<?php

namespace App\Services\Legacy;

use App\Domain\Students\Support\AcademicTerm;
use App\Support\DbfWriteException;
use App\Support\VisualFoxProDbfEditor;
use App\Support\VisualFoxProDbfReader;
use Generator;
use ZipArchive;

/** Applies the validated district snapshot to DBF entries in an exported ZIP. */
final class ItwDbfExportService
{
    public function apply(ZipArchive $zip, string $directory, string $snapshot): array
    {
        $files = $this->files($zip);
        $counts = ['registrations' => 0, 'scores' => 0, 'subjects' => 0];
        $changed = [];
        foreach ([1, 2, 3] as $level) {
            $registrations = array_values(iterator_to_array($this->rows($snapshot, 'registrations', $level)));
            $books = iterator_to_array($this->rows($snapshot, 'scorebooks', $level));
            if ($registrations === [] && $books === []) {
                continue;
            }
            $paths = [];
            foreach (['student', 'grade', 'subject'] as $type) {
                $names = $files[$level][$type] ?? [];
                if (count($names) !== 1) {
                    throw new DbfWriteException('ต้องมี '.strtoupper($type).'.DBF ระดับ '.$level.' เพียงไฟล์เดียว เพื่อจับคู่ข้อมูลอย่างถูกต้อง');
                }
                $paths[$type] = $directory.'/itw-'.$level.'-'.$type.'.dbf';
                $this->copyEntry($zip, $names[0], $paths[$type]);
            }
            $students = [];
            foreach ((new VisualFoxProDbfReader($paths['student']))->records() as $student) {
                $code = substr(trim((string) ($student['id'] ?? '')), -10);
                if ($code !== '') {
                    $students[$code][] = $student;
                }
            }
            $groups = [];
            foreach (array_merge($files[0]['group'] ?? [], $files[$level]['group'] ?? []) as $index => $name) {
                $path = $directory.'/itw-'.$level.'-group-'.$index.'.dbf';
                $this->copyEntry($zip, $name, $path);
                foreach ((new VisualFoxProDbfReader($path))->records() as $group) {
                    $groups[trim((string) ($group['grp_code'] ?? ''))] = trim((string) ($group['grp_name'] ?? ''));
                }
            }
            $grade = new VisualFoxProDbfEditor($paths['grade']);
            $grade->requireFields(['std_code', 'sub_code', 'semestry', 'typ_code', 'midterm', 'final', 'total', 'grade']);
            $index = [];
            foreach ($grade->records() as $rowIndex => $row) {
                $key = $this->key(substr(trim((string) $row['std_code']), -10), (string) $row['semestry'], (string) $row['sub_code']);
                $index[$key][] = ['index' => $rowIndex, 'row' => $row];
            }
            $subject = new VisualFoxProDbfEditor($paths['subject']);
            $subject->requireFields(['sub_code', 'sub_name', 'sub_credit', 'sub_type']);
            $catalog = [];
            $nextSubjectId = 0;
            foreach ($subject->records() as $rowIndex => $row) {
                $catalog[trim((string) $row['sub_code'])][] = ['index' => $rowIndex, 'row' => $row];
                $nextSubjectId = max($nextSubjectId, (int) ($row['sub_id'] ?? 0));
            }
            $subjects = [];
            foreach ($registrations as $registration) {
                foreach (['compulsory_subjects' => 1, 'elective_subjects' => 2] as $column => $type) {
                    foreach ($registration[$column] ?? [] as $selection) {
                        if (empty($selection['registered']) && empty($selection['transferred'])) {
                            continue; // Unselected courses do not erase historical ITW results.
                        }
                        $code = trim($selection['code']);
                        $values = ['sub_code' => $code, 'sub_name' => $selection['name'] ?? '', 'sub_credit' => $selection['credits'] ?? 0, 'sub_type' => $type];
                        if (isset($subjects[$code]) && $subjects[$code] !== $values) {
                            throw new DbfWriteException('ชื่อ/หน่วยกิต/ประเภทของวิชา '.$code.' ไม่ตรงกันในทะเบียนเว็บ');
                        }
                        $subjects[$code] = $values;
                        $key = $this->key($registration['student_code'], $registration['academic_term'], $code);
                        $target = $this->target($grade, $index, $students, $registration['student_code'], $registration['academic_term'], $code);
                        $typ = empty($selection['transferred']) ? 0 : 1;
                        $changes = ['typ_code' => $typ];
                        if ((string) ($target['row']['typ_code'] ?? '') !== (string) $typ) {
                            $changes['grade'] = null;
                        }
                        $this->update($grade, $target['index'], $changes, $registration['student_code'], $code, $registration['academic_term']);
                        $index[$key][0]['row'] = [...$target['row'], ...$changes];
                        $counts['registrations']++;
                    }
                }
            }
            $components = [];
            foreach ($this->rows($snapshot, 'components') as $component) {
                if (isset($books[$component['scorebook_id']])) {
                    $components[$component['scorebook_id']][$component['id']] = $component;
                }
            }
            $scores = [];
            foreach ($this->rows($snapshot, 'entries') as $entry) {
                if (isset($books[$entry['scorebook_id']])) {
                    $scores[$entry['scorebook_id']][$entry['student_code']][$entry['component_id']] = $entry['score'];
                }
            }
            foreach ($this->rows($snapshot, 'notes') as $note) {
                if (isset($books[$note['scorebook_id']])) {
                    $scores[$note['scorebook_id']][$note['student_code']] ??= [];
                }
            }
            $ratios = [];
            $applied = [];
            foreach ($scores as $bookId => $studentScores) {
                $book = $books[$bookId];
                $parts = array_values($components[$bookId] ?? []);
                usort($parts, fn ($a, $b) => $a['position'] <=> $b['position']);
                $coursework = array_values(array_filter($parts, fn ($part) => $part['category'] === 'coursework'));
                $final = array_values(array_filter($parts, fn ($part) => $part['category'] === 'final_exam'));
                if (count($coursework) > 9 || count($coursework) < 1 || count($final) !== 1 || $book['coursework_weight'] === null
                    || abs(array_sum(array_column($coursework, 'max_score')) - (float) $book['coursework_weight']) > 0.001
                    || abs((float) $final[0]['max_score'] - (float) $book['final_exam_weight']) > 0.001) {
                    throw new DbfWriteException('วิชา '.$book['subject_code'].' ต้องมีคะแนนเก็บ 1–9 ช่องและปลายภาค 1 ช่องตามสัดส่วน 60:40, 70:30 หรือ 80:20 ก่อนส่งออก DBF');
                }
                $ratio = [(int) $book['coursework_weight'], (int) $book['final_exam_weight']];
                if (isset($ratios[$book['subject_code']]) && $ratios[$book['subject_code']] !== $ratio) {
                    throw new DbfWriteException('วิชา '.$book['subject_code'].' มีหลายสัดส่วนคะแนน แต่ SUBJECT.DBF เก็บสัดส่วนได้หนึ่งชุดต่อวิชา');
                }
                $ratios[$book['subject_code']] = $ratio;
                foreach ($studentScores as $studentCode => $values) {
                    $studentCode = (string) $studentCode;
                    $student = $this->student($students, $studentCode);
                    $groupCode = trim((string) ($student['grp_code'] ?? ''));
                    if ($book['group_code'] !== '' && ! in_array($book['group_code'], [$groupCode, $groups[$groupCode] ?? null], true)) {
                        throw new DbfWriteException('สมุดคะแนนวิชา '.$book['subject_code'].' ของ '.$studentCode.' ไม่ตรงกับกลุ่มใน STUDENT.DBF');
                    }
                    $changes = [];
                    $sum = 0;
                    $complete = true;
                    for ($slot = 1; $slot <= 9; $slot++) {
                        $component = $coursework[$slot - 1] ?? null;
                        $value = $component === null ? null : ($values[$component['id']] ?? null);
                        $changes['midterm'.$slot] = $value;
                        $sum += $value ?? 0;
                        if ($component !== null && $value === null) {
                            $complete = false;
                        }
                    }
                    $changes['midterm'] = $complete ? $sum : null;
                    $changes['final'] = $values[$final[0]['id']] ?? null;
                    $changes['total'] = $complete && $changes['final'] !== null ? $sum + $changes['final'] : null;
                    $key = $this->key($studentCode, $book['academic_term'], $book['subject_code']);
                    if (isset($applied[$key]) && $applied[$key] !== $changes) {
                        throw new DbfWriteException('พบสมุดคะแนนหลายชุดให้ค่าต่างกันสำหรับ '.$studentCode.' วิชา '.$book['subject_code'].' ภาคเรียน '.$book['academic_term']);
                    }
                    $applied[$key] = $changes;
                    $target = $this->target($grade, $index, $students, $studentCode, $book['academic_term'], $book['subject_code']);
                    if (trim((string) ($target['row']['typ_code'] ?? '')) === '1'
                        || in_array(trim((string) ($target['row']['grade'] ?? '')), ['ม', 'มส', 'ผ', 'มผ', 'ร'], true)) {
                        throw new DbfWriteException('ไม่สามารถเขียนคะแนนทับรายการเทียบโอนหรือเกรดพิเศษของ '.$studentCode.' วิชา '.$book['subject_code']);
                    }
                    $changedScore = false;
                    foreach ($changes as $field => $value) {
                        $old = $target['row'][$field] ?? null;
                        $changedScore = $changedScore || (($old === null) !== ($value === null)) || ($old !== null && $value !== null && (float) $old !== (float) $value);
                    }
                    if ($changedScore) {
                        $changes['grade'] = null; // ITW must recalculate; never retain a stale numeric grade.
                    }
                    $this->update($grade, $target['index'], $changes, $studentCode, $book['subject_code'], $book['academic_term']);
                    $index[$key][0]['row'] = [...$target['row'], ...$changes];
                    $counts['scores']++;
                }
            }
            foreach (array_unique([...array_keys($subjects), ...array_keys($ratios)]) as $code) {
                $existing = $catalog[$code] ?? [];
                if (count($existing) > 1) {
                    throw new DbfWriteException('พบรหัสวิชาซ้ำใน SUBJECT.DBF: '.$code);
                }
                $values = $subjects[$code] ?? [];
                if (isset($ratios[$code])) {
                    if (isset($subject->fields()['midterm'], $subject->fields()['final'])) {
                        $values['midterm'] = $ratios[$code][0];
                        $values['final'] = $ratios[$code][1];
                        if (isset($subject->fields()['defmidfin'])) {
                            $values['defmidfin'] = false;
                        }
                    } else {
                        throw new DbfWriteException('SUBJECT.DBF ไม่มีช่องกำหนดสัดส่วนคะแนนของวิชา '.$code);
                    }
                }
                if ($existing === []) {
                    if (! isset($subjects[$code])) {
                        throw new DbfWriteException('ไม่มีวิชา '.$code.' ใน SUBJECT.DBF หรือทะเบียนเว็บ จึงไม่สามารถสร้างรายละเอียดวิชาได้');
                    }
                    if (trim((string) ($values['sub_name'] ?? '')) === '') {
                        throw new DbfWriteException('กรุณาระบุชื่อวิชา '.$code.' ในทะเบียนเว็บก่อนเพิ่มลง SUBJECT.DBF');
                    }
                    foreach (['defminfin', 'defrmidfin', 'defrminfin'] as $field) {
                        if (isset($subject->fields()[$field])) {
                            $values[$field] = true;
                        }
                    }
                    if (isset($subject->fields()['defmidfin']) && ! isset($ratios[$code])) {
                        $values['defmidfin'] = true;
                    }
                    if (isset($subject->fields()['sub_id'])) {
                        $values['sub_id'] = ++$nextSubjectId;
                    }
                    $subject->append($values);
                } elseif ($values !== []) {
                    if (($values['sub_name'] ?? null) === '') {
                        unset($values['sub_name']);
                    }
                    $subject->update($existing[0]['index'], $values);
                }
                $counts['subjects']++;
            }
            foreach (['grade', 'subject'] as $type) {
                if (! $zip->addFile($paths[$type], $files[$level][$type][0])) {
                    throw new DbfWriteException('เพิ่มไฟล์ DBF ที่แก้ไขกลับเข้า ZIP ไม่สำเร็จ');
                }
                $changed[] = $files[$level][$type][0];
            }
            // Editors flush every write; release file handles before ZIP close on Windows.
            unset($grade, $subject);
        }
        $instructions = "\xEF\xBB\xBFSDL School: ส่งออก DBF สำหรับ ITW\r\n\r\n"
            ."ปิด ITW ทุกเครื่องและสำรองโฟลเดอร์ข้อมูลปลายทางก่อนแทนไฟล์ DBF/FPT/CDX ตามโครงสร้างเดิม\r\n"
            ."หลังแทนไฟล์ต้องสร้างดัชนีใหม่ (REINDEX) ใน ITW/FoxPro แล้วคำนวณเกรดใน ITW อีกครั้ง\r\n"
            ."ระบบเว้น GRADE ที่คะแนนเปลี่ยนไว้ ไม่คำนวณเกรดและไม่ปัดเศษคะแนนเอง\r\n"
            ."คะแนนเก็บเรียงตามช่องบนเว็บลง MIDTERM1–9; MIDTERM เป็นผลรวมเมื่อกรอกครบ; FINAL เป็นคะแนนปลายภาค; TOTAL รวมเมื่อคะแนนครบ\r\n"
            ."เพิ่ม/ปรับเฉพาะวิชาที่เลือกลงทะเบียนหรือเทียบโอน ไม่ลบผลเดิมที่ไม่ได้เลือกโดยอัตโนมัติ\r\n"
            ."หากเคยล้างคะแนนทั้งแถวก่อนติดตั้งรุ่นนี้ ให้เปิดสมุดคะแนนแล้วกดบันทึกอีกครั้งเพื่อให้ระบบจำได้ว่าต้องล้างคะแนน DBF แถวนั้น\r\n\r\n"
            ."1. Close ITW on every computer and back up the destination data folder.\r\n"
            ."2. Replace the DBF/FPT/CDX files using the same directory structure.\r\n"
            ."3. Rebuild indexes (REINDEX) with ITW/FoxPro before using the data. CDX definitions were retained; their index contents require rebuilding.\r\n"
            ."4. Recalculate totals/grades in ITW. Changed scores have a blank GRADE; SDL did not calculate grades.\r\n"
            ."Coursework components follow their web order in MIDTERM1..9. MIDTERM is the sum only when all coursework fields are recorded; FINAL is the final-exam score. No rounding was performed.\r\n"
            ."Only selected registered/transferred subjects are added/updated; unselected subjects do not delete historical ITW results.\r\n"
            ."A student with no web score entries or save marker is left unchanged. Re-save fully cleared rows from older app versions before export. FINAL1/FINAL2 and unrelated fields retain their original values.\r\n"
            .'Changed DBFs: '.implode(', ', $changed)."\r\n";
        $zip->addFromString('SDL_ITW_README.txt', $instructions);

        return $counts;
    }

