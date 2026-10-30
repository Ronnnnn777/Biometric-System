<?php

function studentImportHeader(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
    return (string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($value)));
}

function studentImportHeaderMap(array $headers): array
{
    $aliases = [
        'student_id' => ['student_id', 'studentid', 'id', 'lrn'],
        'first_name' => ['first_name', 'firstname', 'first'],
        'middle_name' => ['middle_name', 'middlename', 'middle', 'middle_initial'],
        'last_name' => ['last_name', 'lastname', 'last', 'surname'],
        'student_name' => ['student_name', 'studentname', 'name', 'full_name', 'fullname'],
        'grade' => ['grade', 'grade_level', 'gradelevel'],
        'age' => ['age'],
        'strand' => ['strand'],
        'address' => ['address', 'home_address', 'homeaddress'],
        'section' => ['section', 'student_section', 'studentsection'],
        'enrollment_status' => ['enrollment_status', 'enrollmentstatus', 'status', 'student_status', 'studentstatus'],
    ];
    $map = [];
    foreach ($headers as $column => $header) {
        $normalized = studentImportHeader((string) $header);
        foreach ($aliases as $field => $names) {
            if (in_array($normalized, $names, true)) $map[$field] = $column;
        }
    }
    return $map;
}

function studentImportCell(array $row, array $map, string $field): string
{
    $column = $map[$field] ?? null;
    return $column === null ? '' : trim((string) ($row[$column] ?? ''));
}

function studentImportXlsxColumn(string $reference): int
{
    preg_match('/^[A-Z]+/i', $reference, $matches);
    $column = strtoupper($matches[0] ?? 'A');
    $index = 0;
    foreach (str_split($column) as $letter) $index = $index * 26 + (ord($letter) - 64);
    return max(0, $index - 1);
}

function studentImportReadCsv(string $path): array
{
    $handle = fopen($path, 'rb');
    if (!$handle) throw new RuntimeException('The CSV file could not be opened.');
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) === 1 && trim((string) $row[0]) === '') continue;
        $rows[] = array_map(static fn ($value) => trim((string) $value), $row);
    }
    fclose($handle);
    return $rows;
}

