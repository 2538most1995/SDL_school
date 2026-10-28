import test from 'node:test';
import assert from 'node:assert/strict';
import * as XLSX from 'xlsx';
import { strFromU8, strToU8, unzipSync, zipSync } from 'fflate';
import { createExcelFileBytes } from '../../resources/js/lib/excel.ts';
import { createQuizJsonTemplate, createQuizExcelTemplate, parseQuizJson, parseQuizWorkbook } from '../../resources/js/features/learning/classroom/quizImport.ts';

const sample = () => JSON.parse(createQuizJsonTemplate());
const headers = ['id', 'type', 'prompt', 'points', ...Array.from({length: 10}, (_, i) => `option_${i+1}`), 'answer'];
const row = (overrides = {}) => {
    const values = { id: 'q1', type: 'single_choice', prompt: 'คำถามภาษาไทย', points: 2, option_1: 'ก', option_2: 'ข', answer: '1', ...overrides };
    return headers.map(header => values[header] ?? '');
};
const book = (rows = [row()], columns = headers) => createExcelFileBytes([{name:'Questions',columns,rows}]);

test('quiz JSON template roundtrips five types, optional title and UTF-8 BOM', () => {
    const source = sample();
    const parsed = parseQuizJson(`\uFEFF${JSON.stringify(source)}`);
    assert.equal(parsed.title, source.title);
    assert.deepEqual(parsed.questions, source.questions);
    assert.deepEqual(parsed.questions.map(q => q.type), ['single_choice','multiple_choice','true_false','short_answer','essay']);
    assert.ok(parsed.questions.every(q => q.image_id === null));
});

test('quiz Excel template has editable Questions and Thai Guide and roundtrips all five types', () => {
    const bytes = createQuizExcelTemplate();
    const wb = XLSX.read(bytes, {type:'array'});
    assert.deepEqual(wb.SheetNames, ['Questions','Guide']);
    assert.equal(wb.Sheets.Questions.D2.t, 'n');
    assert.match(wb.Sheets.Guide.B11.v, /อัปโหลดภาพ/);
    assert.deepEqual(parseQuizWorkbook(bytes).questions, sample().questions);
    assert.equal(parseQuizWorkbook(bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength)).questions.length, 5);
});

test('JSON rejects malformed versions, unknown fields and oversized files', () => {
    assert.throws(() => parseQuizJson('{broken'), /JSON/);
    assert.throws(() => parseQuizJson(JSON.stringify({...sample(),version:2})), /version/);
    assert.throws(() => parseQuizJson(JSON.stringify({...sample(),district_id:999})), /รูปแบบ/);
    assert.throws(() => parseQuizJson(' '.repeat(5*1024*1024+1)), /5 MB/);
    assert.throws(() => parseQuizJson(JSON.stringify({...sample(),title:' '})), /ชื่อ/);
});

test('JSON rejects invalid types, duplicate or reserved IDs, incomplete questions and external image references', () => {
    const invalid = [
        {type:'unknown'}, {id:'__proto__'}, {id:'constructor'}, {id:'not allowed'}, {prompt:' '}, {points:0}, {points:1.001}, {points:1001},
        {answer:'ไม่มีในตัวเลือก'}, {options:['ก','ก'],answer:'ก'}, {options:['เดียว'],answer:'เดียว'}, {image_id:20}, {image_url:'https://example.test/prompt.png'},
    ];
    for (const change of invalid) {
        const source=sample(); source.questions[0]={...source.questions[0],...change};
        assert.throws(() => parseQuizJson(JSON.stringify(source)), /ข้อ 1:/);
    }
    const duplicate=sample(); duplicate.questions[1].id=duplicate.questions[0].id;
    assert.throws(() => parseQuizJson(JSON.stringify(duplicate)), /id ซ้ำ/);
    assert.throws(() => parseQuizJson(JSON.stringify({version:1,questions:[]})), /1–100/);
    assert.throws(() => parseQuizJson(JSON.stringify({version:1,questions:Array.from({length:101}, (_,i) => ({...sample().questions[0],id:`q${i}`}))})), /1–100/);
});

test('JSON validates each answer type and avoids partially accepting a corrupt later row', () => {
    for (const [index, answer] of [[1,['ต้นไม้','ต้นไม้']], [1,['ไม่มี']], [2,'จริง'], [3,' '], [4,'ไม่ควรมีเฉลย']]) {
        const source=sample(); source.questions[index].answer=answer;
        assert.throws(() => parseQuizJson(JSON.stringify(source)), new RegExp(`ข้อ ${index+1}:`));
    }
    const source=sample(); source.questions[4].prompt='';
    assert.throws(() => parseQuizJson(JSON.stringify(source)), /ข้อ 5:/);
});

test('Excel accepts option positions and typed true/false, retains zero-like short answer text and skips only empty rows', () => {
    const rows = [row({type:'multiple_choice',option_3:'ค',answer:'3,1'}), [], row({id:'q2',type:'true_false',option_1:'',option_2:'',answer:false}),row({id:'q3',type:'short_answer',option_1:'',option_2:'',answer:'0'})];
    const parsed=parseQuizWorkbook(book(rows));
    assert.deepEqual(parsed.questions[0].answer,['ค','ก']);
    assert.equal(parsed.questions[1].answer,'false');
    assert.equal(parsed.questions[2].answer,'0');
});

