<?php
require 'page-guard.php';
require_once '../config/db_conn.php';
require_once '../includes/csrf-token.php';
require_once '../includes/promotion-planner.php';
require_once '../includes/promotion-review.php';
require 'components.php';
$activeSchoolYear=(string)$conn->query('SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1')->fetch_assoc()['ACTIVE_SCHOOL_YEAR'];
$plan=null; $context=null; $controls=[]; $error='';
try {
    $context=promotionContext($conn,$activeSchoolYear);
    $saved=$conn->query('SELECT * FROM school_year_promotion_plan ORDER BY FROM_GRADE,FROM_STRAND,TO_STRAND')->fetch_all(MYSQLI_ASSOC);
    $controls=promotionDefaultTargets($context['students'],$saved);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],(string)($_POST['csrf_token'] ?? ''))) throw new RuntimeException('The preview request expired. Refresh this page.');
        if (!is_array($_POST['plans'] ?? null)) throw new RuntimeException('Choose valid promotion targets.');
        promotionValidatedTargets($_POST['plans']);
        $controls=$_POST['plans'];
    } elseif (isset($_GET['plans'])) {
        if (!is_array($_GET['plans'])) throw new RuntimeException('Choose valid promotion targets.');
        promotionValidatedTargets($_GET['plans']);
        $controls=$_GET['plans'];
    }
    $plan=buildPromotionPlan($context,promotionValidatedTargets($controls));
} catch (mysqli_sql_exception $exception) { $error='Promotion setup is unavailable. Run database/migrate_promotion_policy.php before using rollover.'; }
catch (RuntimeException $exception) { $error=$exception->getMessage(); }
$pastRolloverYears=$conn->query('SELECT DISTINCT SCHOOL_YEAR FROM student_school_history ORDER BY SCHOOL_YEAR DESC')->fetch_all(MYSQLI_ASSOC);
$summary=$plan['summary'] ?? [];
adminHead('School year rollover');
adminSidebar('rollover','End-of-school-year rollover','Review completed grades, honor qualification and balanced section assignments before confirming.');
$escape=static fn($value): string=>htmlspecialchars((string)$value,ENT_QUOTES);
?>
<section class="card card-body mx-auto max-w-5xl">
    <h2 class="text-2xl font-black text-slate-900 dark:text-white">End-of-School-Year Rollover</h2>
    <p class="mt-2 text-sm text-slate-500">SY <?= $escape($activeSchoolYear) ?>. Only completed grades enter promotion. Honor students need an unrounded general average of at least 90. Regular students are balanced within each section's capacity. Academic and Techpro remain separate.</p>
    <?php if ($error): ?><p class="mt-4 rounded-xl bg-rose-50 p-4 font-bold text-rose-700" role="alert"><?= $escape($error) ?></p><?php endif; ?>
    <?php if ($plan): ?>
    <?php $assignmentAudit=$plan['assignment_diagnostics']; ?>
    <div class="mt-4 rounded-xl border border-slate-200 p-3 text-sm dark:border-slate-700" data-assignment-diagnostics>
        <p><strong>Advisory ownership:</strong> <?= (int)$assignmentAudit['assigned_teachers'] ?> / <?= (int)$assignmentAudit['active_teachers'] ?> active teachers assigned; <?= count($assignmentAudit['advisory_reviews']) ?> ownership reviews. Subjects do not establish advisory ownership.</p>
        <p class="mt-2"><strong>Subject teaching coverage:</strong> <?= count($assignmentAudit['missing_subject_assignments']) ?> required class/subject combinations have no subject teacher. This does not invalidate advisers or prove that student grades are missing.</p>
        <p class="mt-2"><strong>Student academic records:</strong> <?= (int)$summary['incomplete'] ?> students have an incomplete final general average. Missing subjects and quarterly grades below are checked against actual saved records.</p>
        <p class="mt-2 text-slate-500">Rollover retains adviser accounts and copies advisories to the same grade and section in the new school year. Existing new-year choices take precedence; administrators can change advisers through Teachers. Student promotion never promotes teachers.</p>
        <?php if ($assignmentAudit['missing_subject_assignments']): ?><details class="mt-2"><summary class="cursor-pointer font-bold">Review teaching coverage (independent of student eligibility)</summary><ul class="mt-2 text-xs text-slate-500"><?php foreach ($assignmentAudit['missing_subject_assignments'] as $gap): ?><li>Grade <?= (int)$gap['grade'] ?> <?= $escape($gap['strand'].' / '.$gap['section'].' / '.$gap['subject']) ?></li><?php endforeach; ?></ul><a class="mt-2 inline-block font-bold text-blue-700" href="teacher-list.php">Manage teachers and assignments</a></details><?php endif; ?>
    </div>
    <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <?php foreach (['promoted'=>'Eligible for promotion','honor'=>'Honor qualified','regular'=>'Regular students','incomplete'=>'Incomplete grades','retained'=>'Retained','summer'=>'Summer class','graduated'=>'Eligible Grade 12 graduates'] as $key=>$label): ?>
        <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-700"><p class="text-xs text-slate-500"><?= $escape($label) ?></p><p data-summary="<?= $key ?>" class="mt-1 text-xl font-black text-slate-900 dark:text-white"><?= (int)$summary[$key] ?></p></div>
        <?php endforeach; ?>
    </div>
    <?php if ($plan['reviews']): ?>
    <?php require __DIR__.'/rollover-review.php'; ?>
    <?php endif; endif; ?>
    <form id="rollover-form" action="run-school-year-rollover.php" method="post" class="mt-6">
        <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="expected_school_year" value="<?= $escape($activeSchoolYear) ?>">
        <input type="hidden" name="preview_fingerprint" value="<?= $escape($plan['fingerprint'] ?? '') ?>">
        <div class="space-y-3">
        <?php foreach ($controls as $index=>$source): ?>
            <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                <p class="font-bold text-slate-900 dark:text-white">Grade <?= (int)$source['from_grade'] ?> <?= $escape($source['from_strand']) ?> → Grade <?= (int)$source['from_grade']+1 ?></p>
                <input type="hidden" name="plans[<?= $index ?>][from_grade]" value="<?= (int)$source['from_grade'] ?>">
                <input type="hidden" name="plans[<?= $index ?>][from_strand]" value="<?= $escape($source['from_strand']) ?>">
                <?php foreach ($source['targets'] as $targetIndex=>$target): ?>
                <input type="hidden" name="plans[<?= $index ?>][targets][<?= $targetIndex ?>][strand]" value="<?= $escape($target['strand']) ?>">
                <?php if ((int)$source['from_grade']===10): ?>
                <label class="mt-2 inline-flex items-center gap-2 text-sm font-bold text-slate-700 dark:text-slate-300"><?= $escape($target['strand']) ?><input type="number" min="0" max="100" name="plans[<?= $index ?>][targets][<?= $targetIndex ?>][percent]" value="<?= (int)$target['percent'] ?>" class="w-20 rounded-lg border border-slate-300 bg-white px-2 py-1.5 dark:border-slate-700 dark:bg-slate-900">%</label>
                <?php else: ?><input type="hidden" name="plans[<?= $index ?>][targets][<?= $targetIndex ?>][percent]" value="100"><?php endif; endforeach; ?>
            </div>
        <?php endforeach; ?>
        </div>
        <button type="submit" formaction="school-year-rollover.php" formnovalidate class="btn btn-secondary mt-4">Refresh preview</button>
        <p id="preview-status" class="mt-2 text-sm text-slate-500">This preview does not create sections or change student assignments. Changing strand percentages requires a refreshed preview.</p>
        <?php if ($plan): ?>
        <div class="mt-5 overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-700">
            <table class="w-full text-left text-sm"><thead><tr class="border-b border-slate-200 text-slate-500"><th class="px-4 py-3">Destination</th><th class="px-4 py-3">Section</th><th class="px-4 py-3">Classification</th><th class="px-4 py-3">Incoming</th><th class="px-4 py-3">Staying</th><th class="px-4 py-3">Total / capacity</th></tr></thead><tbody>
            <?php foreach ($plan['destinations'] as $destination): foreach ($destination['groups'] as $group): $section=$group['section']; $incoming=count($group['students']); $staying=(int)$section['EXISTING_COUNT']; ?>
            <tr class="border-b border-slate-100 dark:border-slate-800"><td class="px-4 py-3">Grade <?= $destination['grade'] ?> <?= $escape($destination['strand']) ?></td><td class="px-4 py-3 font-bold"><?= $escape($section['SECTION']) ?><?php if (!empty($section['VIRTUAL'])): ?><span class="ml-1 text-xs font-bold text-amber-700">New regular overflow</span><?php endif; ?></td><td class="px-4 py-3"><?= $section['IS_HONOR'] ? 'Honor (90+)' : 'Regular' ?></td><td class="px-4 py-3"><?= $incoming ?></td><td class="px-4 py-3"><?= $staying ?></td><td class="px-4 py-3"><?= $incoming+$staying ?> / <?= sectionCapacity($section,$context['default']) ?></td></tr>
            <?php endforeach; endforeach; ?>
            </tbody></table>
        </div>
        <details class="mt-4 rounded-xl border border-slate-200 p-4 dark:border-slate-700"><summary class="cursor-pointer font-bold">Review individual promotion decisions and general averages</summary><div class="mt-3 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-2">Student / LRN</th><th class="p-2">General average</th><th class="p-2">Decision</th><th class="p-2">Destination</th></tr></thead><tbody>
        <?php foreach ($plan['students'] as $id=>$record): ?><tr class="border-t border-slate-100 dark:border-slate-800"><td class="p-2"><?= $escape($record['student']['STUDENT_NAME']) ?> (<?= $escape($id) ?>)</td><td class="p-2"><?= $record['standing']['average']===null ? 'Incomplete' : $escape(rtrim(rtrim(number_format($record['standing']['average'],6,'.',''),'0'),'.')) ?></td><td class="p-2"><?= $escape($record['standing']['decision']) ?></td><td class="p-2"><?= $record['placement'] ? $escape($record['placement']['SECTION'].' '.$record['placement']['STRAND']) : 'No section change' ?></td></tr><?php endforeach; ?>
        </tbody></table></div></details>
        <?php endif; ?>
        <div class="mt-6 rounded-xl border border-rose-200 p-4">
            <p class="text-sm text-slate-500">Confirm only after reviewing this plan. Overflow sections are regular sections. Incomplete grades and honor-capacity conflicts must be resolved first.</p>
            <label class="mt-3 flex items-center gap-2 text-sm font-bold"><input type="checkbox" name="end_school_year_confirmation" value="1" required> The school year has ended and I have reviewed the preview.</label>
            <label class="mt-4 block text-sm font-bold">Administrator password<input type="password" name="current_password" autocomplete="current-password" required class="mt-2 block w-full max-w-sm rounded-xl border border-slate-300 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900"></label>
            <button data-run-rollover type="submit" <?= !$plan || $plan['blocked'] ? 'disabled' : '' ?> class="btn btn-danger mt-4 disabled:cursor-not-allowed disabled:opacity-50">Promote &amp; Auto-Section Students</button>
        </div>
    </form>