    private function target(VisualFoxProDbfEditor $grade, array &$index, array $students, string $studentCode, string $term, string $subjectCode): array
    {
        $student = $this->student($students, $studentCode);
        $key = $this->key($studentCode, $term, $subjectCode);
        if (count($index[$key] ?? []) > 1) {
            throw new DbfWriteException('มีหลายระเบียนใน GRADE.DBF สำหรับ '.$studentCode.' วิชา '.$subjectCode.' ภาคเรียน '.$term.' กรุณาตรวจรายการปกติ/ซ่อมก่อนส่งออก');
        }
        if (! isset($index[$key])) {
            $fullCode = trim((string) ($student['std_code'] ?? ''));
            if ($fullCode === '') {
                $fullCode = trim((string) ($student['school'] ?? '')).$studentCode;
            }
            if (substr($fullCode, -10) !== $studentCode
                || strlen($fullCode) !== $grade->fields()['std_code']['length']) {
                throw new DbfWriteException('STD_CODE ของ '.$studentCode.' ไม่ตรงกับรหัสนักศึกษาหรือขนาดช่องใน GRADE.DBF');
            }
            $normalized = AcademicTerm::normalize($term);
            if ($normalized === null) {
                throw new DbfWriteException('รูปแบบภาคเรียนไม่ถูกต้อง: '.$term);
            }
            [$semester, $year] = explode('/', $normalized);
            $row = ['std_code' => $fullCode, 'sub_code' => $subjectCode, 'semestry' => substr($year, -2).'/'.$semester,
                'typ_code' => 0, 'midterm' => null, 'final' => null, 'total' => null, 'grade' => null];
            foreach (['grp_code', 'method', 'learning'] as $field) {
                if (isset($grade->fields()[$field])) {
                    $row[$field] = $student[$field] ?? null;
                }
            }
            $index[$key] = [['index' => $grade->append($row), 'row' => $row]];
        }

        return $index[$key][0];
    }