test('Excel rejects missing or unknown headers, noncontiguous choices, invalid answer positions and duplicate IDs', () => {
    assert.throws(() => parseQuizWorkbook(book([row()], headers.map(h => h==='answer'?'เฉลย':h))), /หัวตาราง/);
    assert.throws(() => parseQuizWorkbook(book([row().slice(0,-1)],headers.slice(0,-1))), /หัวตาราง/);
    for (const overrides of [{option_2:'',option_3:'ค'}, {answer:'9'}, {answer:'1,2'}, {points:'wrong'}, {prompt:''}]) {
        assert.throws(() => parseQuizWorkbook(book([row(overrides)])), /แถว Excel 2/);
    }
    assert.throws(() => parseQuizWorkbook(book([row(),row()])), /แถว Excel 3.*id ซ้ำ/);
    assert.throws(() => parseQuizWorkbook(createExcelFileBytes([{name:'Wrong',columns:headers,rows:[row()]}])), /Questions/);
});

test('Excel rejects formula cells without evaluating them, including formulas outside Questions', () => {
    const wb=XLSX.utils.book_new();
    const ws=XLSX.utils.aoa_to_sheet([headers,row()]);
    ws.D2={t:'n',f:'SUM(1,1)',v:2};
    XLSX.utils.book_append_sheet(wb,ws,'Questions');
    assert.throws(() => parseQuizWorkbook(XLSX.write(wb,{type:'array',bookType:'xlsx'})), /สูตร/);
    ws.D2={t:'n',v:2};
    const guide=XLSX.utils.aoa_to_sheet([['Guide']]);guide.A1={t:'n',f:'1+1',v:2};
    XLSX.utils.book_append_sheet(wb,guide,'Guide');
    assert.throws(() => parseQuizWorkbook(XLSX.write(wb,{type:'array',bookType:'xlsx'})), /สูตร/);
});

test('Excel parser rejects old-format, oversized, bomb-like, unsafe-path and macro workbooks', () => {
    assert.throws(() => parseQuizWorkbook(new Uint8Array([0xD0,0xCF,0x11,0xE0])), /xlsx/);
    assert.throws(() => parseQuizWorkbook(new Uint8Array(5*1024*1024+1)), /5 MB/);
    const files=unzipSync(book());
    assert.throws(() => parseQuizWorkbook(zipSync({...files,'xl/huge.xml':strToU8('x'.repeat(1024*1024))},{level:9})), /ขยายตัว/);
    assert.throws(() => parseQuizWorkbook(zipSync({...files,'../unsafe.xml':strToU8('x')})), /ปลอดภัย/);
    assert.throws(() => parseQuizWorkbook(zipSync({...files,'xl/vbaProject.bin':new Uint8Array([1])})), /แมโคร/);
    const many={...files};for(let i=0;i<201;i++)many[`docProps/extra${i}.xml`]=strToU8('x');
    assert.throws(() => parseQuizWorkbook(zipSync(many)), /ขยายตัว/);
});

test('Excel row limits fail before reading a huge used range and no visible row is silently discarded', () => {
    assert.throws(() => parseQuizWorkbook(book(Array.from({length:101}, (_,i) => row({id:`q${i}`})))), /100/);
    assert.throws(() => parseQuizWorkbook(book([row(),[],row({id:'q2',type:'essay',option_1:'',option_2:'',answer:'',prompt:''})])), /แถว Excel 4/);
    const files=unzipSync(book());files['xl/worksheets/sheet1.xml']=strToU8(strFromU8(files['xl/worksheets/sheet1.xml']).replace('ref="A1:O2"','ref="A1:O1048576"'));
    assert.throws(() => parseQuizWorkbook(zipSync(files)), /100/);
});

test('Excel counts actual streamed output when local and central original sizes are forged', () => {
    const files=unzipSync(createQuizExcelTemplate());
    files['docProps/extra.xml']=strToU8('x'.repeat(12*1024*1024));
    const bytes=zipSync(files,{level:9});
    const view=new DataView(bytes.buffer,bytes.byteOffset,bytes.byteLength);
    let patched=false;
    for(let offset=0;offset+46<=bytes.length;offset++) {
        if(view.getUint32(offset,true)!==0x02014b50)continue;
        const nameLength=view.getUint16(offset+28,true);
        const name=strFromU8(bytes.subarray(offset+46,offset+46+nameLength));
        if(name!=='docProps/extra.xml')continue;
        const localOffset=view.getUint32(offset+42,true);
        view.setUint32(offset+24,4096,true);
        view.setUint32(localOffset+22,4096,true);
        patched=true;
        break;
    }
    assert.equal(patched,true);
    assert.ok(bytes.length<20000);
    assert.throws(() => parseQuizWorkbook(bytes), /ขยายตัวจริงเกินขนาด/);
});
