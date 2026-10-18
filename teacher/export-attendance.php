<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/attendance-schema.php";
require_once "../includes/teacher-profile-schema.php";
require "../includes/teacher-assignment-schema.php";
require "../includes/xlsx-export.php";
ensureAttendanceTimeOutColumn($conn);
ensureTeacherProfileColumns($conn);
ensureTeacherAssignmentSchema($conn);
$section = trim($_GET["section"] ?? "all");
$grade = (int) ($_GET["grade"] ?? 0);
$date = $_GET["date"] ?? date("Y-m-d");
$student = trim($_GET["student"] ?? "");
$allDates = ($_GET["all_dates"] ?? "") === "1";
$teacherId = (int) ($_SESSION["teacher-id"] ?? 0);
if (!$allDates && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    exit("Invalid date.");
}
if ($allDates && $student === "") {
    http_response_code(400);
    exit("A student is required when exporting all dates.");
}
if ($allDates) {
    $stmt = $conn->prepare(
        "SELECT DISTINCT a.STUDENT_ID, COALESCE(s.STUDENT_NAME, a.STUDENT_NAME) STUDENT_NAME,
                s.GRADE, s.SECTION, a.TIME_IN, a.TIME_OUT,
                a.ATTENDANCE_REMARK, a.ATTENDANCE_NOTE
           FROM attendance a
           INNER JOIN students s ON TRIM(a.STUDENT_ID)=TRIM(s.STUDENT_ID)
           INNER JOIN teacher_assignments ta ON ta.TEACHER_ID=?
                AND ta.GRADE=s.GRADE
                AND (ta.SUBJECT<>'' OR ta.SECTION=s.SECTION)
          WHERE a.STUDENT_ID=?
          ORDER BY a.TIME_IN DESC",
    );
    $stmt->bind_param("is", $teacherId, $student);
} else {
    $stmt = $conn->prepare(
        "SELECT DISTINCT a.STUDENT_ID, COALESCE(s.STUDENT_NAME, a.STUDENT_NAME) STUDENT_NAME,
                s.GRADE, s.SECTION, a.TIME_IN, a.TIME_OUT,
                a.ATTENDANCE_REMARK, a.ATTENDANCE_NOTE
           FROM attendance a
           INNER JOIN students s ON TRIM(a.STUDENT_ID)=TRIM(s.STUDENT_ID)
           INNER JOIN teacher_assignments ta ON ta.TEACHER_ID=?
                AND ta.GRADE=s.GRADE
                AND (ta.SUBJECT<>'' OR ta.SECTION=s.SECTION)
          WHERE DATE(a.TIME_IN)=?
            AND (?='all' OR s.SECTION=?)
            AND (?=0 OR s.GRADE=?)
            AND (?='' OR a.STUDENT_ID=?)
          ORDER BY s.GRADE, s.SECTION, STUDENT_NAME",
    );
    $stmt->bind_param(
        "isssiiss",
        $teacherId,
        $date,
        $section,
        $section,
        $grade,
        $grade,
        $student,
        $student,
    );
}
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) {
    $timeIn = new DateTimeImmutable($row["TIME_IN"]);
    $absent = attendanceIsAbsent($row["ATTENDANCE_REMARK"] ?? null);
    $timeOut = empty($row["TIME_OUT"])
        ? null
        : new DateTimeImmutable($row["TIME_OUT"]);
    $rows[] = [
        $row["STUDENT_ID"],
        $row["STUDENT_NAME"],
        "Grade " . $row["GRADE"],
        $row["SECTION"],
        $timeIn->format("Y-m-d"),
        $absent ? "" : $timeIn->format("g:i:s A"),
        $absent ? "" : ($timeOut ? $timeOut->format("g:i:s A") : ""),
        attendanceStatusLabel(
            $row["TIME_IN"],
            $row["TIME_OUT"] ?? null,
            $row["ATTENDANCE_REMARK"] ?? null,
        ),
        $row["ATTENDANCE_NOTE"] ?? "",
    ];
}
$fileSuffix = $allDates ? "all-dates" : $date;
downloadAttendanceXlsx(
    "attendance-" .
        preg_replace("/[^A-Za-z0-9_-]/", "_", $section) .
        "-" .
        $fileSuffix .
        ".xlsx",
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
