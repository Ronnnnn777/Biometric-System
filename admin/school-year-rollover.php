<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/csrf-token.php';
require '../includes/student-placement-options.php';
require '../includes/section-catalog-schema.php';
require '../includes/school-year-schema.php';
require '../includes/sectioning.php';
require 'components.php';

ensureSectionCatalogColumns($conn);
seedDefaultTeacherSections($conn);
ensureSchoolYearRolloverSchema($conn);

$setting=$conn->query('SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1')->fetch_assoc();
$activeSchoolYear=(string)($setting['ACTIVE_SCHOOL_YEAR']??'');
$nextSchoolYear='';
if(preg_match('/^(\d{4})-(\d{4})$/',$activeSchoolYear,$years)) $nextSchoolYear=((int)$years[1]+1).'-'.((int)$years[2]+1);

$defaultCapacity=defaultSectionCapacity($conn);
$planRows=$conn->query('SELECT FROM_GRADE,FROM_STRAND,TO_GRADE,TO_STRAND,SPLIT_PERCENT FROM school_year_promotion_plan ORDER BY FROM_GRADE,FROM_STRAND,TO_STRAND');
$savedPlans=[];
while($planRows&&($plan=$planRows->fetch_assoc())) $savedPlans[$plan['FROM_GRADE'].'|'.$plan['FROM_STRAND']][]=$plan;

$sources=[];
$sourceRows=$conn->query("SELECT GRADE,STRAND,COUNT(*) STUDENTS FROM students WHERE ENROLLMENT_STATUS='Enrolled' AND GRADE BETWEEN 7 AND 11 GROUP BY GRADE,STRAND ORDER BY GRADE,STRAND");
while($sourceRows&&($row=$sourceRows->fetch_assoc())){
    $fromGrade=(int)$row['GRADE'];
    $fromStrand=(string)$row['STRAND'];
    $toGrade=$fromGrade+1;
    $studentCount=(int)$row['STUDENTS'];
    $targetStrands=studentStrandsForGrade($toGrade);
    $saved=$savedPlans[$fromGrade.'|'.$fromStrand]??[];

    // Target percentages per strand. Grade 10 -> 11 can split across both strands.
    $targets=[];
    if($targetStrands){
        if($fromGrade===10){
            // Split-capable source: allow both strands with editable percentages.
            foreach($saved as $plan){
                $strand=(string)$plan['TO_STRAND'];
                if(in_array($strand,$targetStrands,true)) $targets[$strand]=(int)$plan['SPLIT_PERCENT'];
            }
            if(!$targets) $targets['Academic']=100;
            foreach($targetStrands as $strand) $targets[$strand]=$targets[$strand]??0;
        }else{
            // Grade 11 -> 12 carries its strand; only one target is possible.
            $carry=in_array($fromStrand,$targetStrands,true)?$fromStrand:$targetStrands[0];
            foreach($saved as $plan){
                $strand=(string)$plan['TO_STRAND'];
                if(in_array($strand,$targetStrands,true)){ $carry=$strand; break; }
            }
            $targets[$carry]=100;
        }
    }else{
        $targets['']=100;
    }

    $chunks=splitStudentsByWeights($studentCount>0?range(1,$studentCount):[],array_values($targets));
    $targetDetails=[];
    $targetIndex=0;
    foreach($targets as $strand=>$percent){
        $targetSections=orderedTargetSections($conn,$toGrade,$strand);
        $capacity=0;foreach($targetSections as $section)$capacity+=sectionCapacity($section,$defaultCapacity);
        $chunk=$chunks[$targetIndex]??[];
        $targetDetails[]=[
            'strand'=>$strand,
            'percent'=>(int)$percent,
            'capacity'=>$capacity,
            'section_count'=>count($targetSections),
            'projected'=>sectionPlanForBatch($chunk,$targetSections,$toGrade,$strand,$defaultCapacity),
            'count'=>count($chunk),
        ];
        $targetIndex++;
    }
    $sources[]=[
        'from_grade'=>$fromGrade,
        'from_strand'=>$fromStrand,
        'to_grade'=>$toGrade,
        'students'=>$studentCount,
        'is_split_grade'=>$fromGrade===10,
        'targets'=>$targetDetails,
    ];
}

