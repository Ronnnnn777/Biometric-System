<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/attendance-schema.php';
ensureAttendanceTimeOutColumn($conn);
syncAutomaticAbsences($conn);

$grade=$_GET['grade']??'all';$section=$_GET['section']??'all';$filterDate=$_GET['date']??'';$student=trim($_GET['student']??'');$where=[];
if($filterDate!=='')$where[]="DATE(a.TIME_IN)='".mysqli_real_escape_string($conn,$filterDate)."'";
if(in_array((string)$grade,['7','8','9','10','11','12'],true))$where[]='s.GRADE='.(int)$grade;
if($section!==''&&$section!=='all')$where[]="s.SECTION='".mysqli_real_escape_string($conn,$section)."'";
if($student!==''){$safe=mysqli_real_escape_string($conn,$student);$where[]="(a.STUDENT_ID='$safe' OR s.STUDENT_NAME LIKE '%$safe%')";}
$query="SELECT a.*,s.GRADE,s.SECTION,s.STUDENT_NAME official_name FROM attendance a LEFT JOIN students s ON TRIM(a.STUDENT_ID)=TRIM(s.STUDENT_ID)";
if($where)$query.=' WHERE '.implode(' AND ',$where);$query.=' ORDER BY a.TIME_IN DESC';$result=$conn->query($query);

if($result&&$result->num_rows):while($row=$result->fetch_assoc()):
    $timeIn=new DateTime($row['TIME_IN']);$timeOut=empty($row['TIME_OUT'])?null:new DateTime($row['TIME_OUT']);$name=$row['official_name']?:$row['STUDENT_NAME'];
    $remark=(string)($row['ATTENDANCE_REMARK']??'');$note=(string)($row['ATTENDANCE_NOTE']??'');$isAbsent=attendanceIsAbsent($remark);
    $status=attendanceStatusLabel($row['TIME_IN'],$row['TIME_OUT']??null,$remark);
?>
<tr class="transition hover:bg-slate-50">
  <td class="px-4 py-4 font-medium text-slate-700"><?= htmlspecialchars($row['STUDENT_ID']) ?></td>
  <td class="px-4 py-4 font-bold text-slate-900"><?= htmlspecialchars($name) ?></td>
  <td class="px-4 py-4"><span class="rounded-full bg-violet-50 px-3 py-1 text-xs font-bold text-violet-700">Grade <?= htmlspecialchars($row['GRADE']??'N/A') ?></span></td>
  <td class="px-4 py-4"><span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700"><?= htmlspecialchars($row['SECTION']??'N/A') ?></span></td>
  <td class="px-4 py-4 text-slate-600"><?= $timeIn->format('F j, Y') ?></td>
  <td class="px-4 py-4 text-slate-600"><?= $isAbsent?'—':$timeIn->format('g:i A') ?></td>
  <td class="px-4 py-4 text-slate-600"><?= $isAbsent?'—':($timeOut?$timeOut->format('g:i A'):'—') ?></td>
  <td class="px-4 py-4"><span class="rounded-full px-3 py-1 text-xs font-bold <?= attendanceStatusClass($row['TIME_IN'],$row['TIME_OUT']??null,$remark) ?>"><?= htmlspecialchars($status) ?></span></td>
  <td class="px-4 py-4"><p class="max-w-xs text-xs text-slate-600"><?= $note!==''?htmlspecialchars($note):'<span class="text-slate-400">No reason added</span>' ?></p><?php if(!$isAbsent): ?><button type="button" data-attendance-edit data-id="<?= (int)$row['ID'] ?>" data-remark="<?= htmlspecialchars($remark,ENT_QUOTES) ?>" data-note="<?= htmlspecialchars($note,ENT_QUOTES) ?>" class="mt-2 text-xs font-bold text-blue-600 hover:text-blue-800">Update</button><?php endif; ?><a class="<?= $isAbsent?'mt-2 inline-block':'ml-3' ?> text-xs font-bold text-emerald-600" href="export-attendance.php?date=<?= urlencode($filterDate?:date('Y-m-d')) ?>&section=<?= urlencode($section) ?>&student_id=<?= urlencode($row['STUDENT_ID']) ?>">Export student</a></td>
</tr>
<?php endwhile;else: ?>
<tr><td colspan="9" class="px-4 py-14 text-center"><p class="text-base font-black text-slate-800">No attendance records match the current filters.</p><p class="mt-1 text-sm text-slate-500">Try another date or adjust the grade, section, or student filter above.</p></td></tr>
<?php endif; ?>