function studentImportReadXlsx(string $path): array
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('Excel import is unavailable because the PHP ZIP extension is not enabled. Upload a CSV file or enable the ZIP extension.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('The Excel file could not be opened. Save it as a standard .xlsx workbook and try again.');

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $shared = simplexml_load_string($sharedXml);
        $ns = $shared?->getNamespaces(true)[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        if ($shared !== false) {
            $shared->registerXPathNamespace('sheet', $ns);
            foreach ($shared->xpath('//sheet:si') ?: [] as $item) {
                $item->registerXPathNamespace('sheet', $ns);
                $sharedStrings[] = implode('', array_map('strval', $item->xpath('.//sheet:t') ?: []));
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) throw new RuntimeException('The Excel workbook does not contain a first worksheet.');
    $sheet = simplexml_load_string($sheetXml);
    if ($sheet === false) throw new RuntimeException('The Excel worksheet could not be read.');
    $ns = $sheet->getNamespaces(true)[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $sheet->registerXPathNamespace('sheet', $ns);

    $rows = [];
    foreach ($sheet->xpath('//sheet:sheetData/sheet:row') ?: [] as $xmlRow) {
        $row = [];
        $xmlRow->registerXPathNamespace('sheet', $ns);
        foreach ($xmlRow->xpath('./sheet:c') ?: [] as $cell) {
            $attributes = $cell->attributes();
            $column = studentImportXlsxColumn((string) ($attributes['r'] ?? 'A1'));
            $type = (string) ($attributes['t'] ?? '');
            $value = '';
            if ($type === 'inlineStr') {
                $cell->registerXPathNamespace('sheet', $ns);
                $value = implode('', array_map('strval', $cell->xpath('.//sheet:t') ?: []));
            } else {
                $value = (string) ($cell->children($ns)->v ?? '');
                if ($type === 's') $value = $sharedStrings[(int) $value] ?? '';
            }
            $row[$column] = trim($value);
        }
        if ($row) {
            ksort($row);
            $rows[] = $row;
        }
    }
    return $rows;
}

function studentImportReadRows(string $path, string $extension): array
{
    return $extension === 'csv' ? studentImportReadCsv($path) : studentImportReadXlsx($path);
}

function studentImportReadXlsxSheet(string $path, int $sheetNumber): array
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('Excel import is unavailable because the PHP ZIP extension is not enabled.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('The Excel file could not be opened. Save it as a standard .xlsx workbook and try again.');

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $shared = simplexml_load_string($sharedXml);
        $ns = $shared?->getNamespaces(true)[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        if ($shared !== false) {
            $shared->registerXPathNamespace('sheet', $ns);
            foreach ($shared->xpath('//sheet:si') ?: [] as $item) {
                $item->registerXPathNamespace('sheet', $ns);
                $sharedStrings[] = implode('', array_map('strval', $item->xpath('.//sheet:t') ?: []));
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet' . $sheetNumber . '.xml');
    $zip->close();
    if ($sheetXml === false) return [];
    $sheet = simplexml_load_string($sheetXml);
    if ($sheet === false) return [];
    $ns = $sheet->getNamespaces(true)[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $sheet->registerXPathNamespace('sheet', $ns);

    $rows = [];
    foreach ($sheet->xpath('//sheet:sheetData/sheet:row') ?: [] as $xmlRow) {
        $row = [];
        $xmlRow->registerXPathNamespace('sheet', $ns);
        foreach ($xmlRow->xpath('./sheet:c') ?: [] as $cell) {
            $attributes = $cell->attributes();
            $column = studentImportXlsxColumn((string) ($attributes['r'] ?? 'A1'));
            $type = (string) ($attributes['t'] ?? '');
            $value = '';
            if ($type === 'inlineStr') {
                $cell->registerXPathNamespace('sheet', $ns);
                $value = implode('', array_map('strval', $cell->xpath('.//sheet:t') ?: []));
            } else {
                $value = (string) ($cell->children($ns)->v ?? '');
                if ($type === 's') $value = $sharedStrings[(int) $value] ?? '';
            }
            $row[$column] = trim($value);
        }
        if ($row) {
            ksort($row);
            $rows[] = $row;
        }
    }
    return $rows;
}

function studentImportSchoolFormMeta(array $rows, string $label): string
{
    foreach ($rows as $row) {
        foreach ($row as $column => $value) {
            if (studentImportHeader((string) $value) !== studentImportHeader($label)) continue;
            foreach ($row as $nextColumn => $nextValue) {
                if ($nextColumn <= $column) continue;
                $nextValue = trim((string) $nextValue);
                if ($nextValue !== '' && $nextValue !== ':') return $nextValue;
            }
        }
    }
    return '';
}

function studentImportSchoolFormAge(string $birthdate): int
{
    $birthdate = trim($birthdate);
    if ($birthdate === '') return 0;
    try {
        if (is_numeric($birthdate)) {
            $date = new DateTime('1899-12-30');
            $date->modify('+' . (int) $birthdate . ' days');
        } else {
            $date = new DateTime($birthdate);
        }
        return $date->diff(new DateTime('today'))->y;
    } catch (Throwable) {
        return 0;
    }
}

function studentImportSchoolFormName(string $name): array
{
    $parts = array_values(array_filter(array_map('trim', explode(',', $name)), static fn ($part) => $part !== ''));
    if (count($parts) < 2) return ['', '', ''];
    return [$parts[1], implode(' ', array_slice($parts, 2)), $parts[0]];
}

/** Read the school-form-style teacher template used by this system. */
function studentImportReadTeacherFormRows(string $path): ?array
{
    for ($sheetNumber = 1; $sheetNumber <= 20; $sheetNumber++) {
        $rows = studentImportReadXlsxSheet($path, $sheetNumber);
        if (!$rows) continue;

        $headerColumns = null;
        $headerRow = null;
        foreach ($rows as $index => $row) {
            $columns = [];
            foreach ($row as $column => $value) {
                $columns[studentImportHeader((string) $value)] = $column;
            }
            if (
                isset($columns['lrn'], $columns['name'], $columns['birthdate'], $columns['address'], $columns['strand'])
            ) {
                $headerColumns = $columns;
                $headerRow = $index;
                break;
            }
        }
        if ($headerColumns === null || $headerRow === null) continue;

        $grade = (int) (studentImportSchoolFormMeta($rows, 'GRADE') ?: studentImportSchoolFormMeta($rows, 'GRADE LEVEL'));
        $section = ucwords(strtolower(studentImportSchoolFormMeta($rows, 'SECTION')));
        if ($grade < 1 || $section === '') continue;

        $importRows = [["LRN", "First Name", "Middle Name", "Last Name", "Grade", "Age", "Strand", "Address", "Section", "Status"]];
        $emptyRows = 0;
        for ($index = $headerRow + 1; $index < count($rows); $index++) {
            $row = $rows[$index];
            $studentId = trim((string) ($row[$headerColumns['lrn']] ?? ''));
            $name = trim((string) ($row[$headerColumns['name']] ?? ''));
            $birthdate = trim((string) ($row[$headerColumns['birthdate']] ?? ''));
            $address = trim((string) ($row[$headerColumns['address']] ?? ''));
            $strand = trim((string) ($row[$headerColumns['strand']] ?? ''));
            if ($studentId === '' && $name === '' && $birthdate === '' && $address === '' && $strand === '') {
                $emptyRows++;
                if ($emptyRows >= 10) break;
                continue;
            }
            $emptyRows = 0;
            [$firstName, $middleName, $lastName] = studentImportSchoolFormName($name);
            if ($firstName === '' || $lastName === '') {
                $parts = preg_split('/\s+/', $name, 2);
                $firstName = trim((string) ($parts[0] ?? ''));
                $lastName = trim((string) ($parts[1] ?? $firstName));
                $middleName = '';
            }
            $importRows[] = [
                $studentId,
                $firstName,
                $middleName,
                $lastName,
                $grade,
                studentImportSchoolFormAge($birthdate),
                $strand,
                $address,
                $section,
                'Enrolled',
            ];
        }
        if (count($importRows) > 1) return $importRows;
    }
    return null;
}

function studentImportReadSchoolFormRows(string $path): ?array
{
    $teacherFormRows = studentImportReadTeacherFormRows($path);
    if ($teacherFormRows !== null) return $teacherFormRows;
    for ($sheetNumber = 1; $sheetNumber <= 20; $sheetNumber++) {
        $rows = studentImportReadXlsxSheet($path, $sheetNumber);
        if (!$rows) continue;
        $headerRow = null;
        $lrnColumns = [];
        foreach ($rows as $index => $row) {
            $columns = [];
            foreach ($row as $column => $value) if (studentImportHeader((string) $value) === 'lrn') $columns[] = $column;
            if (count($columns) >= 2) {
                $headerRow = $index;
                $lrnColumns = $columns;
                break;
            }
        }
        if ($headerRow === null) continue;

        $grade = (int) studentImportSchoolFormMeta($rows, 'GRADE LEVEL');
        $section = ucwords(strtolower(studentImportSchoolFormMeta($rows, 'SECTION')));
        if ($grade < 1 || $section === '') continue;

        $importRows = [["LRN", "First Name", "Middle Name", "Last Name", "Grade", "Age", "Strand", "Address", "Section", "Status"]];
        $emptyRows = 0;
        for ($index = $headerRow + 1; $index < count($rows); $index++) {
            $row = $rows[$index];
            $foundLearner = false;
            foreach ($lrnColumns as $lrnColumn) {
                $studentId = preg_replace('/\D+/', '', (string) ($row[$lrnColumn] ?? ''));
                $name = trim((string) ($row[$lrnColumn - 1] ?? ''));
                if ($studentId === '' && $name === '') continue;
                $foundLearner = true;
                if ($studentId === '' || $name === '') continue;
                [$firstName, $middleName, $lastName] = studentImportSchoolFormName($name);
                $age = studentImportSchoolFormAge((string) ($row[$lrnColumn + 1] ?? ''));
                $importRows[] = [$studentId, $firstName, $middleName, $lastName, $grade, $age, '', 'Not provided', $section, 'Enrolled'];
            }
            $emptyRows = $foundLearner ? 0 : $emptyRows + 1;
            if ($emptyRows >= 10) break;
        }
        if (count($importRows) > 1) return $importRows;
    }
    return null;
}

function studentImportSchoolFormAdviser(string $path): string
{
    for ($sheetNumber = 1; $sheetNumber <= 20; $sheetNumber++) {
        $rows = studentImportReadXlsxSheet($path, $sheetNumber);
        if (!$rows) continue;
        $lrnHeaders = 0;
        $teacherHeaders = [];
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $header = studentImportHeader((string) $value);
                if ($header === 'lrn') $lrnHeaders++;
                $teacherHeaders[$header] = true;
            }
        }
        if (
            $lrnHeaders >= 2 ||
            ($lrnHeaders >= 1 && isset($teacherHeaders['name'], $teacherHeaders['birthdate'], $teacherHeaders['address'], $teacherHeaders['strand']))
        ) return studentImportSchoolFormMeta($rows, 'ADVISER');
    }
    return '';
}

function studentImportPersonNameKey(string $name): string
{
    $tokens = preg_split('/\s+/', preg_replace('/[^a-z]+/i', ' ', strtoupper($name)) ?: '') ?: [];
    $tokens = array_values(array_filter($tokens, static fn ($token) => strlen($token) > 1));
    return implode(' ', $tokens);
}
