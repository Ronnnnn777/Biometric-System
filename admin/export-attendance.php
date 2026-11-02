<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/attendance-schema.php";
ensureAttendanceTimeOutColumn($conn);
require "../includes/xlsx-export.php";

$date = $_GET["date"] ?? date("Y-m-d");
$dateFrom = $_GET["date_from"] ?? $date;
$dateTo = $_GET["date_to"] ?? $date;
$grade = $_GET["grade"] ?? "all";
$section = trim((string) ($_GET["section"] ?? "all"));
$studentId = trim($_GET["student_id"] ?? ($_GET["student"] ?? ""));
foreach ([$date, $dateFrom, $dateTo] as $value) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        http_response_code(400);
        exit("Invalid date.");
    }
}
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}
if (!in_array((string) $grade, ["all", "7", "8", "9", "10", "11", "12"], true)) {
    $grade = "all";
}

$recordedSection = "COALESCE(NULLIF(a.SECTION_SNAPSHOT,''), s.SECTION)";
$recordedGrade = "COALESCE(a.GRADE_SNAPSHOT, s.GRADE)";
$attendanceHeaders = ["LRN", "Student Name", "Grade", "Section", "Date", "Time In", "Time Out", "Status", "Reason / Comment"];

/** Build one titled worksheet per recorded section, preserving snapshot placement. */
function attendanceSectionSheets(array $headers, array $rows, string $dateLabel): array
{
    $bySection = [];
    foreach ($rows as $row) {
        $sectionName = trim((string) ($row[3] ?? '')) ?: 'Unassigned';
        $bySection[$sectionName][] = $row;
    }
    if (!$bySection) $bySection['Attendance'] = [];
    uksort($bySection, static fn(string $left, string $right): int => strnatcasecmp($left, $right));

    $sheets = [];
    foreach ($bySection as $sectionName => $sectionRows) {
        $sheets[] = [
            'name' => $sectionName,
            'title' => 'Attendance Register — ' . $sectionName,
            'subtitle' => 'Attendance date: ' . $dateLabel . ' | Section: ' . $sectionName,
            'headers' => $headers,
            'rows' => $sectionRows,
        ];
    }
    return $sheets;
}

/* Date-range mode: export the recorded attendance rows within the range,
 * snapshot-first so historical placement is preserved. */
if ($dateFrom !== $dateTo) {
    $stmt = $conn->prepare(
        "SELECT a.ID ATTENDANCE_ID, s.STUDENT_ID,
                COALESCE(NULLIF(s.STUDENT_NAME,''), CONCAT_WS(' ', s.FIRST_NAME, s.MIDDLE_NAME, s.LAST_NAME)) STUDENT_NAME,
                {$recordedGrade} GRADE,
                {$recordedSection} SECTION,
                DATE(a.TIME_IN) ATTENDANCE_DATE,
                a.TIME_IN, a.LUNCH_OUT, a.AFTERNOON_TIME_IN, a.TIME_OUT,
                a.ATTENDANCE_REMARK, a.ATTENDANCE_NOTE
           FROM attendance a
           LEFT JOIN students s ON TRIM(s.STUDENT_ID)=TRIM(a.STUDENT_ID)
          WHERE DATE(a.TIME_IN) BETWEEN ? AND ?
            AND (?='all' OR {$recordedGrade}=?)
            AND (?='all' OR {$recordedSection}=?)
            AND (?='' OR s.STUDENT_ID=?)
          ORDER BY DATE(a.TIME_IN), {$recordedSection}, s.LAST_NAME, s.FIRST_NAME, s.STUDENT_NAME",
    );
    $stmt->bind_param("ssssssss", $dateFrom, $dateTo, $grade, $grade, $section, $section, $studentId, $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $absent = attendanceIsAbsent($row["ATTENDANCE_REMARK"] ?? null);
        $midnight = str_ends_with((string) $row["TIME_IN"], "00:00:00");
        $timeIn = !$absent && !$midnight ? (new DateTimeImmutable($row["TIME_IN"]))->format("g:i:s A") : "";
        $timeOut = !$absent && !$midnight && !empty($row["TIME_OUT"]) ? (new DateTimeImmutable($row["TIME_OUT"]))->format("g:i:s A") : "";
        $rows[] = [
            $row["STUDENT_ID"],
            $row["STUDENT_NAME"],
            $row["GRADE"] ?? "N/A",
            $row["SECTION"] ?? "N/A",
            $row["ATTENDANCE_DATE"],
            $timeIn,
            $timeOut,
            attendanceStatusLabel(
                $row["TIME_IN"],
                $row["TIME_OUT"] ?? null,
                $row["ATTENDANCE_REMARK"] ?? null,
                $row["LUNCH_OUT"] ?? null,
                $row["AFTERNOON_TIME_IN"] ?? null,
            ),
            $row["ATTENDANCE_NOTE"] ?? "",
        ];
    }
    downloadAttendanceXlsxSheets(
        "attendance-range-" . preg_replace('/[^A-Za-z0-9_-]/', "_", $dateFrom . "_to_" . $dateTo) . ".xlsx",
        attendanceSectionSheets($attendanceHeaders, $rows, $dateFrom . ' to ' . $dateTo),
    );
    exit;
}