$pastRolloverYears=[];
$pastYears=$conn->prepare('SELECT SCHOOL_YEAR,COUNT(*) STUDENT_COUNT FROM student_school_history WHERE SCHOOL_YEAR < ? GROUP BY SCHOOL_YEAR ORDER BY SCHOOL_YEAR DESC');
$pastYears->bind_param('s',$activeSchoolYear);
$pastYears->execute();
$pastYearRows=$pastYears->get_result();
while($pastYearRows&&$pastYear=$pastYearRows->fetch_assoc()) $pastRolloverYears[]=$pastYear;
$canUndo=!empty($pastRolloverYears);
adminHead('School year rollover');
adminSidebar('rollover','End-of-school-year rollover','Promote students grade by grade and auto-section them at a maximum of '.$defaultCapacity.' students per section.');
?>
<section class="card card-body mx-auto max-w-5xl">
    <h2 class="text-2xl font-black text-slate-900">End-of-School-Year Rollover</h2>
    <p class="mt-2 text-slate-500">Each source grade is promoted to the next grade. Students are ordered by current
        section and name, then placed <strong><?= (int) $defaultCapacity ?> students per section</strong>. Extra sections
        are created automatically when a batch does not fit. Grade 10 can be split across Academic and Techpro.</p>
    <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        <strong>Important:</strong> Use this only when the school year has ended. Grade 12 students stay enrolled until
        the final end-of-year confirmation, then they are archived as Graduated. Review the projected sections first.
    </div>

    <form id="rollover-form" action="run-school-year-rollover.php" method="post" class="mt-7">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input id="rollover-password" type="hidden" name="current_password">
        <input id="rollover-end-confirmation" type="hidden" name="end_school_year_confirmation">
        <div class="w-full overflow-x-auto">
            <table class="w-full min-w-full text-left text-sm">
                <thead class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Current class</th>
                        <th class="px-4 py-3">Students</th>
                        <th class="px-4 py-3">Promote to</th>
                        <th class="px-4 py-3">Projected sections</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if(!$sources): ?>
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-slate-500">No enrolled students in Grades 7–11
                            are available for promotion. Enrolled Grade 12 students will still be graduated.</td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach($sources as $index=>$source): ?>
                    <tr>
                        <td class="px-4 py-4 font-bold text-slate-900">
                            <input type="hidden" name="plans[<?= $index ?>][from_grade]" value="<?= (int)$source['from_grade'] ?>">
                            <input type="hidden" name="plans[<?= $index ?>][from_strand]" value="<?= htmlspecialchars($source['from_strand']) ?>">
                            Grade <?= (int)$source['from_grade'] ?><?= $source['from_strand']?' · '.htmlspecialchars($source['from_strand']):'' ?>
                        </td>
                        <td class="px-4 py-4 text-slate-600"><?= (int)$source['students'] ?></td>
                        <td class="px-4 py-4">
                            <p class="font-bold text-slate-900">Grade <?= (int)$source['to_grade'] ?></p>
                            <?php if($source['is_split_grade']): ?>
                            <p class="mt-1 text-xs text-slate-500">Choose how the batch splits across strands. Percentages must total 100%.</p>
                            <div class="mt-2 space-y-2">
                                <?php foreach($source['targets'] as $targetIndex=>$target): ?>
                                <label class="flex items-center justify-between gap-2 text-sm">
                                    <span class="font-semibold text-slate-700"><?= htmlspecialchars($target['strand']) ?></span>
                                    <span class="flex items-center gap-1">
                                        <input type="hidden" name="plans[<?= $index ?>][targets][<?= $targetIndex ?>][strand]" value="<?= htmlspecialchars($target['strand']) ?>">
                                        <input class="w-20 rounded-lg border border-slate-300 px-2 py-1.5 text-right text-slate-900" type="number" min="0" max="100"
                                            name="plans[<?= $index ?>][targets][<?= $targetIndex ?>][percent]" value="<?= (int)$target['percent'] ?>">%
                                    </span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <?php foreach($source['targets'] as $targetIndex=>$target): ?>
                            <input type="hidden" name="plans[<?= $index ?>][targets][<?= $targetIndex ?>][strand]" value="<?= htmlspecialchars($target['strand']) ?>">
                            <input type="hidden" name="plans[<?= $index ?>][targets][<?= $targetIndex ?>][percent]" value="<?= (int)$target['percent'] ?>">
                            <p class="text-xs text-slate-500"><?= $target['strand']!==''?htmlspecialchars($target['strand']):'Junior high school' ?></p>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-4">
                            <?php if($source['students']===0): ?>
                            <span class="text-slate-400">No students</span>
                            <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach($source['targets'] as $target): ?>
                                <?php if((int)$target['percent']>0): ?>
                                <div>
                                    <?php if($source['is_split_grade']): ?>
                                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500"><?= htmlspecialchars($target['strand']) ?> · <?= (int)$target['percent'] ?>%</p>
                                    <?php endif; ?>
                                    <?php if(!$target['projected']): ?>
                                    <p class="text-slate-400">No projected students</p>
                                    <?php else: ?>
                                    <ul class="space-y-1 text-slate-600">
                                        <?php foreach($target['projected'] as $group): ?>
                                        <li><span class="font-bold text-slate-900"><?= htmlspecialchars($group['section']['SECTION']) ?></span>
                                            · <?= count($group['students']) ?> student<?= count($group['students'])===1?'':'s' ?>
                                            <?php if(!empty($group['section']['VIRTUAL'])): ?><span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800">new</span><?php endif; ?>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php endif; ?>
                                    <p class="mt-2 text-xs text-slate-500"><?= (int)$target['section_count'] ?> existing section<?= $target['section_count']===1?'':'s' ?> · <?= (int)$target['capacity'] ?> seats · <?= (int)$target['count'] ?> projected students</p>
                                </div>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="mt-6 flex flex-wrap justify-end gap-3">
            <button type="submit" formaction="save-school-year-rollover-map.php"
                class="btn btn-secondary">Preview plan</button>
            <button id="open-undo-rollover" type="button" <?= $canUndo?'':'disabled' ?>
                class="rounded-xl border px-5 py-3 font-bold disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400" style="border-color:#f59e0b;background:#fffbeb;color:#92400e">Undo rollover</button>
            <button id="open-rollover-confirmation" type="button"
                class="btn btn-danger">Confirm rollover</button>
        </div>
    </form>
