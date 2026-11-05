<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/teacher-profile-schema.php';
require '../includes/teacher-assignment-schema.php';
require '../includes/csrf-token.php';
ensureTeacherProfileColumns($conn);
ensureTeacherAssignmentSchema($conn);
require 'components.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_advisory') {
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['toast']=['message'=>'The advisory request expired. Refresh and try again.','type'=>'error'];
    } else {
        try {
            $teacherId = (int) ($_POST['teacher_id'] ?? 0);
            $rawSectionId = $_POST['section_id'] ?? '';
            if (is_array($rawSectionId)) throw new RuntimeException('Choose a valid advisory section.');
            $sectionValue = trim((string) $rawSectionId);
            if ($teacherId < 1 || ($sectionValue !== '' && (!ctype_digit($sectionValue) || (int) $sectionValue < 1))) {
                throw new RuntimeException('Choose a valid teacher and advisory section.');
            }
            $sectionId = $sectionValue === '' ? 0 : (int) $sectionValue;
            $current = teacherAdvisoryAssignments($conn, $teacherId)[0] ?? null;
            if ($sectionId > 0 && $current && (int) $current['SECTION_ID'] !== $sectionId && ($_POST['replace_confirmation'] ?? '') !== '1') {
                throw new RuntimeException('Confirm that the teacher’s existing advisory will be released before assigning the new section.');
            }
            $conn->begin_transaction();
            setTeacherAdvisory($conn, $teacherId, $sectionId);
            $conn->commit();
            $_SESSION['toast']=['message'=>$sectionId > 0 ? 'Current-year advisory assignment saved.' : 'Current-year advisory assignment released.','type'=>'success'];
        } catch (Throwable $error) {
            $conn->rollback();
            $_SESSION['toast']=['message'=>$error instanceof RuntimeException ? $error->getMessage() : ($error instanceof mysqli_sql_exception && (int) $error->getCode() === 1062 ? 'That teacher or section was just assigned elsewhere. Refresh and choose another available section.' : 'Advisory assignments could not be saved.'),'type'=>'error'];
        }
    }
    header('Location: teacher-list.php'); exit;
}

$limit = 15;
$view = strtolower(trim((string) ($_GET['view'] ?? 'active')));
if (!in_array($view, ['active', 'archived'], true)) {
    $view = 'active';
}
$isArchivedView = $view === 'archived';
$teacherWhere = $isArchivedView
    ? "TEACHER_STATUS='Archived'"
    : "COALESCE(NULLIF(TEACHER_STATUS,''),'Active')='Active'";

$teacherCount = $conn->query("SELECT COUNT(*) AS total FROM teachers WHERE $teacherWhere");
$totalTeachers = (int) ($teacherCount ? $teacherCount->fetch_assoc()['total'] ?? 0 : 0);
$teacherOrder = $isArchivedView ? 'ARCHIVED_AT DESC, TEACHER_NAME' : 'TEACHER_NAME';
$teachers = $conn->query(
    "SELECT TEACHER_ID, TEACHER_NAME, TEACHER_USER, CREATED_AT, ARCHIVED_AT
     FROM teachers
     WHERE $teacherWhere
     ORDER BY $teacherOrder",
);

$viewPillActive = 'rounded-full bg-blue-600 text-white font-black text-sm px-4 py-2 shadow-md transition-all';
$viewPillInactive = 'rounded-full text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white font-bold text-sm px-4 py-2 transition-all';
$viewToggle = '<nav class="inline-flex flex-wrap items-center gap-1 rounded-full border border-slate-200 bg-white p-1 shadow-sm dark:border-slate-800 dark:bg-slate-900/90" aria-label="Teacher records view">'
    . '<a href="teacher-list.php?view=active" class="' . ($isArchivedView ? $viewPillInactive : $viewPillActive) . '"'
    . (!$isArchivedView ? ' aria-current="page"' : '') . '>Active</a>'
    . '<a href="teacher-list.php?view=archived" class="' . ($isArchivedView ? $viewPillActive : $viewPillInactive) . '"'
    . ($isArchivedView ? ' aria-current="page"' : '') . '>Archived</a>'
    . '</nav>';

