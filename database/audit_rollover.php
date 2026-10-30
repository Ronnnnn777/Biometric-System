<?php
/** CLI only. SELECT queries only; writes a private local review report, never student data. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__.'/../config/db_conn.php';
require_once __DIR__.'/../includes/promotion-planner.php';
require_once __DIR__.'/../includes/promotion-review.php';
$year = (string)$conn->query('SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1')->fetch_assoc()['ACTIVE_SCHOOL_YEAR'];
$context = promotionContext($conn, $year);
$plan = buildPromotionPlan($context, promotionValidatedTargets(promotionDefaultTargets($context['students'])));
$review = promotionReviewPage($plan);
$teachers = $conn->query("SELECT t.TEACHER_ID,t.TEACHER_NAME,a.GRADE,a.STRAND,a.SECTION,
    (SELECT COUNT(*) FROM current_teacher_subject_assignments x WHERE x.TEACHER_ID=t.TEACHER_ID) SUBJECT_ASSIGNMENTS
    FROM teachers t LEFT JOIN current_teacher_advisory_assignments a ON a.TEACHER_ID=t.TEACHER_ID
    WHERE t.TEACHER_STATUS='Active' ORDER BY t.TEACHER_ID")->fetch_all(MYSQLI_ASSOC);
$coverage = $conn->query("SELECT DISTINCT s.GRADE,s.STRAND,s.SECTION,r.SUBJECT
    FROM students s JOIN promotion_required_subjects r ON r.SCHOOL_YEAR=(SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1)
        AND r.GRADE=s.GRADE AND r.STRAND=s.STRAND
    WHERE s.ENROLLMENT_STATUS='Enrolled' AND s.IS_ARCHIVED=0
      AND NOT EXISTS (SELECT 1 FROM current_teacher_subject_assignments t
        WHERE t.GRADE=s.GRADE AND t.STRAND=s.STRAND AND t.SECTION=s.SECTION AND LOWER(TRIM(t.SUBJECT))=LOWER(TRIM(r.SUBJECT)))
    ORDER BY s.GRADE,s.STRAND,s.SECTION,r.SUBJECT")->fetch_all(MYSQLI_ASSOC);
$duplicateSections = $conn->query('SELECT SCHOOL_YEAR,SECTION_ID,COUNT(*) N FROM teacher_advisory_assignments WHERE RELEASED_AT IS NULL GROUP BY SCHOOL_YEAR,SECTION_ID HAVING N>1')->fetch_all(MYSQLI_ASSOC);
$duplicateTeachers = $conn->query('SELECT SCHOOL_YEAR,TEACHER_ID,COUNT(*) N FROM teacher_advisory_assignments WHERE RELEASED_AT IS NULL GROUP BY SCHOOL_YEAR,TEACHER_ID HAVING N>1')->fetch_all(MYSQLI_ASSOC);
$summary = ['school_year'=>$year, 'students'=>count($plan['students']), 'requiring_review'=>$review['students'],
    'ready'=>$review['ready'], 'missing_subject_records'=>$review['missing'], 'incomplete_subject_records'=>$review['incomplete'],
    'active_teachers'=>count($teachers), 'unassigned_required_class_subjects'=>count($coverage),
    'duplicate_advisory_sections'=>count($duplicateSections), 'duplicate_advisory_teachers'=>count($duplicateTeachers),
    'blocked'=>$plan['blocked']];
$directory = __DIR__.'/../storage/rollover-audit';
if (!is_dir($directory)) mkdir($directory,0700,true);
$report = ['summary'=>$summary,'teachers'=>$teachers,'unassigned_class_subjects'=>$coverage,'students'=>$plan['students'],'reviews'=>$plan['reviews']];
if (file_put_contents($directory.'/review.json',json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX)===false) throw new RuntimeException('Cannot write private audit report.');
echo json_encode($summary,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
echo "Private student/subject details: storage/rollover-audit/review.json\n";
