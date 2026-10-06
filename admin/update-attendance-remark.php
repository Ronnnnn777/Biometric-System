<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/attendance-schema.php';
ensureAttendanceTimeOutColumn($conn);

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $_SESSION['toast'] = ['message' => 'The attendance update expired. Please try again.', 'type' => 'error'];
    header('Location: attendance-log.php');exit;
}

$id=(int)($_POST['attendance_id'] ?? 0);$remark=trim((string)($_POST['attendance_remark'] ?? ''));$note=trim((string)($_POST['attendance_note'] ?? ''));
if ($remark !== '' && !in_array($remark, attendanceRemarkOptions(), true)) {
    $_SESSION['toast'] = ['message' => 'Choose a valid attendance status.', 'type' => 'error'];
} elseif (mb_strlen($note) > 255) {
    $_SESSION['toast'] = ['message' => 'Keep the attendance reason to 255 characters or fewer.', 'type' => 'error'];
} elseif ($id < 1) {
    $_SESSION['toast'] = ['message' => 'Attendance record not found.', 'type' => 'error'];
} else {
    $update=$conn->prepare('UPDATE attendance SET ATTENDANCE_REMARK=?, ATTENDANCE_NOTE=? WHERE ID=?');
    $remarkValue=$remark===''?null:$remark;$noteValue=$note===''?null:$note;
    $update->bind_param('ssi',$remarkValue,$noteValue,$id);$update->execute();
    $_SESSION['toast'] = ['message' => $update->affected_rows ? 'Attendance status and reason saved.' : 'No attendance changes were needed.', 'type' => 'success'];
}
header('Location: attendance-log.php');exit;