</section>
<div id="rollover-confirmation-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-5" role="dialog" aria-modal="true" aria-labelledby="rollover-confirmation-title">
    <div class="card w-full max-w-md p-6 shadow-2xl">
        <h3 id="rollover-confirmation-title" class="text-xl font-black text-slate-900">Confirm end-of-school-year rollover</h3>
        <p class="mt-2 text-sm leading-6 text-slate-600">This promotes enrolled Grade 7–11 students into the next grade,
            auto-sections them at <?= (int) $defaultCapacity ?> per section, archives graduating Grade 12 students, and advances the active school
            year<?= $nextSchoolYear!==''?' to '.htmlspecialchars($nextSchoolYear):'' ?>.</p>
        <label class="mt-5 flex items-start gap-3 text-sm font-bold text-slate-800"><input id="rollover-ended-check" type="checkbox" class="mt-1 h-4 w-4">I confirm the school year has ended and eligible Grade 12 students have graduated.</label>
        <label class="mt-5 block text-sm font-bold text-slate-800">Administrator password<input id="rollover-password-input" type="password" autocomplete="current-password" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" required></label>
        <p id="rollover-confirmation-error" class="mt-3 hidden text-sm font-bold text-rose-600"></p>
        <div class="mt-6 flex gap-3"><button id="cancel-rollover-confirmation" type="button" class="btn btn-secondary flex-1">Cancel</button><button id="submit-rollover-confirmation" type="button" class="btn btn-danger flex-1">Run rollover</button></div>
    </div>
</div>
<form id="undo-rollover-form" action="undo-school-year-rollover.php" method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input id="undo-rollover-year" type="hidden" name="target_school_year">
    <input id="undo-rollover-password" type="hidden" name="current_password">
