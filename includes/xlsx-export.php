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
function downloadAttendanceXlsx(
    string $filename,
    array $headers,
    array $rows,
): void {
    $xml =
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet
    xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheetData>';
        $allRows = array_merge([$headers], $rows);
        foreach ($allRows as $rowIndex => $row) {
        $rowNumber = $rowIndex + 1;
        $xml .= '<row r="' . $rowNumber . '">';
            foreach (array_values($row) as $cellIndex => $value) {
            $reference = xlsxColumn($cellIndex + 1) . $rowNumber;
            $xml .=
            '<c r="' .
                $reference .
                '" t="inlineStr">
                <is>
                    <t>' .
                        xlsxXml((string) $value) .
                        "</t>
                </is>
            </c>";
            }
            $xml .= "</row>";
        }
        $xml .= "</sheetData>
</worksheet>";
$entries = [
"[Content_Types].xml" =>
'
<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml" />
    <Default Extension="xml" ContentType="application/xml" />
    <Override PartName="/xl/workbook.xml"
        ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml" />
    <Override PartName="/xl/worksheets/sheet1.xml"
        ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml" />
</Types>',
"_rels/.rels" =>
'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"
        Target="xl/workbook.xml" />
</Relationships>',
"xl/workbook.xml" =>
'
<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
    xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="Attendance" sheetId="1" r:id="rId1" />
    </sheets>
</workbook>',
"xl/_rels/workbook.xml.rels" =>
'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"
        Target="worksheets/sheet1.xml" />
</Relationships>',
"xl/worksheets/sheet1.xml" => $xml,
];
header(
"Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header("Content-Length: " . strlen(xlsxZip($entries)));
echo xlsxZip($entries);
}