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
        if ($shared) {
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
    if (!$sheet) throw new RuntimeException('The Excel worksheet could not be read.');
    $ns = $sheet->getNamespaces(true)[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $sheet->registerXPathNamespace('sheet', $ns);

    $rows = [];
    foreach ($sheet->xpath('//sheet:sheetData/sheet:row') ?: [] as $xmlRow) {
        $row = [];
        foreach ($xmlRow->children($ns)->c as $cell) {
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