</form>
<div id="undo-rollover-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-5" role="dialog" aria-modal="true" aria-labelledby="undo-rollover-title">
    <div class="card w-full max-w-md p-6 shadow-2xl">
        <h3 id="undo-rollover-title" class="text-xl font-black text-slate-900">Undo school-year rollover?</h3>
        <p class="mt-2 text-sm leading-6 text-slate-600">Choose a saved school year to restore its student grades, sections, and enrollment statuses. The active school year will return to the selected year.</p>
        <label class="mt-5 block text-sm font-bold text-slate-800">Restore to school year<select id="undo-rollover-year-select" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3" required><?php foreach($pastRolloverYears as $pastYear): ?><option value="<?= htmlspecialchars($pastYear['SCHOOL_YEAR']) ?>" data-students="<?= (int)$pastYear['STUDENT_COUNT'] ?>"><?= htmlspecialchars($pastYear['SCHOOL_YEAR']) ?> (<?= (int)$pastYear['STUDENT_COUNT'] ?> students)</option><?php endforeach; ?></select></label>
        <p id="undo-rollover-summary" class="mt-3 text-sm font-bold text-amber-800"></p>
        <label class="mt-5 block text-sm font-bold text-slate-800">Administrator password<input id="undo-rollover-password-input" type="password" autocomplete="current-password" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" required></label>
        <p id="undo-rollover-error" class="mt-3 hidden text-sm font-bold text-rose-600"></p>
        <div class="mt-6 flex gap-3"><button id="cancel-undo-rollover" type="button" class="btn btn-secondary flex-1">Cancel</button><button id="submit-undo-rollover" type="button" class="flex-1 rounded-xl px-4 py-3 font-bold shadow-sm" style="display:inline-flex;align-items:center;justify-content:center;background:#b45309;color:#fff">Undo rollover</button></div>
    </div>
</div>
<script>
    (() => {
        const form = document.getElementById('rollover-form'), open = document.getElementById('open-rollover-confirmation'), modal = document.getElementById('rollover-confirmation-modal'), cancel = document.getElementById('cancel-rollover-confirmation'), submit = document.getElementById('submit-rollover-confirmation'), password = document.getElementById('rollover-password-input'), ended = document.getElementById('rollover-ended-check'), error = document.getElementById('rollover-confirmation-error');
        if (!form || !open || !modal || !cancel || !submit) return;
        const close = () => { modal.classList.replace('flex', 'hidden'); error.classList.add('hidden'); };
        open.addEventListener('click', () => { if (!form.reportValidity()) return; password.value = ''; ended.checked = false; modal.classList.replace('hidden', 'flex'); password.focus(); });
        cancel.addEventListener('click', close);
        modal.addEventListener('click', event => { if (event.target === modal) close(); });
        submit.addEventListener('click', () => {
            if (!ended.checked || !password.value) { error.textContent = !ended.checked ? 'Confirm that the school year has ended.' : 'Enter the administrator password.'; error.classList.remove('hidden'); return; }
            document.getElementById('rollover-password').value = password.value;
            document.getElementById('rollover-end-confirmation').value = '1';
            form.submit();
        });
    })();
</script>
<script>
    (() => {
        const form = document.getElementById('undo-rollover-form'), open = document.getElementById('open-undo-rollover'), modal = document.getElementById('undo-rollover-modal'), cancel = document.getElementById('cancel-undo-rollover'), submit = document.getElementById('submit-undo-rollover'), password = document.getElementById('undo-rollover-password-input'), year = document.getElementById('undo-rollover-year-select'), targetYear = document.getElementById('undo-rollover-year'), summary = document.getElementById('undo-rollover-summary'), error = document.getElementById('undo-rollover-error');
        if (!form || !open || open.disabled || !modal || !cancel || !submit) return;
        const close = () => { modal.classList.replace('flex', 'hidden'); error.classList.add('hidden'); };
        const updateSummary = () => {
            const option = year.options[year.selectedIndex];
            summary.textContent = `This will restore ${option.dataset.students} student records and make ${option.value} the active school year.`;
        };
        year.addEventListener('change', updateSummary);
        open.addEventListener('click', () => { password.value = ''; updateSummary(); modal.classList.replace('hidden', 'flex'); password.focus(); });
        cancel.addEventListener('click', close);
        modal.addEventListener('click', event => { if (event.target === modal) close(); });
        submit.addEventListener('click', () => {
            if (!password.value) { error.textContent = 'Enter the administrator password.'; error.classList.remove('hidden'); return; }
            document.getElementById('undo-rollover-password').value = password.value;
            targetYear.value = year.value;
            form.submit();
        });
    })();
</script>
<?php adminFoot(); ?>
