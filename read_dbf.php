<?php
require __DIR__.'/includes/DbfReader.php';

$studentIds = ['6722000170', '6712000605', '6722000385', '6823000608', '6723000212'];
$files = glob(__DIR__.'/uploads/extracted/*/*/*/STUDENT.DBF');
$files = array_merge($files, glob(__DIR__.'/uploads/extracted/*/*/*/student.dbf'));

foreach ($files as $dbfFile) {
    echo "Reading from: $dbfFile\n";
    try {
        $reader = new DbfReader($dbfFile);
        $headers = $reader->getHeaders();
        
        while ($record = $reader->nextRecord()) {
            $id = trim($record['ID'] ?? '');
            if (in_array($id, $studentIds)) {
                echo "Found $id:\n";
                echo "NAME: " . ($record['NAME'] ?? '') . " " . ($record['SURNAME'] ?? '') . "\n";
                foreach (['NT_SARA1', 'NT_SARA2', 'NT_SEM', 'NT_NOSEM', 'NNET', 'N_NET', 'EEXAM', 'E_EXAM', 'NNET_STAT', 'EXM_STATUS', 'NT_RESULT', 'NT_RES', 'NNET_PASS', 'NNET_RESULT', 'EEXAM_STATUS', 'E_EXAM_STAT'] as $col) {
                    if (isset($record[$col])) {
                        echo "  $col: [" . $record[$col] . "]\n";
                    }
                }
            }
        }
    } catch (\Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