adminHead($isArchivedView ? 'Archived teachers' : 'Teachers');
adminSidebar(
    'teachers',
    $isArchivedView ? 'Archived teachers' : 'Teachers',
    $isArchivedView ? 'Retained teacher accounts and access assignments.' : 'Manage teacher access and grade-section assignments.',
);
?>
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
        <p class="text-sm font-semibold text-slate-500">
            <?= $totalTeachers ?> <?= $isArchivedView ? 'archived' : 'active' ?>
            teacher<?= $totalTeachers === 1 ? ' account' : ' accounts' ?>
        </p>
        <?= $viewToggle ?>
    </div>
    <?php if (!$isArchivedView): ?>
    <a href="add-teacher.php" class="btn btn-primary">+ Add teacher</a>
    <?php endif; ?>
</div>

<div class="card overflow-hidden">
    <div class="w-full overflow-x-auto">
        <table class="w-full min-w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                <tr>
                    <th class="px-5 py-4">Teacher</th>
                    <th class="px-5 py-4">Username</th>
                    <th class="px-5 py-4"><?= $isArchivedView ? 'Retained assignments' : 'Assigned grades and sections' ?>
                    </th>
                    <th class="px-5 py-4"><?= $isArchivedView ? 'Archived' : 'Created' ?></th>
                    <?php if ($isArchivedView): ?>
                    <th class="px-5 py-4">Status</th>
                    <?php endif; ?>
                    <th class="px-5 py-4"></th>
                </tr>
            </thead>
            <tbody id="teacher-rows" data-page-size="<?= $limit ?>" class="divide-y divide-slate-100">
                <?php if ($teachers && $teachers->num_rows): ?>
                <?php while ($teacher = $teachers->fetch_assoc()): ?>
                <?php
                    $assignments = teacherAssignments($conn, (int) $teacher['TEACHER_ID']);
                    $advisory = teacherAdvisoryAssignments($conn, (int) $teacher['TEACHER_ID']);
                    $dateValue = $isArchivedView ? ($teacher['ARCHIVED_AT'] ?? '') : ($teacher['CREATED_AT'] ?? '');
                ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-4 font-bold text-slate-900"><?= htmlspecialchars($teacher['TEACHER_NAME']) ?></td>
                    <td class="px-5 py-4 text-slate-600"><?= htmlspecialchars($teacher['TEACHER_USER']) ?></td>
                    <td class="px-5 py-4">
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($advisory as $adviserClass): ?><span class="px-2.5 py-1 rounded-full text-xs font-bold bg-blue-500/10 text-blue-600 border border-blue-500/20"><?= htmlspecialchars('Adviser: Grade '.$adviserClass['GRADE'].' · '.$adviserClass['SECTION'].($adviserClass['STRAND'] ? ' · '.$adviserClass['STRAND'] : '')) ?></span><?php endforeach; ?>
                            <?php foreach ($assignments as $assignment): ?>
                            <?php
                                $detail = in_array((int) $assignment['GRADE'], [11, 12], true) ? $assignment['STRAND'] : $assignment['SUBJECT'];
                                $label = 'Teaches: Grade ' . $assignment['GRADE'] . ' | ' . $assignment['SECTION'];
                            ?>
                            <span
                                class="rounded-full bg-violet-50 px-3 py-1 font-bold text-violet-700"><?= htmlspecialchars($label) . ($detail ? ' · ' . htmlspecialchars($detail) : '') ?></span>
                            <?php endforeach; ?>
                            <?php if (!$assignments && !$advisory): ?>
                            <span class="text-slate-500"><?= $isArchivedView ? 'No assignments retained' : 'Not assigned' ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-5 py-4 text-slate-500">
                        <?= $dateValue ? htmlspecialchars(date('M j, Y', strtotime($dateValue))) : '—' ?>
                    </td>
                    <?php if ($isArchivedView): ?>
                    <td class="px-5 py-4"><span
                            class="rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700">Archived</span>
                    </td>
                    <?php endif; ?>
                    <td class="px-5 py-4 text-right">
                        <?php if ($isArchivedView): ?>
                        <div class="flex flex-wrap justify-end gap-2">
                            <a class="btn btn-secondary inline-flex h-8 items-center justify-center rounded border border-blue-500/20 bg-blue-500/10 px-3 text-xs font-medium text-blue-600 transition hover:bg-blue-500/20"
                                href="archived-teacher-record.php?id=<?= (int) $teacher['TEACHER_ID'] ?>">Open record</a>
                            <?php $restoreOptions = availableTeacherAdvisorySections($conn, (int) $teacher['TEACHER_ID']); ?>
                            <button type="button" class="advisory-modal-trigger btn btn-secondary inline-flex h-8 items-center justify-center rounded border border-emerald-500/20 bg-emerald-500/10 px-3 text-xs font-medium text-emerald-700 transition hover:bg-emerald-500/20"
                                data-restore="true"
                                data-teacher-id="<?= (int) $teacher['TEACHER_ID'] ?>"
                                data-teacher-name="<?= htmlspecialchars($teacher['TEACHER_NAME'], ENT_QUOTES) ?>"
                                data-current-section="0"
                                data-sections="<?= htmlspecialchars(json_encode($restoreOptions), ENT_QUOTES) ?>">Restore</button>
                        </div>
                        <?php else: ?>
                        <?php $advisoryOptions = availableTeacherAdvisorySections($conn, (int) $teacher['TEACHER_ID']); ?>
                        <button type="button" class="advisory-modal-trigger btn btn-secondary inline-flex h-8 items-center justify-center rounded border border-emerald-500/20 bg-emerald-500/10 px-3 text-xs font-medium text-emerald-700 transition hover:bg-emerald-500/20"
                            data-teacher-id="<?= (int) $teacher['TEACHER_ID'] ?>"
                            data-teacher-name="<?= htmlspecialchars($teacher['TEACHER_NAME'], ENT_QUOTES) ?>"
                            data-current-section="<?= (int) ($advisory[0]['SECTION_ID'] ?? 0) ?>"
                            data-sections="<?= htmlspecialchars(json_encode($advisoryOptions), ENT_QUOTES) ?>">Assign / Change Advisory</button>
                        <a href="edit-teacher.php?id=<?= (int) $teacher['TEACHER_ID'] ?>"
                            class="btn btn-secondary inline-flex h-8 items-center justify-center rounded border border-blue-500/20 bg-blue-500/10 px-3 text-xs font-medium text-blue-600 transition hover:bg-blue-500/20">Open
                            profile</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php else: ?>
                <tr>
                    <td colspan="<?= $isArchivedView ? 6 : 5 ?>" class="px-5 py-12 text-center text-slate-500">
                        <?= $isArchivedView ? 'No archived teacher accounts.' : 'No teacher accounts yet. Add the first teacher to get started.' ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<div id="advisory-search-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="advisory-modal-title">
    <div class="w-full max-w-xl rounded-2xl bg-white p-5 shadow-2xl">
        <div class="flex items-start justify-between gap-4">
            <div><h2 id="advisory-modal-title" class="text-lg font-black text-slate-900">Assign advisory</h2><p id="advisory-modal-teacher" class="mt-1 text-sm text-slate-500"></p></div>
            <button type="button" data-advisory-modal-close class="rounded-lg px-2 py-1 text-slate-500 hover:bg-slate-100" aria-label="Close">×</button>
        </div>
        <form method="post" id="advisory-assignment-form" class="mt-5 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="assign_advisory" id="advisory-action">
            <input type="hidden" name="teacher_id" id="advisory-teacher-id">
            <input type="hidden" name="section_id" id="advisory-section-id">
            <label for="advisory-search-input" class="block text-sm font-bold text-slate-700">Search advisory section</label>
            <input type="text" id="advisory-search-input" placeholder="Search Grade or Section..." autocomplete="off" class="field w-full" aria-controls="advisory-search-results">
            <div id="advisory-search-results" class="max-h-64 space-y-2 overflow-y-auto rounded-xl border border-slate-200 p-2" role="listbox"></div>
            <p id="advisory-selected-label" class="text-sm font-semibold text-slate-500">Choose a section or leave it empty.</p>
            <label id="advisory-replace-confirmation" class="hidden items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                <input type="checkbox" name="replace_confirmation" value="1" class="mt-0.5">
                <span>I understand this releases the teacher’s current advisory before assigning the selected section.</span>
            </label>
            <div class="flex justify-end gap-2"><button type="button" data-advisory-modal-close class="btn btn-secondary">Cancel</button><button class="btn btn-primary">Save advisory</button></div>
        </form>
    </div>
