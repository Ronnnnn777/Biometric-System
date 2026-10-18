<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/csrf-token.php';
require '../includes/student-profile-schema.php';
require '../includes/teacher-assignment-schema.php';
require '../includes/school-year-schema.php';
require '../includes/student-promotion-schema.php';

ensureStudentProfileColumns($conn);
ensureTeacherAssignmentSchema($conn);
ensureSchoolYearRolloverSchema($conn);
ensureStudentPromotionSchema($conn);

$studentRecordId = (int) ($_POST['student_record_id'] ?? 0);
$grade = (int) ($_POST['grade'] ?? 0);
$section = trim((string) ($_POST['section'] ?? ''));
$redirect = 'student-profile.php?id=' . $studentRecordId . '&grade=' . $grade . '&section=' . urlencode($section);

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $_SESSION['toast'] = ['message' => 'The academic-result request expired. Please try again.', 'type' => 'error'];
    header('Location: ' . $redirect);
    exit;
}

$subject = trim((string) ($_POST['subject'] ?? ''));
$result = trim((string) ($_POST['result'] ?? ''));
if ($studentRecordId < 1 || $subject === '' || mb_strlen($subject) > 80 || !in_array($result, ['Passed', 'Failed'], true)) {
    $_SESSION['toast'] = ['message' => 'Choose a subject and a valid Passed or Failed result.', 'type' => 'error'];
    header('Location: ' . $redirect);
    exit;
}

$studentQuery = $conn->prepare("SELECT STUDENT_ID,GRADE,STRAND,SECTION FROM students WHERE ID=? AND ENROLLMENT_STATUS='Enrolled' LIMIT 1");
$studentQuery->bind_param('i', $studentRecordId);
$studentQuery->execute();
$student = $studentQuery->get_result()->fetch_assoc();
if (!$student) {
    $_SESSION['toast'] = ['message' => 'Only currently enrolled students can receive subject results.', 'type' => 'error'];
    header('Location: students.php');
    exit;
}

$teacherId = (int) ($_SESSION['teacher-id'] ?? 0);
$assignmentQuery = $conn->prepare('SELECT SUBJECT FROM teacher_assignments WHERE TEACHER_ID=? AND GRADE=? AND STRAND=? AND SECTION=? LIMIT 1');
$assignmentQuery->bind_param('iiss', $teacherId, $student['GRADE'], $student['STRAND'], $student['SECTION']);
$assignmentQuery->execute();
$assignment = $assignmentQuery->get_result()->fetch_assoc();
if (!$assignment) {
    $_SESSION['toast'] = ['message' => 'You can record results only for students in your assigned class.', 'type' => 'error'];
    header('Location: students.php');
    exit;
}

$assignedSubject = trim((string) ($assignment['SUBJECT'] ?? ''));
if ($assignedSubject !== '' && $subject !== $assignedSubject) {
    $_SESSION['toast'] = ['message' => 'Use the subject assigned to your class.', 'type' => 'error'];
    header('Location: ' . $redirect);
    exit;
}

$setting = $conn->query('SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1')->fetch_assoc();
$schoolYear = (string) ($setting['ACTIVE_SCHOOL_YEAR'] ?? '');
$save = $conn->prepare('INSERT INTO student_subject_results (STUDENT_ID,SCHOOL_YEAR,SUBJECT,RESULT,TEACHER_ID,GRADE,STRAND,SECTION) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE RESULT=VALUES(RESULT),TEACHER_ID=VALUES(TEACHER_ID),GRADE=VALUES(GRADE),STRAND=VALUES(STRAND),SECTION=VALUES(SECTION)');
$save->bind_param('ssssiiss', $student['STUDENT_ID'], $schoolYear, $subject, $result, $teacherId, $student['GRADE'], $student['STRAND'], $student['SECTION']);
$save->execute();

$_SESSION['toast'] = ['message' => $subject . ' result saved as ' . $result . '.', 'type' => 'success'];
header('Location: ' . $redirect);
exit;
