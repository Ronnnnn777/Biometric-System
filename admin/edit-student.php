<?php
session_start();
require "../config/db_conn.php";
require "../includes/student-profile-schema.php";
require "../includes/student-placement-options.php";
require "../includes/section-catalog-schema.php";
ensureStudentProfileColumns($conn);
ensureSectionCatalogColumns($conn);
seedDefaultTeacherSections($conn);
require "page-guard.php";
require "../includes/csrf-token.php";
require "components.php";
$id = (int) ($_GET["id"] ?? 0);
$stmt = $conn->prepare("SELECT * FROM students WHERE ID=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) {
    http_response_code(404);
    exit("Student not found.");
}
$placement = studentSectionPlacementOptions($conn);
adminHead("Edit student");
adminSidebar(
    "students",
    "Edit student",
    "Update the student profile or registered image",
);
?>
<a href="student-list.php"
    class="mb-4 inline-flex items-center gap-1.5 text-sm font-bold text-slate-600 transition hover:text-blue-700">
    <?= icon('arrow-left', 'h-4 w-4') ?> Back to Students
</a>
<div class="grid gap-7 lg:grid-cols-[minmax(0,1fr)_360px]">
    <section class="card card-body">
        <h2 class="text-2xl font-black text-slate-900">Student details</h2>
        <form action="update-student.php" method="post" enctype="multipart/form-data" class="mt-7 space-y-5">
            <input type="hidden" name="id" value="<?= $row[
          "ID"
      ] ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(
    $_SESSION["csrf_token"],
) ?>">
            <div class="grid gap-5 sm:grid-cols-2">
                <label class="block text-sm font-bold text-slate-700">LRN<input
                        class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" name="student_id" value="<?= htmlspecialchars(
            $row["STUDENT_ID"],
        ) ?>" required></label>
                <label class="block text-sm font-bold text-slate-700">Grade<select id="student-grade"
                        class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3" name="grade" required>
                        <option value="">Choose a grade</option><?php foreach (
            [7, 8, 9, 10, 11, 12]
            as $grade
        ): ?><option value="<?= $grade ?>" <?= (int) $row["GRADE"] === $grade
    ? "selected"
    : "" ?>>Grade <?= $grade ?></option><?php endforeach; ?>
                    </select></label>
            </div>
            <label class="block text-sm font-bold text-slate-700">Enrollment status<select
                    class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3" name="enrollment_status"
                    required><?php foreach (
          studentEnrollmentStatuses()
          as $status
      ): ?><option value="<?= htmlspecialchars($status) ?>" <?= ($row[
    "ENROLLMENT_STATUS"
] ??
    "Enrolled") ===