</div>
<script src="../js/table-pagination.js?v=7"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const rows = document.getElementById('teacher-rows');
        if (!rows) return;
        const card = rows.closest('.overflow-hidden'),
            bottom = document.createElement('div');
        card?.insertAdjacentElement('afterend', bottom);
        createTablePagination({
            rows,
            pager: bottom,
            label: '<?= $isArchivedView ? 'archived teacher accounts' : 'teacher accounts' ?>',
            perPage: <?= $limit ?>,
            total: <?= $totalTeachers ?>
        });

        const modal = document.getElementById('advisory-search-modal');
        if (!modal) return;
        const input = document.getElementById('advisory-search-input');
        const results = document.getElementById('advisory-search-results');
        const teacherId = document.getElementById('advisory-teacher-id');
        const sectionId = document.getElementById('advisory-section-id');
        const teacherLabel = document.getElementById('advisory-modal-teacher');
        const selectedLabel = document.getElementById('advisory-selected-label');
        const confirmation = document.getElementById('advisory-replace-confirmation');
        const form = document.getElementById('advisory-assignment-form');
        const action = document.getElementById('advisory-action');
        let sections = [], currentSectionId = 0;
        const closeModal = () => { modal.classList.add('hidden'); modal.classList.remove('flex'); };
        const sectionLabel = (section) => `Grade ${section.GRADE} · ${section.SECTION}${section.STRAND ? ` · ${section.STRAND}` : ''}`;
        const resetConfirmation = () => {
            confirmation.classList.add('hidden');
            confirmation.classList.remove('flex');
            confirmation.querySelector('input').checked = false;
        };
        const selectSection = (value, label) => {
            sectionId.value = value;
            selectedLabel.textContent = `Selected: ${label}`;
            const replacing = value !== '' && currentSectionId > 0 && Number(value) !== currentSectionId;
            confirmation.classList.toggle('hidden', !replacing);
            confirmation.classList.toggle('flex', replacing);
            confirmation.querySelector('input').checked = false;
        };
        const appendResult = (label, className, onClick, disabled = false) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.role = 'option';
            button.className = className;
            button.textContent = label;
            if (disabled) {
                button.disabled = true;
                button.setAttribute('aria-disabled', 'true');
            } else {
                button.addEventListener('click', onClick);
            }
            results.appendChild(button);
        };
        const renderSections = () => {
            const needle = input.value.trim().toLowerCase();
            const currentSection = sections.find(section => Number(section.SECTION_ID) === currentSectionId);
            const matches = sections.filter(section => Number(section.SECTION_ID) !== currentSectionId && (`grade ${section.GRADE} ${section.SECTION} ${section.STRAND || ''}`).toLowerCase().includes(needle));
            results.innerHTML = '';
            appendResult(
                '-- Leave empty (Unassign) --',
                'block w-full rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-left text-sm font-semibold text-rose-700 hover:bg-rose-100 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-300 dark:hover:bg-rose-900/30',
                () => selectSection('', '-- Leave empty (Unassign) --'),
            );
            if (currentSection) {
                appendResult(
                    `${sectionLabel(currentSection)} (Currently Assigned)`,
                    'block w-full cursor-not-allowed rounded-lg bg-slate-50 px-3 py-2 text-left text-sm font-semibold text-slate-400 dark:bg-slate-800/70 dark:text-slate-500',
                    null,
                    true,
                );
            }
            if (!matches.length) {
                const empty = document.createElement('p');
                empty.className = 'px-3 py-2 text-sm text-slate-500';
                empty.textContent = 'No available sections match your search.';
                results.appendChild(empty);
                return;
            }
            matches.forEach(section => {
                const label = sectionLabel(section);
                appendResult(
                    label,
                    'block w-full rounded-lg px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800',
                    () => selectSection(String(section.SECTION_ID), label),
                );
            });
        };
        document.querySelectorAll('.advisory-modal-trigger').forEach(button => button.addEventListener('click', () => {
            const restore = button.dataset.restore === 'true';
            teacherId.value = button.dataset.teacherId;
            teacherId.name = restore ? 'id' : 'teacher_id';
            sectionId.name = restore ? 'advisory_section_id' : 'section_id';
            form.action = restore ? 'restore-archived-teacher.php' : 'teacher-list.php';
            action.disabled = restore;
            teacherLabel.textContent = button.dataset.teacherName;
            currentSectionId = Number(button.dataset.currentSection || 0);
            sections = JSON.parse(button.dataset.sections || '[]');
            input.value = ''; sectionId.value = ''; selectedLabel.textContent = 'Choose a section or leave it empty.';
            resetConfirmation();
            renderSections(); modal.classList.remove('hidden'); modal.classList.add('flex'); input.focus();
        }));
        input.addEventListener('input', renderSections);
        modal.querySelectorAll('[data-advisory-modal-close]').forEach(button => button.addEventListener('click', closeModal));
        document.addEventListener('keydown', event => { if (event.key === 'Escape' && !modal.classList.contains('hidden')) closeModal(); });
    });
</script>
<?php adminFoot(); ?>
