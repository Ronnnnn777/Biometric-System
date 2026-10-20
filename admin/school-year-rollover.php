<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/csrf-token.php';
require '../includes/section-catalog-schema.php';
require '../includes/school-year-schema.php';
require 'components.php';

ensureSectionCatalogColumns($conn);
ensureSchoolYearRolloverSchema($conn);

$sources=[];
$sourceRows=$conn->query("SELECT c.GRADE,c.STRAND,c.SECTION,COUNT(s.ID) STUDENTS FROM sections c LEFT JOIN students s ON s.GRADE=c.GRADE AND s.STRAND=c.STRAND AND s.SECTION=c.SECTION AND s.ENROLLMENT_STATUS='Enrolled' WHERE c.IS_ACTIVE=1 AND c.GRADE BETWEEN 7 AND 11 GROUP BY c.GRADE,c.STRAND,c.SECTION ORDER BY c.GRADE,c.STRAND,c.SECTION");
while($sourceRows&&$row=$sourceRows->fetch_assoc()) $sources[]=$row;

$targets=[];
$targetRows=$conn->query('SELECT GRADE,STRAND,SECTION FROM sections WHERE IS_ACTIVE=1 AND GRADE BETWEEN 8 AND 12 ORDER BY GRADE,STRAND,SECTION');
while($targetRows&&$row=$targetRows->fetch_assoc()) $targets[]=$row;

