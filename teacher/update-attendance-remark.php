<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/attendance-schema.php';
require '../includes/teacher-assignment-schema.php';
ensureAttendanceTimeOutColumn($conn);ensureTeacherAssignmentSchema($conn);

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $_SESSION['toast'] = ['message' => 'The attendance update expired. Please try again.', 'type' => 'error'];
    header('Location: home.php');exit;
}

$id=(int)($_POST['attendance_id'] ?? 0);$remark=trim((string)($_POST['attendance_remark'] ?? ''));$note=trim((string)($_POST['attendance_note'] ?? ''));$teacherId=(int)($_SESSION['teacher-id'] ?? 0);
if ($remark !== '' && !in_array($remark, attendanceRemarkOptions(), true)) {
    $_SESSION['toast'] = ['message' => 'Choose a valid attendance status.', 'type' => 'error'];
} elseif (mb_strlen($note) > 255) {
    $_SESSION['toast'] = ['message' => 'Keep the attendance reason to 255 characters or fewer.', 'type' => 'error'];
} else {
    $allowed=$conn->prepare("SELECT a.ID FROM attendance a INNER JOIN students s ON TRIM(s.STUDENT_ID)=TRIM(a.STUDENT_ID) INNER JOIN teacher_assignments ta ON ta.TEACHER_ID=? AND ta.GRADE=s.GRADE AND (ta.SUBJECT<>'' OR ta.SECTION=s.SECTION) WHERE a.ID=? LIMIT 1");
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