/*
 * Single-day mode: export the section roster, not just rows that already have
 * attendance. The recorded section/grade (snapshot) is preferred so historical
 * records stay with the class they were taken in; current placement is fallback.
 */
$stmt = $conn->prepare(
    "SELECT a.ID ATTENDANCE_ID, s.STUDENT_ID,
            COALESCE(NULLIF(s.STUDENT_NAME,''), CONCAT_WS(' ', s.FIRST_NAME, s.MIDDLE_NAME, s.LAST_NAME)) STUDENT_NAME,
            {$recordedGrade} GRADE,
            {$recordedSection} SECTION,
            a.TIME_IN, a.TIME_OUT, a.ATTENDANCE_REMARK, a.ATTENDANCE_NOTE
       FROM students s
       LEFT JOIN attendance a ON a.ID = (
           SELECT a2.ID FROM attendance a2
            WHERE TRIM(a2.STUDENT_ID)=TRIM(s.STUDENT_ID) AND DATE(a2.TIME_IN)=?
            ORDER BY a2.TIME_IN DESC, a2.ID DESC
            LIMIT 1
       )
      WHERE (a.ID IS NOT NULL OR COALESCE(s.ENROLLMENT_STATUS,'Enrolled')='Enrolled')
        AND (?='all' OR {$recordedGrade}=?)
        AND (?='all' OR {$recordedSection}=?)
        AND (?='' OR s.STUDENT_ID=?)
      ORDER BY {$recordedSection}, s.LAST_NAME, s.FIRST_NAME, s.STUDENT_NAME",
);
$stmt->bind_param(
    "sssssss",
    $date,
    $grade,
    $grade,
    $section,
    $section,
    $studentId,
    $studentId,
);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) {
    $hasRecord = !empty($row["ATTENDANCE_ID"]);
    $absent = $hasRecord && attendanceIsAbsent($row["ATTENDANCE_REMARK"] ?? null);
    $timeIn = $hasRecord && !$absent
        ? (new DateTimeImmutable($row["TIME_IN"]))->format("g:i:s A")
        : "";
    $timeOut = $hasRecord && !$absent && !empty($row["TIME_OUT"])
        ? (new DateTimeImmutable($row["TIME_OUT"]))->format("g:i:s A")
        : "";
    $status = $hasRecord
        ? attendanceStatusLabel(
            $row["TIME_IN"],
            $row["TIME_OUT"] ?? null,
            $row["ATTENDANCE_REMARK"] ?? null,
        )
        : "Not recorded";
    $rows[] = [
        $row["STUDENT_ID"],
        $row["STUDENT_NAME"],
        $row["GRADE"] ?? "N/A",
        $row["SECTION"] ?? "N/A",
        $date,
        $timeIn,
        $timeOut,
        $status,
        $row["ATTENDANCE_NOTE"] ?? "",
    ];
}
$filePart = preg_replace(
    "/[^A-Za-z0-9_-]/",
    "_",
    $studentId ?: ($grade !== "all" ? "grade-" . $grade : $section),
);
downloadAttendanceXlsxSheets(
    "attendance-" . $filePart . "-" . $date . ".xlsx",
    attendanceSectionSheets($attendanceHeaders, $rows, $date),
);