$maps=[];
$mapRows=$conn->query('SELECT * FROM school_year_rollover_maps');
while($mapRows&&$row=$mapRows->fetch_assoc()) $maps[$row['FROM_GRADE'].'|'.$row['FROM_STRAND'].'|'.$row['FROM_SECTION']]=$row;
$setting=$conn->query('SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1')->fetch_assoc();
$activeSchoolYear=(string)($setting['ACTIVE_SCHOOL_YEAR']??'');
$pastRolloverYears=[];
$pastYears=$conn->prepare('SELECT SCHOOL_YEAR,COUNT(*) STUDENT_COUNT FROM student_school_history WHERE SCHOOL_YEAR < ? GROUP BY SCHOOL_YEAR ORDER BY SCHOOL_YEAR DESC');
$pastYears->bind_param('s',$activeSchoolYear);
$pastYears->execute();
$pastYearRows=$pastYears->get_result();
while($pastYearRows&&$pastYear=$pastYearRows->fetch_assoc()) $pastRolloverYears[]=$pastYear;
$canUndo=!empty($pastRolloverYears);
adminHead('School year rollover');
adminSidebar('rollover','End-of-school-year rollover','Use this only after the school year ends to promote students and archive graduating Grade 12 students.');
?>
<section class="mx-auto max-w-5xl rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 sm:p-8">
    <h2 class="text-2xl font-black text-slate-900">End-of-School-Year Rollover</h2>
    <p class="mt-2 text-slate-500">Set the official destination for every class, then confirm the rollover. Your
        administrator password is required before any student record changes.</p>
    <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        <strong>Important:</strong> Use this only when the school year has ended. Grade 12 students stay enrolled until
        the final end-of-year confirmation, then they are archived as Graduated. Review every mapping first.</div>

    <form id="rollover-form" action="run-school-year-rollover.php" method="post" class="mt-7">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input id="rollover-password" type="hidden" name="current_password">
        <input id="rollover-end-confirmation" type="hidden" name="end_school_year_confirmation">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Current class</th>
                        <th class="px-4 py-3">Students</th>
                        <th class="px-4 py-3">Next school year class</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach($sources as $index=>$source):
            $key=$source['GRADE'].'|'.$source['STRAND'].'|'.$source['SECTION'];
            $map=$maps[$key]??null;
            $nextGrade=(int)$source['GRADE']+1;
          ?>
                    <tr>
                        <td class="px-4 py-4 font-bold text-slate-900"><input type="hidden"
                                name="mappings[<?= $index ?>][from_grade]" value="<?= (int)$source['GRADE'] ?>"><input
                                type="hidden" name="mappings[<?= $index ?>][from_strand]"
                                value="<?= htmlspecialchars($source['STRAND']) ?>"><input type="hidden"
                                name="mappings[<?= $index ?>][from_section]"
                                value="<?= htmlspecialchars($source['SECTION']) ?>">Grade
                            <?= (int)$source['GRADE'] ?><?= $source['STRAND']?' · '.htmlspecialchars($source['STRAND']):'' ?>
                            · <?= htmlspecialchars($source['SECTION']) ?></td>
                        <td class="px-4 py-4 text-slate-600"><?= (int)$source['STUDENTS'] ?></td>
                        <td class="px-4 py-4">
                            <select name="mappings[<?= $index ?>][target]"
                                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2" required>
                                <option value="">Choose Grade <?= $nextGrade ?> section</option>
                                <?php foreach($targets as $target): if((int)$target['GRADE']!==$nextGrade) continue;
                    $value=$target['GRADE'].'|'.$target['STRAND'].'|'.$target['SECTION'];
                    $selected=$map&&$map['TO_GRADE']==$target['GRADE']&&$map['TO_STRAND']===$target['STRAND']&&$map['TO_SECTION']===$target['SECTION'];
                  ?>
                                <option value="<?= htmlspecialchars($value) ?>" <?= $selected?'selected':'' ?>>Grade
                                    <?= (int)$target['GRADE'] ?><?= $target['STRAND']?' · '.htmlspecialchars($target['STRAND']):'' ?>
                                    · <?= htmlspecialchars($target['SECTION']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="mt-6 flex flex-wrap justify-end gap-3">
            <button id="open-undo-rollover" type="button" <?= $canUndo?'':'disabled' ?>
                class="rounded-xl border px-5 py-3 font-bold disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400" style="border-color:#f59e0b;background:#fffbeb;color:#92400e">Undo rollover</button>
            <button id="open-rollover-confirmation" type="button"
                class="rounded-xl bg-rose-600 px-5 py-3 font-bold text-white hover:bg-rose-700">Confirm rollover</button>
        </div>
    </form>
</section>
<div id="rollover-confirmation-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-5" role="dialog" aria-modal="true" aria-labelledby="rollover-confirmation-title">
    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
        <h3 id="rollover-confirmation-title" class="text-xl font-black text-slate-900">Confirm end-of-school-year rollover</h3>
        <p class="mt-2 text-sm leading-6 text-slate-600">This promotes enrolled Grade 7–11 students, archives graduating Grade 12 students, and advances the active school year.</p>
        <label class="mt-5 flex items-start gap-3 text-sm font-bold text-slate-800"><input id="rollover-ended-check" type="checkbox" class="mt-1 h-4 w-4">I confirm the school year has ended and eligible Grade 12 students have graduated.</label>
        <label class="mt-5 block text-sm font-bold text-slate-800">Administrator password<input id="rollover-password-input" type="password" autocomplete="current-password" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" required></label>
        <p id="rollover-confirmation-error" class="mt-3 hidden text-sm font-bold text-rose-600"></p>
        <div class="mt-6 flex gap-3"><button id="cancel-rollover-confirmation" type="button" class="flex-1 rounded-xl border border-slate-300 px-4 py-3 font-bold text-slate-700 hover:bg-slate-50">Cancel</button><button id="submit-rollover-confirmation" type="button" class="flex-1 rounded-xl bg-rose-600 px-4 py-3 font-bold text-white hover:bg-rose-700">Run rollover</button></div>
    </div>
</div>
<form id="undo-rollover-form" action="undo-school-year-rollover.php" method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input id="undo-rollover-password" type="hidden" name="current_password">
    <input id="undo-rollover-year" type="hidden" name="target_school_year">
</form>
<div id="undo-rollover-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-5" role="dialog" aria-modal="true" aria-labelledby="undo-rollover-title">
    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
        <h3 id="undo-rollover-title" class="text-xl font-black text-slate-900">Undo school-year rollover?</h3>
        <p class="mt-2 text-sm leading-6 text-slate-600">Choose a saved school year to restore its student grades, sections, and enrollment statuses. The active school year will return to the selected year.</p>
        <label class="mt-5 block text-sm font-bold text-slate-800">Restore to school year<select id="undo-rollover-year-select" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3" required><?php foreach($pastRolloverYears as $pastYear): ?><option value="<?= htmlspecialchars($pastYear['SCHOOL_YEAR']) ?>" data-students="<?= (int)$pastYear['STUDENT_COUNT'] ?>"><?= htmlspecialchars($pastYear['SCHOOL_YEAR']) ?> (<?= (int)$pastYear['STUDENT_COUNT'] ?> students)</option><?php endforeach; ?></select></label>
        <p id="undo-rollover-summary" class="mt-3 text-sm font-bold text-amber-800"></p>
        <label class="mt-5 block text-sm font-bold text-slate-800">Administrator password<input id="undo-rollover-password-input" type="password" autocomplete="current-password" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" required></label>
        <p id="undo-rollover-error" class="mt-3 hidden text-sm font-bold text-rose-600"></p>
        <div class="mt-6 flex gap-3"><button id="cancel-undo-rollover" type="button" class="flex-1 rounded-xl border border-slate-300 px-4 py-3 font-bold text-slate-700 hover:bg-slate-50">Cancel</button><button id="submit-undo-rollover" type="button" class="flex-1 rounded-xl px-4 py-3 font-bold shadow-sm" style="display:inline-flex;align-items:center;justify-content:center;background:#b45309;color:#fff">Undo rollover</button></div>
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
