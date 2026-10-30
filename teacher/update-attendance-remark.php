<?php
require 'page-guard.php';
require_once '../config/db_conn.php';
require_once '../includes/attendance-schema.php';
require_once '../includes/teacher-assignment-schema.php';
ensureAttendanceTimeOutColumn($conn);ensureTeacherAssignmentSchema($conn);ensureAttendanceSnapshotColumns($conn);

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $_SESSION['toast'] = ['message' => 'The attendance update expired. Please try again.', 'type' => 'error'];
    header('Location: home.php');exit;
}

$id=(int)($_POST['attendance_id'] ?? 0);$studentId=trim((string)($_POST['student_id'] ?? ''));$attendanceDate=trim((string)($_POST['attendance_date'] ?? ''));$remark=trim((string)($_POST['attendance_remark'] ?? ''));$note=trim((string)($_POST['attendance_note'] ?? ''));$teacherId=(int)($_SESSION['teacher-id'] ?? 0);
if ($remark !== '' && !in_array($remark, attendanceRemarkOptions(), true)) {
    $_SESSION['toast'] = ['message' => 'Choose a valid attendance status.', 'type' => 'error'];
} elseif (mb_strlen($note) > 255) {
    $_SESSION['toast'] = ['message' => 'Keep the attendance reason to 255 characters or fewer.', 'type' => 'error'];
} elseif ($id < 1) {
    if ($studentId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendanceDate) || $remark !== 'Excused absence') {
        $_SESSION['toast'] = ['message' => 'Choose Excused absence to record an unscanned student.', 'type' => 'error'];
    } else {
        $student=$conn->prepare("SELECT s.STUDENT_ID,s.STUDENT_NAME,s.GRADE,s.STRAND,s.SECTION FROM students s WHERE TRIM(s.STUDENT_ID)=TRIM(?) AND COALESCE(s.ENROLLMENT_STATUS,'Enrolled')='Enrolled' AND EXISTS (SELECT 1 FROM current_teacher_advisory_assignments ta WHERE ta.TEACHER_ID=? AND ta.GRADE=s.GRADE AND (ta.SECTION=s.SECTION AND ta.STRAND=s.STRAND)) LIMIT 1");
        $student->bind_param('si',$studentId,$teacherId);$student->execute();$studentRow=$student->get_result()->fetch_assoc();
        $existing=$conn->prepare('SELECT ID FROM attendance WHERE TRIM(STUDENT_ID)=TRIM(?) AND DATE(TIME_IN)=? LIMIT 1');
        $existing->bind_param('ss',$studentId,$attendanceDate);$existing->execute();
        if(!$studentRow || $existing->get_result()->fetch_assoc()) {
            $_SESSION['toast'] = ['message' => 'Attendance was already recorded or the student is unavailable.', 'type' => 'error'];
        } else {
            $insert=$conn->prepare("INSERT INTO attendance (STUDENT_ID,STUDENT_NAME,TIME_IN,ATTENDANCE_REMARK,ATTENDANCE_NOTE,IS_AUTO_ABSENT,GRADE_SNAPSHOT,STRAND_SNAPSHOT,SECTION_SNAPSHOT) VALUES (?,?,CONCAT(?,' 00:00:00'),?,?,0,?,?,?)");
            $insert->bind_param('sssssiss',$studentRow['STUDENT_ID'],$studentRow['STUDENT_NAME'],$attendanceDate,$remark,$note,$studentRow['GRADE'],$studentRow['STRAND'],$studentRow['SECTION']);$insert->execute();
            $_SESSION['toast'] = ['message' => $insert->affected_rows ? 'Excused absence recorded.' : 'Could not save the attendance record.', 'type' => $insert->affected_rows ? 'success' : 'error'];
        }
    }
} else {
    $allowed=$conn->prepare("SELECT a.ID FROM attendance a INNER JOIN students s ON TRIM(s.STUDENT_ID)=TRIM(a.STUDENT_ID) INNER JOIN current_teacher_advisory_assignments ta ON ta.TEACHER_ID=? AND ta.GRADE=s.GRADE AND (ta.SECTION=s.SECTION AND ta.STRAND=s.STRAND) WHERE a.ID=? AND s.ENROLLMENT_STATUS='Enrolled' LIMIT 1");
    $allowed->bind_param('ii',$teacherId,$id);$allowed->execute();
    if (!$allowed->get_result()->fetch_assoc()) {
        $_SESSION['toast'] = ['message' => 'You can update attendance only for your assigned students.', 'type' => 'error'];
    } else {
        $update=$conn->prepare('UPDATE attendance SET ATTENDANCE_REMARK=?, ATTENDANCE_NOTE=? WHERE ID=?');
        $remarkValue=$remark===''?null:$remark;$noteValue=$note===''?null:$note;
        $update->bind_param('ssi',$remarkValue,$noteValue,$id);$update->execute();
        $_SESSION['toast'] = ['message' => $update->affected_rows ? 'Attendance status and reason saved.' : 'No attendance changes were needed.', 'type' => 'success'];
    }
}
header('Location: home.php');exit;
