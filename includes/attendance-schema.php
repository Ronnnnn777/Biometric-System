<?php
/** Adds attendance fields while keeping existing attendance records unchanged. */
function ensureAttendanceTimeOutColumn(mysqli $conn): void
{
    $columns=[];$result=$conn->query('SHOW COLUMNS FROM attendance');
    while($result&&($column=$result->fetch_assoc()))$columns[strtoupper($column['Field'])]=true;
    if(!isset($columns['TIME_OUT']))$conn->query('ALTER TABLE attendance ADD COLUMN TIME_OUT DATETIME NULL DEFAULT NULL AFTER TIME_IN');
    if(!isset($columns['ATTENDANCE_REMARK']))$conn->query("ALTER TABLE attendance ADD COLUMN ATTENDANCE_REMARK VARCHAR(40) NULL DEFAULT NULL AFTER TIME_OUT");
    if(!isset($columns['ATTENDANCE_NOTE']))$conn->query("ALTER TABLE attendance ADD COLUMN ATTENDANCE_NOTE VARCHAR(255) NULL DEFAULT NULL AFTER ATTENDANCE_REMARK");
    if(!isset($columns['IS_AUTO_ABSENT']))$conn->query("ALTER TABLE attendance ADD COLUMN IS_AUTO_ABSENT TINYINT(1) NOT NULL DEFAULT 0 AFTER ATTENDANCE_NOTE");
    $conn->query("CREATE TABLE IF NOT EXISTS attendance_absence_settings (ID TINYINT UNSIGNED NOT NULL PRIMARY KEY, ENABLED_FROM DATE NOT NULL)");
    $conn->query("INSERT IGNORE INTO attendance_absence_settings (ID, ENABLED_FROM) VALUES (1, CURDATE())");
}

/** Adds a single Absent record for enrolled students who had no time-in on a completed weekday. */
function syncAutomaticAbsences(mysqli $conn): void
{
    ensureAttendanceTimeOutColumn($conn);
    $settings=$conn->query('SELECT ENABLED_FROM FROM attendance_absence_settings WHERE ID=1 LIMIT 1')->fetch_assoc();
    $start=isset($settings['ENABLED_FROM'])?new DateTimeImmutable($settings['ENABLED_FROM']):new DateTimeImmutable('today');
    $now=new DateTimeImmutable('now');
    $end=$now->format('H:i:s') >= ATTENDANCE_ABSENCE_FINALIZE_AT ? $now->setTime(0,0) : new DateTimeImmutable('yesterday');
    if($start>$end)return;
    // If a historical time-in is later added, remove only the automatic absence.
    $conn->query("DELETE absent FROM attendance absent INNER JOIN attendance present ON TRIM(present.STUDENT_ID)=TRIM(absent.STUDENT_ID) AND DATE(present.TIME_IN)=DATE(absent.TIME_IN) AND present.IS_AUTO_ABSENT=0 WHERE absent.IS_AUTO_ABSENT=1");
    $insert=$conn->prepare("INSERT INTO attendance (STUDENT_ID, STUDENT_NAME, TIME_IN, ATTENDANCE_REMARK, ATTENDANCE_NOTE, IS_AUTO_ABSENT) SELECT s.STUDENT_ID, s.STUDENT_NAME, CONCAT(?, ' 00:00:00'), 'Absent', 'Automatically marked absent: no time in recorded.', 1 FROM students s WHERE s.ENROLLMENT_STATUS='Enrolled' AND NOT EXISTS (SELECT 1 FROM attendance a WHERE TRIM(a.STUDENT_ID)=TRIM(s.STUDENT_ID) AND DATE(a.TIME_IN)=?)");
    for($day=$start;$day<=$end;$day=$day->modify('+1 day')){
        if((int)$day->format('N')>5)continue;
        $date=$day->format('Y-m-d');$insert->bind_param('ss',$date,$date);$insert->execute();
    }
}

const ATTENDANCE_LATE_FROM = '07:21:00';
const ATTENDANCE_ABSENCE_FINALIZE_AT = '23:59:00';
const ATTENDANCE_TIME_OUT_COOLDOWN_SECONDS = 300;

/** Returns the number of seconds before a recorded time in may become a time out. */
function attendanceTimeOutCooldownRemaining(?string $timeIn): int
{
    if (!$timeIn) return 0;
    $recordedAt = strtotime($timeIn);
    if ($recordedAt === false) return 0;
    return max(0, ATTENDANCE_TIME_OUT_COOLDOWN_SECONDS - (time() - $recordedAt));
}

function attendanceTimeOutCooldownMessage(int $remainingSeconds): string
{
    $minutes = (int)ceil($remainingSeconds / 60);
    return 'Time in is protected for ' . $minutes . ' more minute' . ($minutes === 1 ? '' : 's') . '.';
}

function attendanceIsLate(string $timeIn): bool
{
    try {
        return (new DateTimeImmutable($timeIn))->format('H:i:s') >= ATTENDANCE_LATE_FROM;
    } catch (Throwable) {
        return false;
    }
}

function attendanceRemarkOptions(): array
{
    return ['Excused late', 'Early dismissal'];
}

function attendanceIsAbsent(?string $remark): bool
{
    return $remark === 'Absent';
}

function attendanceStatusLabel(string $timeIn, ?string $timeOut = null, ?string $remark = null): string
{
    if (attendanceIsAbsent($remark)) return 'Absent';
    if (in_array($remark, attendanceRemarkOptions(), true)) return $remark;
    $late = attendanceIsLate($timeIn);
    if ($late && !empty($timeOut)) return 'Late · Dismissed';
    if ($late) return 'Late';
    return empty($timeOut) ? 'Present' : 'Dismissed';
}

function attendanceStatusClass(string $timeIn, ?string $timeOut = null, ?string $remark = null): string
{
    if (attendanceIsAbsent($remark)) return 'bg-rose-50 text-rose-700';
    if ($remark === 'Excused late') return 'bg-violet-50 text-violet-700';
    if ($remark === 'Early dismissal') return 'bg-sky-50 text-sky-700';
    if (attendanceIsLate($timeIn)) return 'bg-rose-50 text-rose-700';
    return empty($timeOut) ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700';
}
