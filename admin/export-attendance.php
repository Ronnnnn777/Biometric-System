<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/attendance-schema.php";
ensureAttendanceTimeOutColumn($conn);
require "../includes/xlsx-export.php";

$date = $_GET["date"] ?? date("Y-m-d");
$grade = $_GET["grade"] ?? "all";
$section = trim((string) ($_GET["section"] ?? "all"));
$studentId = trim($_GET["student_id"] ?? ($_GET["student"] ?? ""));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    exit("Invalid date.");
}
if (!in_array((string) $grade, ["all", "7", "8", "9", "10", "11", "12"], true)) {
    $grade = "all";
}

/*
 * Export the section roster, not just rows that already have attendance.
 * The recorded section/grade (snapshot) is preferred so historical records stay
 * with the class they were taken in; current placement is the fallback.
 */
$recordedSection = "COALESCE(NULLIF(a.SECTION_SNAPSHOT,''), s.SECTION)";
$recordedGrade = "COALESCE(a.GRADE_SNAPSHOT, s.GRADE)";
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
downloadAttendanceXlsx(
    "attendance-" . $filePart . "-" . $date . ".xlsx",
    [
        "LRN",
        "Student Name",
        "Grade",
        "Section",
        "Date",
        "Time In",
        "Time Out",
        "Status",
        "Reason / Comment",
    ],
    $rows,
);
