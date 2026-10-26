<?php
/** Adds attendance fields while keeping existing attendance records unchanged. */
function ensureAttendanceTimeOutColumn(mysqli $conn): void
{
    $columns=[];$result=$conn->query('SHOW COLUMNS FROM attendance');
    while($result&&($column=$result->fetch_assoc()))$columns[strtoupper($column['Field'])]=true;
    if(!isset($columns['TIME_OUT']))$conn->query('ALTER TABLE attendance ADD COLUMN TIME_OUT DATETIME NULL DEFAULT NULL AFTER TIME_IN');
    if(!isset($columns['LUNCH_OUT']))$conn->query('ALTER TABLE attendance ADD COLUMN LUNCH_OUT DATETIME NULL DEFAULT NULL AFTER TIME_IN');
    if(!isset($columns['AFTERNOON_TIME_IN']))$conn->query('ALTER TABLE attendance ADD COLUMN AFTERNOON_TIME_IN DATETIME NULL DEFAULT NULL AFTER LUNCH_OUT');
    if(!isset($columns['ATTENDANCE_REMARK']))$conn->query("ALTER TABLE attendance ADD COLUMN ATTENDANCE_REMARK VARCHAR(40) NULL DEFAULT NULL AFTER TIME_OUT");
    if(!isset($columns['ATTENDANCE_NOTE']))$conn->query("ALTER TABLE attendance ADD COLUMN ATTENDANCE_NOTE VARCHAR(255) NULL DEFAULT NULL AFTER ATTENDANCE_REMARK");
    if(!isset($columns['IS_AUTO_ABSENT']))$conn->query("ALTER TABLE attendance ADD COLUMN IS_AUTO_ABSENT TINYINT(1) NOT NULL DEFAULT 0 AFTER ATTENDANCE_NOTE");
    // Lets the dashboard identify a newly scanned or updated attendance record.
    if(!isset($columns['ATTENDANCE_UPDATED_AT']))$conn->query('ALTER TABLE attendance ADD COLUMN ATTENDANCE_UPDATED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER IS_AUTO_ABSENT');
    // Automatic absences were retired in favor of teacher-confirmed records.
    // Remove only the old system-generated rows; teacher-marked absences use 0.
    $conn->query('DELETE FROM attendance WHERE IS_AUTO_ABSENT=1');
    $conn->query("CREATE TABLE IF NOT EXISTS attendance_day_settings (ID TINYINT UNSIGNED NOT NULL PRIMARY KEY, WHOLE_DAY_ENABLED TINYINT(1) NOT NULL DEFAULT 0, UPDATED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
    $conn->query("INSERT IGNORE INTO attendance_day_settings (ID, WHOLE_DAY_ENABLED) VALUES (1, 0)");
}

/** Whole-day mode records the lunch departure and afternoon return. Off means half-day attendance. */
function attendanceUsesWholeDay(mysqli $conn): bool
{
    ensureAttendanceTimeOutColumn($conn);
    $settings=$conn->query('SELECT WHOLE_DAY_ENABLED FROM attendance_day_settings WHERE ID=1 LIMIT 1');
    $row=$settings?$settings->fetch_assoc():null;
    return (int)($row['WHOLE_DAY_ENABLED']??0)===1;
}

function setWholeDayAttendance(mysqli $conn, bool $enabled): void
{
    ensureAttendanceTimeOutColumn($conn);
    $value=$enabled?1:0;
    $update=$conn->prepare('INSERT INTO attendance_day_settings (ID, WHOLE_DAY_ENABLED) VALUES (1, ?) ON DUPLICATE KEY UPDATE WHOLE_DAY_ENABLED=VALUES(WHOLE_DAY_ENABLED)');
    $update->bind_param('i',$value);
    $update->execute();
}

const ATTENDANCE_LATE_FROM = '07:21:00';
const ATTENDANCE_ABSENCE_FINALIZE_AT = '23:59:00';
const ATTENDANCE_TIME_OUT_COOLDOWN_SECONDS = 120;
const ATTENDANCE_LUNCH_OUT_FROM = '11:40:00';
const ATTENDANCE_AFTERNOON_TIME_IN_FROM = '12:20:00';

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

/** Returns the next valid automatic attendance event for a daily record. */
function attendanceNextAutomaticAction(?array $record, ?DateTimeImmutable $now = null, bool $wholeDay = false): array
{
    if (!$record) return ['action' => 'time_in', 'message' => 'Time In recorded.', 'can_record' => true];

    $now ??= new DateTimeImmutable('now');
    $clock = $now->format('H:i:s');
    $lunchOut = $record['LUNCH_OUT'] ?? null;
    $afternoonTimeIn = $record['AFTERNOON_TIME_IN'] ?? null;
    $timeOut = $record['TIME_OUT'] ?? null;

    if (!$wholeDay) {
        if ($timeOut) return ['action' => 'complete', 'message' => 'Attendance already completed today.', 'can_record' => false];
        $remaining = attendanceTimeOutCooldownRemaining($record['TIME_IN'] ?? null);
        if ($remaining > 0) return ['action' => 'cooldown', 'message' => attendanceTimeOutCooldownMessage($remaining), 'can_record' => false];
        return ['action' => 'time_out', 'message' => 'Time Out recorded.', 'can_record' => true];
    }

    if (!$lunchOut) {
        if ($clock < ATTENDANCE_LUNCH_OUT_FROM) {
            return ['action' => 'morning_protected', 'message' => 'Lunch Out opens at 11:40 AM.', 'can_record' => false];
        }
        if ($clock < ATTENDANCE_AFTERNOON_TIME_IN_FROM) {
            return ['action' => 'lunch_out', 'message' => 'Lunch Out recorded.', 'can_record' => true];
        }
    }

    if ($lunchOut && !$afternoonTimeIn) {
        if ($clock < ATTENDANCE_AFTERNOON_TIME_IN_FROM) {
            return ['action' => 'waiting_afternoon', 'message' => 'Afternoon Time In opens at 12:20 PM.', 'can_record' => false];
        }
        return ['action' => 'afternoon_time_in', 'message' => 'Afternoon Time In recorded.', 'can_record' => true];
    }

    if ($timeOut) return ['action' => 'complete', 'message' => 'Attendance already completed today.', 'can_record' => false];

    $protectionStart = $afternoonTimeIn ?: ($record['TIME_IN'] ?? null);
    $remaining = attendanceTimeOutCooldownRemaining($protectionStart);
    if ($remaining > 0) return ['action' => 'cooldown', 'message' => attendanceTimeOutCooldownMessage($remaining), 'can_record' => false];

    return ['action' => 'time_out', 'message' => 'Time Out recorded.', 'can_record' => true];
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
    return ['Excused late', 'Excused absence', 'Early dismissal', 'Emergency dismissal'];
}

function attendanceIsAbsent(?string $remark): bool
{
    return $remark === 'Absent';
}

/** Legacy profile views that do not request the remark still recognize a teacher-created absence. */
function attendanceHasManualAbsentTimestamp(string $timeIn, ?string $timeOut = null): bool
{
    return $timeOut === null && str_ends_with($timeIn, '00:00:00');
}

function attendanceStatusLabel(string $timeIn, ?string $timeOut = null, ?string $remark = null, ?string $lunchOut = null, ?string $afternoonTimeIn = null): string
{
    if (attendanceIsAbsent($remark) || ($remark === null && attendanceHasManualAbsentTimestamp($timeIn, $timeOut))) return 'Absent';
    if (in_array($remark, attendanceRemarkOptions(), true)) return $remark;
    $late = attendanceIsLate($timeIn);
    if (!empty($lunchOut) && empty($afternoonTimeIn)) return $late ? 'Late - Lunch out' : 'Lunch out';
    if ($late && !empty($timeOut)) return 'Late · Dismissed';
    if ($late) return 'Late';
    return empty($timeOut) ? 'Present' : 'Dismissed';
}

function attendanceStatusClass(string $timeIn, ?string $timeOut = null, ?string $remark = null, ?string $lunchOut = null, ?string $afternoonTimeIn = null): string
{
    if (attendanceIsAbsent($remark) || ($remark === null && attendanceHasManualAbsentTimestamp($timeIn, $timeOut))) return 'bg-rose-50 text-rose-700';
    if ($remark === 'Excused late' || $remark === 'Excused absence') return 'bg-violet-50 text-violet-700';
    if ($remark === 'Early dismissal') return 'bg-sky-50 text-sky-700';
    if ($remark === 'Emergency dismissal') return 'bg-rose-50 text-rose-700';
    if (!empty($lunchOut) && empty($afternoonTimeIn)) return 'bg-blue-50 text-blue-700';
    if (attendanceIsLate($timeIn)) return 'bg-rose-50 text-rose-700';
    return empty($timeOut) ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700';
}