</section>
<?php if ($context): ?>
<details class="card card-body mx-auto mt-6 max-w-5xl"><summary class="cursor-pointer font-bold">Promotion settings: required subjects and honor designation</summary>
    <p class="mt-3 text-sm text-slate-500">Junior high starts with the eight subjects already used by the system. Configure the exact subject list for each senior-high grade and strand. A missing subject, missing quarter, or inconsistent final result blocks promotion. Honor thresholds use equal-weight quarterly subject averages without qualifying round-up.</p>
    <ul class="mt-3 text-sm text-slate-500"><?php $curricula=[]; foreach ($context['required'] as $required) $curricula[$required['GRADE'].' '.$required['STRAND']][]=$required['SUBJECT']; foreach ($curricula as $label=>$subjects): ?><li class="mt-2"><strong>Grade <?= $escape($label) ?>:</strong> <?= $escape(implode(', ',$subjects)) ?></li><?php endforeach; ?></ul>
    <form action="save-promotion-policy.php" method="post" class="mt-4 space-y-3">
        <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>"><input type="hidden" name="expected_school_year" value="<?= $escape($activeSchoolYear) ?>"><input type="hidden" name="action" value="subjects">
        <label class="block text-sm font-bold">Grade and strand<select name="placement" class="mt-2 block rounded-xl border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-900"><?php foreach ([7,8,9,10,11,12] as $grade): foreach ($grade>=11 ? ['Academic','Techpro'] : [''] as $strand): ?><option value="<?= $escape($grade.'|'.$strand) ?>">Grade <?= $grade ?> <?= $escape($strand) ?></option><?php endforeach; endforeach; ?></select></label>
        <label class="block text-sm font-bold">Required subjects (one per line)<textarea name="subjects" rows="5" maxlength="3200" required class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-900"></textarea></label><button class="btn btn-secondary">Save required subjects</button>
    </form>
    <form action="save-promotion-policy.php" method="post" class="mt-6 space-y-3"><input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>"><input type="hidden" name="expected_school_year" value="<?= $escape($activeSchoolYear) ?>"><input type="hidden" name="action" value="honor">
        <p class="text-sm text-slate-500">One explicitly designated honor section per grade/strand. Junior-high designations: Amethyst, Peace, Aristotle. Senior high has no honor designation unless an administrator chooses one.</p>
        <label class="block text-sm font-bold">Honor section<select name="honor_section_id" class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-900"><?php foreach ($context['sections'] as $section): if (!(int)$section['IS_ACTIVE']) continue; ?><option value="<?= (int)$section['SECTION_ID'] ?>">Grade <?= (int)$section['GRADE'] ?> <?= $escape($section['STRAND'].' | '.$section['SECTION']) ?></option><?php endforeach; ?></select></label><button class="btn btn-secondary">Confirm honor designation</button>
    </form>
</details>
<?php endif; ?>
<?php if ($pastRolloverYears): ?>
<details class="card card-body mx-auto mt-6 max-w-5xl"><summary class="cursor-pointer font-bold">Undo the latest rollover</summary><p class="mt-3 text-sm text-slate-500">Restores the previous year's saved placements. Grades and audit history remain preserved.</p><form action="undo-school-year-rollover.php" method="post" class="mt-4 space-y-3"><input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>"><input type="hidden" name="expected_school_year" value="<?= $escape($activeSchoolYear) ?>"><label class="block text-sm font-bold">Administrator password<input type="password" name="current_password" required autocomplete="current-password" class="mt-2 block w-full max-w-sm rounded-xl border border-slate-300 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900"></label><button class="btn btn-secondary">Undo rollover</button></form></details>
<?php endif; ?>
<script>
document.querySelectorAll('#rollover-form input[name^="plans"]').forEach(input => {
    input.addEventListener('input', () => {
        document.querySelector('[data-run-rollover]').disabled = true;
        document.getElementById('preview-status').textContent = 'Targets changed. Refresh and review the preview before confirming.';
    });
});
</script>
<?php adminFoot(); ?>
