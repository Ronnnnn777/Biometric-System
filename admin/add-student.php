<?php
session_start();
require "../config/db_conn.php";
require "../includes/csrf-token.php";
require "../includes/student-placement-options.php";
require "../includes/section-catalog-schema.php";
require "page-guard.php";
require "components.php";
require_once "../includes/student-face-references.php";
ensureStudentFaceReferences($conn);
ensureSectionCatalogColumns($conn);
seedDefaultTeacherSections($conn);
$toast = $_SESSION["toast"]["message"] ?? null;
unset($_SESSION["toast"]);
adminHead("Register student");
adminSidebar(
    "students",
    "Register student",
    "Create a student profile for attendance recognition",
);
?>
<div class="w-full">
    <section class="card card-body">
        <p class="text-sm font-bold uppercase tracking-widest text-blue-600">Student profile</p>
        <h2 class="mt-2 text-2xl font-black text-slate-900">Registration details</h2>
        <p class="mt-2 text-slate-500">All fields marked below are required.</p>
        <?php if (
            ($_GET["msg"] ?? "") ===
            "duplicate"
        ): ?><div class="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">That LRN
            is already registered. Try a different ID.</div><?php endif; ?>
        <form action="save-student.php" method="post" enctype="multipart/form-data" class="mt-7 space-y-5">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(
                $_SESSION["csrf_token"],
            ) ?>">
            <div class="grid gap-5 sm:grid-cols-2">
                <label class="block text-sm font-bold text-slate-700">LRN<input
                        class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
                        name="student_id" placeholder="e.g. 2024-0001" required></label>
                <label class="block text-sm font-bold text-slate-700">Grade<select
                        class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
                        name="grade" required>
                        <option value="">Choose a grade</option><?php foreach (
                    [7, 8, 9, 10, 11, 12]
                    as $grade
                ): ?><option value="<?= $grade ?>">Grade <?= $grade ?></option><?php endforeach; ?>
                    </select></label>
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <label id="strand-field" class="hidden text-sm font-bold text-slate-700">Strand<select
                        id="student-strand"
                        class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
                        name="strand" disabled>
                        <option value="">Choose a strand</option>
                    </select></label>
                <label id="section-field" class="block text-sm font-bold text-slate-700 sm:col-span-2">Section<select
                        id="student-section"
                        class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
                        name="student_section" required disabled>
                        <option value="">Choose a grade first</option>
                    </select></label>
            </div>
            <div class="grid gap-5 sm:grid-cols-3"><label class="block text-sm font-bold text-slate-700">First
                    name<input
                        class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
                        name="first_name" autocomplete="given-name" required></label><label
                    class="block text-sm font-bold text-slate-700">Middle name<input
                        class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
                        name="middle_name" autocomplete="additional-name"></label><label
                    class="block text-sm font-bold text-slate-700">Last name<input
                        class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
                        name="last_name" autocomplete="family-name" required></label></div>
            <div class="grid gap-5 sm:grid-cols-[140px_minmax(0,1fr)]"><label
                    class="block text-sm font-bold text-slate-700">Age<input
                        class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
                        type="number" name="age" min="3" max="120" inputmode="numeric" required></label><label
                    class="block text-sm font-bold text-slate-700">Home address<input
                        class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
                        name="address" autocomplete="street-address" maxlength="255" required></label></div>
            <?php require "../includes/student-face-fields.php"; ?>
            <button
                class="btn btn-primary w-full">Register
                student</button>
        </form>
    </section>
</div>
<?php if (
    $toast
): ?><div data-auto-toast
    class="fixed bottom-5 right-5 rounded-xl bg-slate-900 px-5 py-4 font-semibold text-white shadow-xl transition-all duration-300"><?= htmlspecialchars(
    $toast,
) ?></div><?php endif; ?>
<script>
    const placementOptions = <?php echo json_encode(
        studentSectionPlacementOptions($conn),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
    ); ?>;
    const gradeSelect = document.querySelector('[name="grade"]'),
        sectionSelect = document.getElementById('student-section'),
        strandSelect = document.getElementById('student-strand'),
        sectionField = document.getElementById('section-field'),
        strandField = document.getElementById('strand-field');

    function setOptions(select, placeholder, items) {
        select.innerHTML = '';
        const first = document.createElement('option');
        first.value = '';
        first.textContent = placeholder;
        select.appendChild(first);
        items.forEach(item => {
            const option = document.createElement('option');
            option.value = item;
            option.textContent = item;
            select.appendChild(option)
        })
    }

    function updatePlacement() {
        const grade = gradeSelect.value,
            senior = grade === '11' || grade === '12';
        strandField.classList.toggle('hidden', !senior);
        strandSelect.disabled = !senior;
        strandSelect.required = senior;
        sectionField.classList.toggle('sm:col-span-2', !senior);
        if (!grade) {
            setOptions(sectionSelect, 'Choose a grade first', []);
            sectionSelect.disabled = true;
            setOptions(strandSelect, 'Choose a strand', []);
            return
        }
        if (!senior) {
            strandSelect.value = '';
            setOptions(sectionSelect, 'Choose a section', placementOptions[grade] || []);
            sectionSelect.disabled = false;
            return
        }
        setOptions(strandSelect, 'Choose a strand', ['Techpro', 'Academic']);
        setOptions(sectionSelect, 'Choose a strand first', []);
        sectionSelect.disabled = true
    }

    function updateSeniorSections() {
        if (!(gradeSelect.value === '11' || gradeSelect.value === '12')) return;
        const sections = placementOptions[gradeSelect.value]?.[strandSelect.value] || [];
        setOptions(sectionSelect, strandSelect.value ? 'Choose a section' : 'Choose a strand first', sections);
        sectionSelect.disabled = !strandSelect.value
    }
    gradeSelect.addEventListener('change', updatePlacement);
    strandSelect.addEventListener('change', updateSeniorSections);
    updatePlacement();
</script>
<?php adminFoot(); ?>
