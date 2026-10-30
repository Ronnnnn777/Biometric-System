<?php
// Included only by the authenticated rollover preview.
if (!isset($plan, $controls, $escape)) { http_response_code(404); exit; }
$reviewGrade = (int)($_GET['review_grade'] ?? 0);
$reviewSection = is_string($_GET['review_section'] ?? null) ? $_GET['review_section'] : '';
$review = promotionReviewPage($plan, $reviewGrade, $reviewSection, (int)($_GET['review_page'] ?? 1));
$reviewUrl = static fn(int $page): string => 'school-year-rollover.php?'.http_build_query([
    'review_grade'=>$reviewGrade, 'review_section'=>$reviewSection, 'review_page'=>$page, 'plans'=>$controls,
]).'#grade-review';
?>
<div id="grade-review" class="mt-5">
    <p role="alert" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"><strong>Review required — rollover is blocked.</strong> Complete the records below and resolve placement reviews before confirming.</p>
    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <?php foreach (['review-students'=>[$review['students'],'Students requiring review'], 'ready-students'=>[$review['ready'],'Ready for promotion / graduation'], 'missing-records'=>[$review['missing'],'Missing subject records'], 'incomplete-records'=>[$review['incomplete'],'Incomplete subject records']] as $metric=>$item): ?>
        <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-700"><p class="text-xs text-slate-500"><?= $escape($item[1]) ?></p><p data-summary="<?= $metric ?>" class="mt-1 text-xl font-black"><?= (int)$item[0] ?></p></div>
        <?php endforeach; ?>
    </div>
    <p class="mt-2 text-xs text-slate-500">Counts cover the entire school year. Missing records have no subject row; incomplete records have missing or invalid quarterly/final grades. Unconfigured curricula cannot be counted as missing subjects. Honor capacity reviews may still prevent placement of academically eligible students.</p>
    <form method="get" action="school-year-rollover.php#grade-review" class="mt-4 flex flex-wrap items-end gap-3">
        <?php foreach ($controls as $index=>$source): ?>
        <input type="hidden" name="plans[<?= $index ?>][from_grade]" value="<?= (int)$source['from_grade'] ?>">
        <input type="hidden" name="plans[<?= $index ?>][from_strand]" value="<?= $escape($source['from_strand']) ?>">
        <?php foreach ($source['targets'] as $targetIndex=>$target): ?>
        <input type="hidden" name="plans[<?= $index ?>][targets][<?= $targetIndex ?>][strand]" value="<?= $escape($target['strand']) ?>">
        <input type="hidden" name="plans[<?= $index ?>][targets][<?= $targetIndex ?>][percent]" value="<?= (int)$target['percent'] ?>">
        <?php endforeach; endforeach; ?>
        <label class="text-sm font-bold">Grade<select name="review_grade" class="field mt-1"><option value="0">All grades</option><?php foreach ($review['grades'] as $option): ?><option value="<?= $option ?>" <?= $reviewGrade===$option?'selected':'' ?>>Grade <?= $option ?></option><?php endforeach; ?></select></label>
        <label class="text-sm font-bold">Section<select name="review_section" class="field mt-1"><option value="">All sections</option><?php foreach ($review['sections'] as $key=>$label): ?><option value="<?= $escape($key) ?>" <?= $reviewSection===$key?'selected':'' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label>
        <button class="btn btn-secondary">Filter review</button>
    </form>
    <div class="mt-3 overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-700">
        <table class="w-full text-left text-sm" data-review-table>
            <caption class="p-3 text-left text-xs text-slate-500">Subject teachers enter quarterly grades using their own accounts. Administrators assign subjects through Teachers. Advisory ownership alone does not grant grading access.</caption>
            <thead><tr class="border-b border-slate-200 text-slate-500"><th class="p-3">Student / LRN</th><th class="p-3">Grade / section</th><th class="p-3">Missing subjects / review</th><th class="p-3">Action</th></tr></thead>
            <tbody>
            <?php foreach ($review['rows'] as $id=>$record): $student=$record['student']; ?>
            <tr data-review-student="<?= $escape($id) ?>" class="border-b border-slate-100 align-top dark:border-slate-800">
                <td class="p-3"><strong><?= $escape($student['STUDENT_NAME']) ?></strong><span class="block text-xs text-slate-500"><?= $escape($id) ?></span></td>
                <td class="p-3">Grade <?= (int)$student['GRADE'] ?><span class="block"><?= $escape(trim($student['STRAND'].' '.$student['SECTION'])) ?></span></td>
                <td class="p-3"><?php if ($record['standing']['missing_subjects']): ?><p><strong>Missing:</strong> <?= $escape(implode(', ',$record['standing']['missing_subjects'])) ?></p><?php endif; ?>
                    <?php foreach ($record['standing']['issues'] as $issue): if (str_starts_with($issue,'Missing subject: ')) continue; ?><p class="mt-1"><?= $escape($issue) ?></p><?php endforeach; ?>
                    <?php foreach ($record['reasons'] as $reason): if ($reason===implode(' ',$record['standing']['issues'])) continue; ?><p class="mt-1"><?= $escape($reason) ?></p><?php endforeach; ?>
                </td>
                <td class="p-3"><a class="font-bold text-blue-700 dark:text-blue-400" target="_blank" rel="noopener" href="../teacher/grading.php?<?= $escape(http_build_query(['grade'=>$student['GRADE'],'section'=>$student['SECTION']])) ?>">Open teacher grading</a><span class="mt-1 block text-xs text-slate-500">Subject teacher sign-in required</span><a class="mt-2 block text-blue-700 dark:text-blue-400" href="teacher-list.php">Manage teaching assignments</a></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$review['rows']): ?><tr><td colspan="4" class="p-4 text-slate-500">No students match these review filters. The complete plan still controls rollover validation.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <nav aria-label="Grade review pages" class="mt-3 flex flex-wrap items-center justify-between gap-3 text-sm">
        <span><?= $review['total'] ?> matching students · Page <?= $review['page'] ?> of <?= $review['pages'] ?> · 15 per page</span>
        <div class="flex gap-3"><?php if ($review['page']>1): ?><a class="btn btn-secondary" href="<?= $escape($reviewUrl($review['page']-1)) ?>">Previous</a><?php endif; ?><?php if ($review['page']<$review['pages']): ?><a class="btn btn-secondary" href="<?= $escape($reviewUrl($review['page']+1)) ?>">Next</a><?php endif; ?></div>
    </nav>
    <?php if ($review['other']): ?><div class="mt-3 rounded-xl border border-amber-200 p-3 text-sm"><strong>Advisory and placement reviews</strong><?php foreach ($review['other'] as $item): ?><p class="mt-1"><?= $escape($item['name'].': '.$item['reason']) ?></p><?php endforeach; ?></div><?php endif; ?>
</div>
