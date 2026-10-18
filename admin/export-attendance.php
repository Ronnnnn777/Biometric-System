<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/attendance-schema.php";
ensureAttendanceTimeOutColumn($conn);
require "../includes/xlsx-export.php";
$date = $_GET["date"] ?? date("Y-m-d");
$grade = $_GET["grade"] ?? "all";
$section = $_GET["section"] ?? "all";
$studentId = trim($_GET["student_id"] ?? ($_GET["student"] ?? ""));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    exit("Invalid date.");
}
if (
    !in_array((string) $grade, ["all", "7", "8", "9", "10", "11", "12"], true)
) {
    $grade = "all";
}
$stmt = $conn->prepare(
    "SELECT a.STUDENT_ID, COALESCE(s.STUDENT_NAME, a.STUDENT_NAME) STUDENT_NAME,
            s.GRADE, s.SECTION, a.TIME_IN, a.TIME_OUT,
            a.ATTENDANCE_REMARK, a.ATTENDANCE_NOTE
       FROM attendance a
       LEFT JOIN students s ON TRIM(a.STUDENT_ID)=TRIM(s.STUDENT_ID)
      WHERE DATE(a.TIME_IN)=?
        AND (?='all' OR s.GRADE=?)
        AND (?='all' OR s.SECTION=?)
        AND (?='' OR a.STUDENT_ID=?)
      ORDER BY s.GRADE, s.SECTION, STUDENT_NAME",
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
    $absent = attendanceIsAbsent($row["ATTENDANCE_REMARK"] ?? null);
    $timeIn = $absent
        ? ""
        : (new DateTimeImmutable($row["TIME_IN"]))->format("g:i:s A");
    $timeOut = $absent
        ? ""
        : (empty($row["TIME_OUT"])
            ? ""
            : (new DateTimeImmutable($row["TIME_OUT"]))->format("g:i:s A"));
    $rows[] = [
        $row["STUDENT_ID"],
        $row["STUDENT_NAME"],
        $row["GRADE"] ?? "N/A",
        $row["SECTION"] ?? "N/A",
        $date,
        $timeIn,
        $timeOut,
        attendanceStatusLabel(
            $row["TIME_IN"],
            $row["TIME_OUT"] ?? null,
            $row["ATTENDANCE_REMARK"] ?? null,
        ),
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
