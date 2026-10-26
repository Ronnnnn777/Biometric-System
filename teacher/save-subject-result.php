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

/**
 * Read a posted quarterly grade, returning null for a blank cell. A non-numeric
 * or out-of-range value is flagged through $valid so the caller can report it.
 */
function postedQuarterGrade($value, bool &$valid): ?float
{
    if ($value === null || trim((string) $value) === '') {
        return null;
    }
    if (!is_numeric($value)) {
        $valid = false;
        return null;
    }
    $number = (float) $value;
    if ($number < 0 || $number > 100) {
        $valid = false;
        return null;
    }
    return round($number, 2);
}

$studentRecordId = (int) ($_POST['student_record_id'] ?? 0);
$grade = (int) ($_POST['grade'] ?? 0);
$section = trim((string) ($_POST['section'] ?? ''));
$quarter = (int) ($_POST['quarter_period'] ?? 0);
$bulk = isset($_POST['student_ids']) && is_array($_POST['student_ids']);

$redirect = $bulk
    ? 'students.php?' . http_build_query(array_filter([
        'grade' => $grade ?: null,
        'section' => $section !== '' ? $section : null,
        'quarter' => $quarter >= 1 && $quarter <= 4 ? $quarter : null,
    ], fn($value) => $value !== null))
    : 'student-profile.php?id=' . $studentRecordId . '&grade=' . $grade . '&section=' . urlencode($section);

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $_SESSION['toast'] = ['message' => 'The academic-result request expired. Please try again.', 'type' => 'error'];
    header('Location: ' . $redirect);
    exit;
}

$subject = trim((string) ($_POST['subject'] ?? ''));
$teacherId = (int) ($_SESSION['teacher-id'] ?? 0);

/* ---------------------------------------------------------------
 * Quarterly grade sheet (teacher/students.php).
 * --------------------------------------------------------------- */
if ($bulk) {
    $studentIds = array_values(array_unique(array_filter(
        array_map('intval', (array) $_POST['student_ids']),
        fn($id) => $id > 0,
    )));
    $onlyStudent = (int) ($_POST['save_student'] ?? 0);
    if ($onlyStudent > 0) {
        if (!in_array($onlyStudent, $studentIds, true)) {
            $_SESSION['toast'] = ['message' => 'That student is not part of the selected class.', 'type' => 'error'];
            header('Location: ' . $redirect);
            exit;
        }
        $studentIds = [$onlyStudent];
    }

    if (!$studentIds || $grade < 7 || $grade > 12 || $section === '' || $subject === '' || mb_strlen($subject) > 80) {
        $_SESSION['toast'] = ['message' => 'Choose a valid class and subject before saving grades.', 'type' => 'error'];
        header('Location: ' . $redirect);
        exit;
    }

    $setting = $conn->query('SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1')->fetch_assoc();
    $schoolYear = (string) ($setting['ACTIVE_SCHOOL_YEAR'] ?? '');
    if ($schoolYear === '') {
        $_SESSION['toast'] = ['message' => 'Set the active school year before saving grades.', 'type' => 'error'];
        header('Location: ' . $redirect);
        exit;
    }

    $studentQuery = $conn->prepare('SELECT STUDENT_ID,GRADE,STRAND,SECTION FROM students WHERE ID=? AND ENROLLMENT_STATUS=? LIMIT 1');
    $enrolled = 'Enrolled';
    $assignmentQuery = $conn->prepare('SELECT SUBJECT FROM teacher_assignments WHERE TEACHER_ID=? AND GRADE=? AND STRAND=? AND SECTION=? LIMIT 1');
    $save = $conn->prepare(
        'INSERT INTO student_subject_results
            (STUDENT_ID,SCHOOL_YEAR,SUBJECT,RESULT,TEACHER_ID,GRADE,STRAND,SECTION,Q1_GRADE,Q2_GRADE,Q3_GRADE,Q4_GRADE,FINAL_GRADE)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            RESULT=VALUES(RESULT),TEACHER_ID=VALUES(TEACHER_ID),GRADE=VALUES(GRADE),
            STRAND=VALUES(STRAND),SECTION=VALUES(SECTION),Q1_GRADE=VALUES(Q1_GRADE),
            Q2_GRADE=VALUES(Q2_GRADE),Q3_GRADE=VALUES(Q3_GRADE),Q4_GRADE=VALUES(Q4_GRADE),
            FINAL_GRADE=VALUES(FINAL_GRADE)',
    );

    $saved = 0;
    $skipped = 0;
    foreach ($studentIds as $studentId) {
        $studentQuery->bind_param('is', $studentId, $enrolled);
        $studentQuery->execute();
        $student = $studentQuery->get_result()->fetch_assoc();
        if (!$student || (int) $student['GRADE'] !== $grade || (string) $student['SECTION'] !== $section) {
            $skipped++;
            continue;
        }

        $assignmentQuery->bind_param('iiss', $teacherId, $student['GRADE'], $student['STRAND'], $student['SECTION']);
        $assignmentQuery->execute();
        $assignment = $assignmentQuery->get_result()->fetch_assoc();
        if (!$assignment) {
            $skipped++;
            continue;
        }
        $assignedSubject = trim((string) ($assignment['SUBJECT'] ?? ''));
        if ($assignedSubject !== '' && $subject !== $assignedSubject) {
            $skipped++;
            continue;
        }

        $valid = true;
        $quarters = [
            1 => postedQuarterGrade($_POST['q1'][$studentId] ?? null, $valid),
            2 => postedQuarterGrade($_POST['q2'][$studentId] ?? null, $valid),
            3 => postedQuarterGrade($_POST['q3'][$studentId] ?? null, $valid),
            4 => postedQuarterGrade($_POST['q4'][$studentId] ?? null, $valid),
        ];
        if (!$valid) {
            $skipped++;
            continue;
        }
        $finalGrade = studentFinalGrade($quarters);
        $result = studentResultRemark($finalGrade);
        if ($finalGrade === null || $result === '') {
            $skipped++;
            continue;
        }

        $q1 = $quarters[1];
        $q2 = $quarters[2];
        $q3 = $quarters[3];
        $q4 = $quarters[4];
        $studentKey = (string) $student['STUDENT_ID'];
        $studentGrade = (int) $student['GRADE'];
        $studentStrand = (string) $student['STRAND'];
        $studentSection = (string) $student['SECTION'];
        $save->bind_param(
            'ssssiissddddd',
            $studentKey,
            $schoolYear,
            $subject,
            $result,
            $teacherId,
            $studentGrade,
            $studentStrand,
            $studentSection,
            $q1,
            $q2,
            $q3,
            $q4,
            $finalGrade,
        );
        if ($save->execute()) {
            $saved++;
        } else {
            $skipped++;
        }
    }

    $message = $saved . ' student' . ($saved === 1 ? '' : 's') . ' saved for ' . $subject . '.';
    if ($skipped > 0) {
        $message .= ' ' . $skipped . ' row' . ($skipped === 1 ? '' : 's') . ' skipped (blank or invalid grade).';
    }
    $_SESSION['toast'] = ['message' => $message, 'type' => $saved > 0 ? 'success' : 'error'];
    header('Location: ' . $redirect);
    exit;
}

/* ---------------------------------------------------------------
 * Single subject result (teacher/student-profile.php).
 * --------------------------------------------------------------- */
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
