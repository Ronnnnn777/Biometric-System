<?php
/**
 * Verified biometric scan events.
 *
 * The daily `attendance` table stores only the current state of one school day
 * per student (TIME_IN, LUNCH_OUT, AFTERNOON_TIME_IN, TIME_OUT). It cannot tell
 * us when a scan happened, which event it produced, from which scanner it came,
 * or whether an attempt was rejected. This table records that append-only
 * history.
 *
 * Design rules (see SY.md, Modification B/C/D):
 *  - Additive only. Existing attendance rows are never altered.
 *  - Historical attendance is NOT evidence of a biometric scan. Rows created
 *    before this feature shipped are simply absent here, so the UI must never
 *    present old attendance as verified scanner activity.
 *  - An unidentified face must never be attached to a student id. Those rows
 *    keep STUDENT_ID = NULL.
 *  - No facial images or biometric templates are stored here.
 *  - Unsuccessful attempts are de-duplicated and pruned so continuous failed
 *    recognition frames cannot flood the table.
 */

const ATTENDANCE_SCAN_RESULT_SUCCESS = 'success';

/** Allowed scan results. */
const ATTENDANCE_SCAN_RESULTS = [
    'success',
    'rejected',
    'ignored',
    'cooldown',
    'duplicate',
    'ambiguous',
    'unrecognized',
];

/** Allowed event types. `ignored`/`unrecognized` carry no attendance write. */
const ATTENDANCE_SCAN_EVENT_TYPES = [
    'time_in',
    'lunch_out',
    'afternoon_time_in',
    'time_out',
    'ignored',
    'unrecognized',
];

/** Created lazily so the database self-upgrades on first use. */
function ensureAttendanceScanEventsSchema(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS attendance_scan_events (
            ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ATTENDANCE_ID INT UNSIGNED NULL,
            STUDENT_ID VARCHAR(50) NULL,
            EVENT_TYPE VARCHAR(20) NOT NULL,
            SCAN_RESULT VARCHAR(20) NOT NULL,
            SCANNED_AT DATETIME NOT NULL,
            SCANNER_SOURCE VARCHAR(20) NOT NULL DEFAULT '',
            SCANNER_ID VARCHAR(60) NOT NULL DEFAULT '',
            SCANNER_NAME VARCHAR(80) NOT NULL DEFAULT '',
            DETAIL VARCHAR(120) NOT NULL DEFAULT '',
            GRADE_SNAPSHOT TINYINT UNSIGNED NULL,
            STRAND_SNAPSHOT VARCHAR(20) NOT NULL DEFAULT '',
            SECTION_SNAPSHOT VARCHAR(80) NOT NULL DEFAULT '',
            CREATED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX IX_SCAN_EVENTS_STUDENT_TIME (STUDENT_ID, SCANNED_AT),
            INDEX IX_SCAN_EVENTS_TIME (SCANNED_AT),
            INDEX IX_SCAN_EVENTS_RESULT_TIME (SCAN_RESULT, SCANNED_AT),
            INDEX IX_SCAN_EVENTS_SECTION (GRADE_SNAPSHOT, SECTION_SNAPSHOT, SCANNED_AT),
            INDEX IX_SCAN_EVENTS_ATTENDANCE (ATTENDANCE_ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    );
}

/**
 * Append one scan event. Returns true when a row was written.
 *
 * Never throws: scan-event logging must not break the recognition workflow.
 * When `dedupe_seconds` is supplied, an identical (student, event, result,
 * scanner) event inside that window is skipped, so a stream of rejected frames
 * cannot flood the table.
 *
 * @param array{
 *   attendance_id?:int|null, student_id?:string|null, event_type?:string,
 *   result?:string, scanner_source?:string, scanner_id?:string,
 *   scanner_name?:string, detail?:string, grade?:int|null, strand?:string,
 *   section?:string, dedupe_seconds?:int
 * } $event
 */
function logAttendanceScanEvent(mysqli $conn, array $event): bool
{
    try {
        $eventType = in_array((string) ($event['event_type'] ?? ''), ATTENDANCE_SCAN_EVENT_TYPES, true)
            ? (string) $event['event_type']
            : 'ignored';
        $result = in_array((string) ($event['result'] ?? ''), ATTENDANCE_SCAN_RESULTS, true)
            ? (string) $event['result']
            : 'ignored';
        $studentId = ($event['student_id'] ?? null) !== null ? (string) $event['student_id'] : null;
        $attendanceId = ($event['attendance_id'] ?? null) !== null ? (int) $event['attendance_id'] : null;
        $scannerId = (string) ($event['scanner_id'] ?? '');
        $dedupe = (int) ($event['dedupe_seconds'] ?? 0);

        if ($dedupe > 0) {
            $recent = $conn->prepare(
                "SELECT ID FROM attendance_scan_events
                  WHERE SCANNED_AT > (NOW() - INTERVAL ? SECOND)
                    AND SCAN_RESULT = ? AND EVENT_TYPE = ? AND SCANNER_ID = ?
                    AND (STUDENT_ID <=> ?)
                  LIMIT 1",
            );
            $recent->bind_param('issss', $dedupe, $result, $eventType, $scannerId, $studentId);
            $recent->execute();
            if ($recent->get_result()->num_rows > 0) {
                return false;
            }
        }

        $stmt = $conn->prepare(
            "INSERT INTO attendance_scan_events
                (ATTENDANCE_ID, STUDENT_ID, EVENT_TYPE, SCAN_RESULT, SCANNED_AT,
                 SCANNER_SOURCE, SCANNER_ID, SCANNER_NAME, DETAIL,
                 GRADE_SNAPSHOT, STRAND_SNAPSHOT, SECTION_SNAPSHOT)
             VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)",
        );
        $source = (string) ($event['scanner_source'] ?? 'unknown');
        $scannerName = (string) ($event['scanner_name'] ?? '');
        $detail = mb_substr((string) ($event['detail'] ?? ''), 0, 120);
        $grade = ($event['grade'] ?? null) !== null ? (int) $event['grade'] : null;
        $strand = (string) ($event['strand'] ?? '');
        $section = (string) ($event['section'] ?? '');
        $stmt->bind_param(
            'isssssssiss',
            $attendanceId,
            $studentId,
            $eventType,
            $result,
            $source,
            $scannerId,
            $scannerName,
            $detail,
            $grade,
            $strand,
            $section,
        );
        return $stmt->execute() === true;
    } catch (Throwable $error) {
        error_log('attendance_scan_event_log_failed: ' . $error->getMessage());
        return false;
    }
}

