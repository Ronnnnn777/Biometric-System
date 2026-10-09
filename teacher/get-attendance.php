<?php
require 'page-guard.php';require '../config/db_conn.php';require '../includes/attendance-schema.php';require '../includes/teacher-profile-schema.php';require '../includes/teacher-assignment-schema.php';
ensureAttendanceTimeOutColumn($conn);ensureTeacherProfileColumns($conn);ensureTeacherAssignmentSchema($conn);
syncAutomaticAbsences($conn);
$section=trim($_GET['section']??'all');$grade=(int)($_GET['grade']??0);$date=$_GET['date']??date('Y-m-d');$student=trim($_GET['student']??'');$teacherId=(int)($_SESSION['teacher-id']??0);
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){http_response_code(400);exit('Invalid date.');}
$stmt=$conn->prepare("SELECT DISTINCT a.ID,a.STUDENT_ID,COALESCE(s.STUDENT_NAME,a.STUDENT_NAME) STUDENT_NAME,s.GRADE,s.SECTION,a.TIME_IN,a.TIME_OUT,a.ATTENDANCE_REMARK,a.ATTENDANCE_NOTE FROM attendance a INNER JOIN students s ON TRIM(a.STUDENT_ID)=TRIM(s.STUDENT_ID) INNER JOIN teacher_assignments ta ON ta.TEACHER_ID=? AND ta.GRADE=s.GRADE AND (ta.SUBJECT<>'' OR ta.SECTION=s.SECTION) WHERE DATE(a.TIME_IN)=? AND (?='all' OR s.SECTION=?) AND (?=0 OR s.GRADE=?) AND (?='' OR a.STUDENT_ID=?) ORDER BY s.GRADE,s.SECTION,STUDENT_NAME");
$stmt->bind_param('isssiiss',$teacherId,$date,$section,$section,$grade,$grade,$student,$student);$stmt->execute();$result=$stmt->get_result();
if($result->num_rows):while($row=$result->fetch_assoc()):
    $timeIn=new DateTime($row['TIME_IN']);$timeOut=empty($row['TIME_OUT'])?null:new DateTime($row['TIME_OUT']);$remark=(string)($row['ATTENDANCE_REMARK']??'');$note=(string)($row['ATTENDANCE_NOTE']??'');$isAbsent=attendanceIsAbsent($remark);$status=attendanceStatusLabel($row['TIME_IN'],$row['TIME_OUT']??null,$remark);
?>
<tr>
  <td class="px-4 py-4"><?= htmlspecialchars($row['STUDENT_ID']) ?></td><td class="px-4 py-4 font-bold"><?= htmlspecialchars($row['STUDENT_NAME']) ?></td>
  <td class="px-4 py-4"><span class="rounded-full bg-violet-50 px-3 py-1 text-xs font-bold text-violet-700">Grade <?= htmlspecialchars($row['GRADE']??'—') ?></span></td>
  <td class="px-4 py-4"><a class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700 hover:bg-blue-100" href="students.php?grade=<?= (int)$row['GRADE'] ?>&section=<?= urlencode($row['SECTION']) ?>"><?= htmlspecialchars($row['SECTION']) ?></a></td>
  <td class="px-4 py-4"><?= $timeIn->format('F j, Y') ?></td><td class="px-4 py-4"><?= $isAbsent?'—':$timeIn->format('g:i A') ?></td><td class="px-4 py-4"><?= $isAbsent?'—':($timeOut?$timeOut->format('g:i A'):'—') ?></td>
  <td class="px-4 py-4"><span class="rounded-full px-3 py-1 text-xs font-bold <?= attendanceStatusClass($row['TIME_IN'],$row['TIME_OUT']??null,$remark) ?>"><?= htmlspecialchars($status) ?></span></td>
  <td class="px-4 py-4"><p class="max-w-xs text-xs text-slate-600"><?= $note!==''?htmlspecialchars($note):'<span class="text-slate-400">No reason added</span>' ?></p><?php if(!$isAbsent): ?><button type="button" data-attendance-edit data-id="<?= (int)$row['ID'] ?>" data-remark="<?= htmlspecialchars($remark,ENT_QUOTES) ?>" data-note="<?= htmlspecialchars($note,ENT_QUOTES) ?>" class="mt-2 text-xs font-bold text-blue-600 hover:text-blue-800">Update</button><?php endif; ?></td>
</tr>
<?php endwhile;else: ?>
<tr><td colspan="9" class="px-4 py-14 text-center"><p class="text-base font-black text-slate-800">No attendance records match the current filters.</p><p class="mt-1 text-sm text-slate-500">Try another date or adjust the grade, section, or student filter above.</p></td></tr>
<?php endif; ?>