$status
    ? "selected"
    : "" ?>><?= htmlspecialchars(
    $status,
) ?></option><?php endforeach; ?></select><span class="mt-2 block text-xs font-normal text-slate-500">Only enrolled
                    students can use attendance scanning. Archived and inactive profiles remain in the system with their
                    records.</span></label>
            <div class="grid gap-5 sm:grid-cols-2">
                <label id="strand-field" class="text-sm font-bold text-slate-700">Strand<select id="student-strand"
                        class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3"
                        name="strand"></select></label>
                <label id="section-field" class="block text-sm font-bold text-slate-700">Section<select
                        id="student-section" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3"
                        name="section" required></select></label>
            </div>
            <div class="grid gap-5 sm:grid-cols-3"><label class="block text-sm font-bold text-slate-700">First
                    name<input class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" name="first_name" value="<?= htmlspecialchars(
          $row["FIRST_NAME"],
      ) ?>" required></label><label class="block text-sm font-bold text-slate-700">Middle name<input
                        class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" name="middle_name" value="<?= htmlspecialchars(
    $row["MIDDLE_NAME"] ?? "",
) ?>"></label><label class="block text-sm font-bold text-slate-700">Last name<input
                        class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" name="last_name" value="<?= htmlspecialchars(
    $row["LAST_NAME"],
) ?>" required></label></div>
            <div class="grid gap-5 sm:grid-cols-[140px_minmax(0,1fr)]"><label
                    class="block text-sm font-bold text-slate-700">Age<input
                        class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" type="number" name="age"
                        min="3" max="120" inputmode="numeric" value="<?= htmlspecialchars(
          $row["AGE"] ?? "",
      ) ?>" required></label><label class="block text-sm font-bold text-slate-700">Home address<input
                        class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" name="address"
                        autocomplete="street-address" maxlength="255" value="<?= htmlspecialchars(
    $row["ADDRESS"] ?? "",
) ?>" required></label></div>
            <div class="rounded-xl border border-slate-200 p-4">
                <div>
                    <p class="text-sm font-bold text-slate-700">Replace biometric image <span
                            class="font-normal text-slate-500">(optional)</span></p>
                    <p class="mt-1 text-sm text-slate-500">JPEG or PNG, max 5 MB: one clear, upright, uncropped face
                        with space around the head.</p>
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-3"><input class="sr-only" type="file"
                        name="student_image" id="student_image" accept="image/jpeg,image/png"><label for="student_image"
                        class="cursor-pointer rounded-lg bg-blue-50 px-4 py-2 text-sm font-bold text-blue-700 hover:bg-blue-100">Choose
                        photo</label><span id="student-image-file-name"
                        class="min-w-0 max-w-full truncate text-sm text-slate-500">No file chosen</span></div>
            </div>
            <button class="btn btn-primary">Save
                changes</button>
        </form>
        <form id="archive-form" action="archive-student.php" method="post" class="hidden"><input name="id" value="<?= $row[
        "ID"
    ] ?>"><input name="csrf_token" value="<?= htmlspecialchars(
    $_SESSION["csrf_token"],
) ?>"></form>
    </section>
    <aside class="card card-body h-fit">
        <p class="text-sm font-bold uppercase tracking-widest text-blue-600">Current image</p><img id="main-preview"
            src="../<?= htmlspecialchars(
      $row["IMAGE_PATH"],
  ) ?>" alt="<?= htmlspecialchars(
    $row["STUDENT_NAME"],
) ?>" class="mt-4 aspect-square w-full rounded-xl object-cover">
        <p class="mt-4 text-sm text-slate-500"><?= htmlspecialchars(
    $row["STUDENT_NAME"],
) ?></p>
        <section class="mt-5 rounded-2xl bg-blue-950 p-6">
            <h3 class="font-bold text-white">Archive profile</h3>
            <p class="mt-2 text-sm text-blue-200">Keep this student and all attendance records, but prevent future
                attendance scans.</p><button type="button" id="archive-button"
                class="mt-5 w-full rounded-xl border border-amber-300/40 px-4 py-3 font-bold text-amber-200 hover:bg-amber-500/20">Archive
                student</button>
        </section>
    </aside>
</div>
<script>
    const placementOptions = <?php echo json_encode($placement, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        savedSection = <?php echo json_encode($row["SECTION"], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        savedStrand = <?php echo json_encode($row["STRAND"] ?? "", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const gradeSelect = document.getElementById('student-grade'),
        sectionSelect = document.getElementById('student-section'),
        strandSelect = document.getElementById('student-strand'),
        sectionField = document.getElementById('section-field'),
        strandField = document.getElementById('strand-field');

    function setOptions(select, placeholder, items, value = '') {
        select.innerHTML = '';
        select.add(new Option(placeholder, ''));
        items.forEach(item => select.add(new Option(item, item, item === value, item === value)))
    }

    function updatePlacement(restore = false) {
        const grade = gradeSelect.value,
            senior = grade === '11' || grade === '12';
        strandField.classList.toggle('hidden', !senior);
        strandSelect.disabled = !senior;
        strandSelect.required = senior;
        sectionField.classList.toggle('sm:col-span-2', !senior);
        if (!senior) {
            strandSelect.value = '';
            setOptions(sectionSelect, grade ? 'Choose a section' : 'Choose a grade first', placementOptions[grade] ||
            [], restore ? savedSection : '');
            sectionSelect.disabled = !grade;
            return
        }
        setOptions(strandSelect, 'Choose a strand', ['Techpro', 'Academic'], restore ? savedStrand : '');
        updateSeniorSections(restore)
    }

    function updateSeniorSections(restore = false) {
        const sections = placementOptions[gradeSelect.value]?.[strandSelect.value] || [];
        setOptions(sectionSelect, strandSelect.value ? 'Choose a section' : 'Choose a strand first', sections, restore ?
            savedSection : '');
        sectionSelect.disabled = !strandSelect.value
    }
    gradeSelect.onchange = () => updatePlacement();
    strandSelect.onchange = () => updateSeniorSections();
    updatePlacement(true);
    document.getElementById('student_image').onchange = e => {
        if (e.target.files[0]) document.getElementById('main-preview').src = URL.createObjectURL(e.target.files[0])
    };
</script>
<script>
    document.getElementById('student_image').addEventListener('change', event => {
        const file = event.target.files[0];
        document.getElementById('student-image-file-name').textContent = file ? file.name : 'No file chosen'
    });
</script>
<?php adminFoot(); ?>
