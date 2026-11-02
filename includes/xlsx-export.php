<?php
function xlsxXml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, "UTF-8");
}
function xlsxColumn(int $index): string
{
    $result = "";
    while ($index > 0) {
        $index--;
        $result = chr(65 + ($index % 26)) . $result;
        $index = intdiv($index, 26);
    }
    return $result;
}
function xlsxZip(array $entries): string
{
    $body = "";
    $central = "";
    $offset = 0;
    $count = 0;
    foreach ($entries as $name => $contents) {
        $crc = crc32($contents);
        $size = strlen($contents);
        $nameLength = strlen($name);
        $local =
            pack(
                "VvvvvvVVVvv",
                0x04034b50,
                20,
                0,
                0,
                0,
                0,
                $crc,
                $size,
                $size,
                $nameLength,
                0,
            ) .
            $name .
            $contents;
        $body .= $local;
        $central .=
            pack(
                "VvvvvvvVVVvvvvvVV",
                0x02014b50,
                20,
                20,
                0,
                0,
                0,
                0,
                $crc,
                $size,
                $size,
                $nameLength,
                0,
                0,
                0,
                0,
                0,
                $offset,
            ) . $name;
        $offset += strlen($local);
        $count++;
    }
    return $body .
        $central .
        pack(
            "VvvvvVVv",
            0x06054b50,
            0,
            0,
            $count,
            $count,
            strlen($central),
            $offset,
            0,
        );
}
function xlsxWorksheetName(string $name, array &$used): string
{
    $name = trim((string) preg_replace('/[\\[\\]:*?\\\\\/]/u', ' ', $name));
    if ($name === '') $name = 'Attendance';
    $truncate = static fn(string $value, int $length): string => function_exists('mb_substr')
        ? mb_substr($value, 0, $length, 'UTF-8')
        : substr($value, 0, $length);
    $base = $truncate($name, 31);
    $candidate = $base;
    $suffix = 2;
    $lower = static fn(string $value): string => function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
    while (isset($used[$lower($candidate)])) {
        $tag = ' (' . $suffix++ . ')';
        $candidate = $truncate($base, 31 - strlen($tag)) . $tag;
    }
    $used[$lower($candidate)] = true;
    return $candidate;
}

function xlsxInlineCell(string $reference, string $value, ?int $style = null): string
{
    return '<c r="' . $reference . '"' . ($style === null ? '' : ' s="' . $style . '"')
        . ' t="inlineStr"><is><t xml:space="preserve">' . xlsxXml($value) . '</t></is></c>';
}

/** @param array{name:string,title?:string,subtitle?:string,headers:array,rows:array} $sheet */
function xlsxWorksheetXml(array $sheet): string
{
    $headers = array_values($sheet['headers']);
    $rows = $sheet['rows'];
    $title = trim((string) ($sheet['title'] ?? ''));
    $subtitle = trim((string) ($sheet['subtitle'] ?? ''));
    $hasBanner = $title !== '';
    $headerRow = $hasBanner ? 4 : 1;
    $firstDataRow = $headerRow + 1;
    $lastColumn = xlsxColumn(max(1, count($headers)));
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<cols><col min="1" max="' . max(1, count($headers)) . '" width="18" customWidth="1"/></cols><sheetData>';

    if ($hasBanner) {
        $xml .= '<row r="1" ht="24" customHeight="1">' . xlsxInlineCell('A1', $title, 1) . '</row>';
        $xml .= '<row r="2">' . xlsxInlineCell('A2', $subtitle, 2) . '</row><row r="3"/>';
    }

    $xml .= '<row r="' . $headerRow . '">';
    foreach ($headers as $index => $header) {
        $xml .= xlsxInlineCell(xlsxColumn($index + 1) . $headerRow, (string) $header, $hasBanner ? 3 : null);
    }
    $xml .= '</row>';
    foreach ($rows as $rowIndex => $row) {
        $rowNumber = $firstDataRow + $rowIndex;
        $xml .= '<row r="' . $rowNumber . '">';
        foreach (array_values($row) as $cellIndex => $value) {
            $xml .= xlsxInlineCell(xlsxColumn($cellIndex + 1) . $rowNumber, (string) $value);
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData>';
    if ($hasBanner) $xml .= '<mergeCells count="1"><mergeCell ref="A1:' . $lastColumn . '1"/></mergeCells>';
    return $xml . '</worksheet>';
}

function xlsxWorkbook(array $sheets): string
{
    if (!$sheets) $sheets = [['name' => 'Attendance', 'headers' => [], 'rows' => []]];
    $usedNames = [];
    $overrides = '';
    $workbookSheets = '';
    $relationships = '';
    $entries = [];
    foreach (array_values($sheets) as $index => $sheet) {
        $number = $index + 1;
        $name = xlsxWorksheetName((string) ($sheet['name'] ?? 'Attendance'), $usedNames);
        $sheet['name'] = $name;
        $entries['xl/worksheets/sheet' . $number . '.xml'] = xlsxWorksheetXml($sheet);
        $overrides .= '<Override PartName="/xl/worksheets/sheet' . $number . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml" />';
        $workbookSheets .= '<sheet name="' . xlsxXml($name) . '" sheetId="' . $number . '" r:id="rId' . $number . '" />';
        $relationships .= '<Relationship Id="rId' . $number . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $number . '.xml" />';
    }
    $styleRelationshipId = count($sheets) + 1;
    $relationships .= '<Relationship Id="rId' . $styleRelationshipId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml" />';
    $entries = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $overrides . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $workbookSheets . '</sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relationships . '</Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1D4ED8"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="center"/></xf><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
    ] + $entries;
    return xlsxZip($entries);
}

function downloadAttendanceXlsx(string $filename, array $headers, array $rows): void
{
    downloadAttendanceXlsxSheets($filename, [[
        'name' => 'Attendance', 'headers' => $headers, 'rows' => $rows,
    ]]);
}

/** @param array<int,array{name:string,title:string,subtitle:string,headers:array,rows:array}> $sheets */
function downloadAttendanceXlsxSheets(string $filename, array $sheets): void
{
    $workbook = xlsxWorkbook($sheets);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($workbook));
    echo $workbook;
}
