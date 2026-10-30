<?php
/** CLI only. Back up first. Idempotent view migration; existing assignments are preserved. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__.'/../config/db_conn.php';
require_once __DIR__.'/../includes/teacher-access-schema.php';
require_once __DIR__.'/../includes/promotion-planner.php';

$year=activeTeacherSchoolYear($conn);
$context=promotionContext($conn,$year);
$assignments=$context['assignments'];
$audit=teacherAssignmentDiagnostics($assignments['teachers'],$assignments['advisories'],$assignments['subjects'],$context['sections'],$context['students'],$context['required']);
if ($audit['advisory_reviews']) throw new RuntimeException('Resolve the recorded advisory ownership reviews explicitly before migrating. No assignments were inferred from subjects.');
foreach (['uq_adviser_year','uq_section_year'] as $name) {
    $index=$conn->query("SHOW INDEX FROM teacher_advisory_assignments WHERE Key_name='$name'")->fetch_all(MYSQLI_ASSOC);
    if (count($index)!==2 || (int)$index[0]['Non_unique']!==0) throw new RuntimeException('The advisory year-specific unique constraints must be installed before migrating.');
}
// DDL has an implicit commit in MariaDB, so do not wrap the view migration in a data transaction.
installTeacherAdvisoryView($conn);
echo "Advisory ownership view upgraded for $year. Verified {$audit['active_teachers']} active teachers and {$audit['active_sections']} unique active advisory sections.\n";
echo "No teacher, password, assignment, student, grade, attendance or history rows changed. Re-running this migration is safe.\n";