    private function student(array $students, string $code): array
    {
        if (count($students[$code] ?? []) !== 1) {
            throw new DbfWriteException('ไม่พบรหัสนักศึกษา '.$code.' เพียงรายการเดียวใน STUDENT.DBF ของระดับที่เลือก');
        }

        return $students[$code][0];
    }

    private function update(VisualFoxProDbfEditor $editor, int $index, array $values, string $student, string $subject, string $term): void
    {
        try {
            $editor->update($index, $values);
        } catch (DbfWriteException $exception) {
            throw new DbfWriteException($student.' วิชา '.$subject.' ภาคเรียน '.$term.': '.$exception->getMessage(), 0, $exception);
        }
    }

    private function key(string $student, string $term, string $subject): string
    {
        return $student.'|'.(AcademicTerm::normalize($term) ?? trim($term)).'|'.trim($subject);
    }

    private function rows(string $directory, string $type, ?int $level = null): Generator
    {
        $handle = fopen($directory.'/'.$type.'.jsonl', 'rb');
        try {
            while (($line = fgets($handle)) !== false) {
                $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                if ($level === null || (int) ($row['education_level'] ?? 0) === $level) {
                    yield $row['id'] => $row;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function files(ZipArchive $zip): array
    {
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', $zip->getNameIndex($i));
            if (preg_match('~(?:^|/)([123])/(student|grade|subject|group)\.dbf$~i', $name, $match) === 1) {
                $files[(int) $match[1]][strtolower($match[2])][] = $zip->getNameIndex($i);
            } elseif (preg_match('~(?:^|/)group\.dbf$~i', $name) === 1) {
                $files[0]['group'][] = $zip->getNameIndex($i);
            }
        }

        return $files;
    }

    private function copyEntry(ZipArchive $zip, string $name, string $path): void
    {
        $input = $zip->getStream($name);
        $output = fopen($path, 'xb');
        if ($input === false || $output === false) {
            throw new DbfWriteException('เปิดไฟล์ DBF ใน ZIP ไม่สำเร็จ');
        }
        try {
            $bytes = stream_copy_to_stream($input, $output, 268_435_457);
            if ($bytes === false || $bytes > 268_435_456 || $bytes !== $zip->statName($name)['size']) {
                throw new DbfWriteException('ขนาด DBF ไม่ถูกต้องหรือเกินกำหนด');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }
}