/**
 * Retention for unsuccessful attempts only. Successful scans are the audit
 * trail and are kept indefinitely; rejected/ignored/unrecognized noise is
 * trimmed after $keepDays. Cheap and bounded, so it can run opportunistically.
 */
function pruneAttendanceScanEvents(mysqli $conn, int $keepDays = 30, int $batch = 500): void
{
    try {
        $keepDays = max(1, $keepDays);
        $batch = max(1, $batch);
        $conn->query(
            "DELETE FROM attendance_scan_events
              WHERE SCAN_RESULT <> 'success'
                AND SCANNED_AT < (NOW() - INTERVAL {$keepDays} DAY)
              LIMIT {$batch}",
        );
    } catch (Throwable $error) {
        error_log('attendance_scan_event_prune_failed: ' . $error->getMessage());
    }
}

/**
 * First date (Y-m-d) for which verified scan events exist, or null when the
 * table is still empty. Used to explain why history looks incomplete.
 */
function attendanceScanEventsActivationDate(mysqli $conn): ?string
{
    $result = $conn->query('SELECT MIN(SCANNED_AT) AS first_scan FROM attendance_scan_events');
    $row = $result ? $result->fetch_assoc() : null;
    $first = trim((string) ($row['first_scan'] ?? ''));
    return $first !== '' ? substr($first, 0, 10) : null;
}

/** Human label for a scanner source value. Unknown sources never invent a name. */
function attendanceScannerSourceLabel(string $source, string $scannerName = ''): string
{
    return match ($source) {
        'webcam' => 'Webcam',
        'mobile' => $scannerName !== '' ? $scannerName : 'Mobile scanner',
        'manual' => 'Manual entry',
        'legacy' => 'Legacy record',
        default => 'Unknown scanner',
    };
}
