import { strToU8, zipSync } from 'fflate';

export type ExcelCell = string | number | boolean | null | undefined;

export type ExcelSheet = {
    name: string;
    columns: string[];
    rows: ExcelCell[][];
};

const XML_HEADER = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

function escapeXml(value: string): string {
    return value
        .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&apos;');
}

function columnName(index: number): string {
    let current = index + 1;
    let name = '';
    while (current > 0) {
        current -= 1;
        name = String.fromCharCode(65 + (current % 26)) + name;
        current = Math.floor(current / 26);
    }
    return name;
}

function safeSheetName(value: string, index: number): string {
    const cleaned = value.replace(/[\\/*?:[\]]/g, ' ').trim().slice(0, 31);
    return cleaned || `Sheet ${index + 1}`;
}

function safeFileName(value: string): string {
    const cleaned = value.replace(/[\\/:*?"<>|\u0000-\u001f]/g, '-').trim();
    return `${cleaned || 'export'}-${new Date().toISOString().slice(0, 10)}.xlsx`;
}

function normalizedText(value: unknown): string {
    if (value === null || value === undefined) return '';
    if (Array.isArray(value)) return value.map(normalizedText).join(', ');
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
}

function cellXml(value: ExcelCell, reference: string, style: number): string {
    if (typeof value === 'number' && Number.isFinite(value)) {
        return `<c r="${reference}" s="${style}"><v>${value}</v></c>`;
    }
    if (typeof value === 'boolean') {
        return `<c r="${reference}" s="${style}" t="b"><v>${value ? 1 : 0}</v></c>`;
    }

    // inlineStr makes user-controlled values such as =SUM(...) plain text, never formulas.
    const text = normalizedText(value);
    const preserveSpace = /^\s|\s$/.test(text) ? ' xml:space="preserve"' : '';
    return `<c r="${reference}" s="${style}" t="inlineStr"><is><t${preserveSpace}>${escapeXml(text)}</t></is></c>`;
}

function worksheetXml(sheet: ExcelSheet): string {
    const rowValues = [sheet.columns, ...sheet.rows];
    const columnCount = Math.max(sheet.columns.length, ...sheet.rows.map((row) => row.length), 1);
    const rowCount = Math.max(rowValues.length, 1);
    const widths = Array.from({ length: columnCount }, (_, columnIndex) => {
        const maximum = rowValues.reduce((width, row) => Math.max(width, normalizedText(row[columnIndex]).length), 0);
        return Math.min(Math.max(maximum + 2, 10), 42);
    });
    const columns = widths.map((width, index) => `<col min="${index + 1}" max="${index + 1}" width="${width}" customWidth="1"/>`).join('');
    const rows = rowValues.map((row, rowIndex) => {
        const cells = Array.from({ length: columnCount }, (_, columnIndex) => cellXml(
            row[columnIndex],
            `${columnName(columnIndex)}${rowIndex + 1}`,
            rowIndex === 0 ? 1 : rowIndex % 2 === 0 ? 2 : 0,
        )).join('');
        return `<row r="${rowIndex + 1}"${rowIndex === 0 ? ' ht="24" customHeight="1"' : ''}>${cells}</row>`;
    }).join('');
    const finalCell = `${columnName(columnCount - 1)}${rowCount}`;
    const autoFilter = sheet.columns.length > 0 ? `<autoFilter ref="A1:${columnName(columnCount - 1)}1"/>` : '';

    return `${XML_HEADER}<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:${finalCell}"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="20"/><cols>${columns}</cols><sheetData>${rows}</sheetData>${autoFilter}</worksheet>`;
}

function workbookFiles(sheets: ExcelSheet[]): Record<string, Uint8Array> {
    const normalizedSheets = sheets.map((sheet, index) => ({ ...sheet, name: safeSheetName(sheet.name, index) }));
    const contentOverrides = normalizedSheets.map((_, index) => `<Override PartName="/xl/worksheets/sheet${index + 1}.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>`).join('');
    const workbookSheets = normalizedSheets.map((sheet, index) => `<sheet name="${escapeXml(sheet.name)}" sheetId="${index + 1}" r:id="rId${index + 1}"/>`).join('');
    const workbookRelationships = normalizedSheets.map((_, index) => `<Relationship Id="rId${index + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet${index + 1}.xml"/>`).join('');
    const files: Record<string, Uint8Array> = {
        '[Content_Types].xml': strToU8(`${XML_HEADER}<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>${contentOverrides}</Types>`),
        '_rels/.rels': strToU8(`${XML_HEADER}<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>`),
        'xl/workbook.xml': strToU8(`${XML_HEADER}<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets>${workbookSheets}</sheets></workbook>`),
        'xl/_rels/workbook.xml.rels': strToU8(`${XML_HEADER}<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">${workbookRelationships}<Relationship Id="rId${normalizedSheets.length + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>`),
        'xl/styles.xml': strToU8(`${XML_HEADER}<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Noto Sans Thai"/><family val="2"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Noto Sans Thai"/><family val="2"/></font></fonts><fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF166534"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF0FDF4"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFill="1" applyFont="1" applyAlignment="1"><alignment vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="3" borderId="0" xfId="0" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>`),
        'docProps/core.xml': strToU8(`${XML_HEADER}<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>Sena Care School</dc:creator><cp:lastModifiedBy>Sena Care School</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">${new Date().toISOString()}</dcterms:created></cp:coreProperties>`),
        'docProps/app.xml': strToU8(`${XML_HEADER}<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Sena Care School</Application></Properties>`),
    };
    normalizedSheets.forEach((sheet, index) => {
        files[`xl/worksheets/sheet${index + 1}.xml`] = strToU8(worksheetXml(sheet));
    });
    return files;
}

export function downloadExcel(fileName: string, sheets: ExcelSheet[]): void {
    const usableSheets = sheets.filter((sheet) => sheet.columns.length > 0);
    if (usableSheets.length === 0) throw new Error('ไม่มีข้อมูลสำหรับส่งออก');
    const bytes = zipSync(workbookFiles(usableSheets), { level: 6 });
    const blob = new Blob([new Uint8Array(bytes).buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = safeFileName(fileName);
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
}

export function excelValue(value: unknown): ExcelCell {
    if (value === null || value === undefined || typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') return value;
    return normalizedText(value);
}
