<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/school-year-schema.php';
require '../includes/student-placement-options.php';
require '../includes/section-catalog-schema.php';
require '../includes/sectioning.php';

ensureSchoolYearRolloverSchema($conn);
ensureSectionCatalogColumns($conn);
seedDefaultTeacherSections($conn);

function saveRolloverPlanFail(string $message): void
{
    $_SESSION['toast'] = ['message' => $message, 'type' => 'error'];
    header('Location: school-year-rollover.php');
    exit;
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    saveRolloverPlanFail('The rollover request expired. Please try again.');
}

$postedPlans = $_POST['plans'] ?? [];
if (!is_array($postedPlans) || !$postedPlans) {
    saveRolloverPlanFail('Add at least one valid promotion plan.');
}

$validated = normalisePromotionPlans($postedPlans, 'saveRolloverPlanFail');

$conn->begin_transaction();
try {
    // Replace the whole plan so removed split targets do not linger.
    $conn->query('DELETE FROM school_year_promotion_plan');
    $stmt = $conn->prepare(
        'INSERT INTO school_year_promotion_plan (FROM_GRADE,FROM_STRAND,TO_GRADE,TO_STRAND,SPLIT_PERCENT)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE TO_GRADE=VALUES(TO_GRADE), SPLIT_PERCENT=VALUES(SPLIT_PERCENT)',
    );
    foreach ($validated as $row) {
        $stmt->bind_param(
            'isisi',
            $row['FROM_GRADE'],
            $row['FROM_STRAND'],
            $row['TO_GRADE'],
            $row['TO_STRAND'],
            $row['SPLIT_PERCENT'],
        );
        $stmt->execute();
    }
    $conn->commit();
    $_SESSION['toast'] = ['message' => count($validated) . ' promotion plan row' . (count($validated) === 1 ? '' : 's') . ' saved.', 'type' => 'success'];
    header('Location: school-year-rollover-preview.php');
    exit;
} catch (Throwable $error) {
    $conn->rollback();
    $_SESSION['toast'] = ['message' => 'Could not save the promotion plan. No changes were made.', 'type' => 'error'];
}
header('Location: school-year-rollover.php');
exit;
