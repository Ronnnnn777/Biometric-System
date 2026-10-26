<?php
session_start();
require "../includes/csrf-token.php";
require "page-guard.php";
require "components.php";
$result = $_SESSION["student-import-result"] ?? null;
unset($_SESSION["student-import-result"]);
adminHead("Import students");
adminSidebar(
    "students",
    "Import students",
    "Add student records from a spreadsheet",
);
?>
<div class="mx-auto max-w-4xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-2xl font-black text-slate-900">Import student records</h2>
            <p class="mt-1 text-slate-500">Add many student profiles from one CSV or Excel spreadsheet.</p>
        </div>
        <a href="student-list.php"
            class="rounded-xl border border-slate-300 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50">Back
            to students</a>
    </div>

    <?php if (!empty($result["error"])): ?>
    <div class="rounded-2xl border border-rose-200 bg-rose-50 p-5 text-rose-800">
        <p class="font-bold">Import could not start</p>
        <p class="mt-1 text-sm"><?= htmlspecialchars(
        $result["error"],
    ) ?></p>
    </div>
    <?php elseif ($result): ?>
    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-900">
        <p class="font-bold"><?= (int) ($result[
        "inserted"
    ] ?? 0) ?> student<?= (int) ($result["inserted"] ?? 0) === 1
     ? ""
     : "s" ?> imported.</p>
        <p class="mt-1 text-sm">Each imported student uses the placeholder photo until an administrator adds their
            biometric face image.</p><?php if (
     !empty($result["skipped"])
 ): ?><details class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-900">
            <summary class="cursor-pointer font-bold"><?= count(
    $result["skipped"],
) ?> row<?= count($result["skipped"]) === 1
     ? ""
     : "s" ?> skipped — review reasons</summary>
            <ul class="mt-3 list-disc space-y-1 pl-5 text-sm"><?php
 foreach (
     array_slice($result["skipped"], 0, 50)
     as $message
 ): ?><li><?= htmlspecialchars($message) ?></li><?php endforeach;
 if (
     count($result["skipped"]) > 50
 ): ?><li>Only the first 50 skipped rows are shown.</li><?php endif;
 ?></ul>
        </details><?php endif; ?>
    </div>
    <?php endif; ?>

    <section class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 sm:p-8">
        <p class="text-sm font-bold uppercase tracking-widest text-blue-600">Step 1</p>
        <h3 class="mt-2 text-xl font-black text-slate-900">Prepare the spreadsheet</h3>
        <p class="mt-2 text-slate-600">Use CSV or Excel <code class="rounded bg-slate-100 px-1.5 py-0.5">.xlsx</code>,
            with a header row. Format LRN cells as text if they have leading zeroes. Download the sample and
            replace its example rows.</p>
        <p class="mt-3 rounded-xl border border-blue-200 bg-blue-50 p-3 text-sm leading-6 text-blue-900"><strong>Maruhat
                School Form/ECR supported:</strong> the importer can also read the learner list from the official
            worksheet and automatically use its LRN, name, birthdate, grade level, and section.</p>
        <div class="mt-5 overflow-x-auto rounded-xl border border-slate-200">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-3">LRN</th>
                        <th class="px-4 py-3">First Name</th>
                        <th class="px-4 py-3">Last Name</th>
                        <th class="px-4 py-3">Grade</th>
                        <th class="px-4 py-3">Age</th>
                        <th class="px-4 py-3">Strand</th>
                        <th class="px-4 py-3">Address</th>
                        <th class="px-4 py-3">Section</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="px-4 py-3">2026-0001</td>
                        <td class="px-4 py-3">Juan</td>
                        <td class="px-4 py-3">Dela Cruz</td>
                        <td class="px-4 py-3">7</td>
                        <td class="px-4 py-3">12</td>
                        <td class="px-4 py-3">Leave blank</td>
                        <td class="px-4 py-3">Barangay Example</td>
                        <td class="px-4 py-3">Camia</td>
                        <td class="px-4 py-3">Enrolled</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3">2026-0002</td>
                        <td class="px-4 py-3">Ana</td>
                        <td class="px-4 py-3">Santos</td>
                        <td class="px-4 py-3">11</td>
                        <td class="px-4 py-3">16</td>
                        <td class="px-4 py-3">Academic</td>
                        <td class="px-4 py-3">Barangay Example</td>
                        <td class="px-4 py-3">Kepler</td>
                        <td class="px-4 py-3">Enrolled</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-sm text-slate-500">The optional Status column accepts: Enrolled, Transferred, Dropped, or
            Archived. Leave it blank to use Enrolled. Grade 12 graduation is handled only by the school-year rollover.</p>
        <a href="student-import-template.csv" download
            class="mt-5 inline-flex rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm font-bold text-blue-700 hover:bg-blue-100">Download
            CSV template</a>
    </section>

    <section class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 sm:p-8">
        <p class="text-sm font-bold uppercase tracking-widest text-blue-600">Step 2</p>
        <h3 class="mt-2 text-xl font-black text-slate-900">Upload and import</h3>
        <form action="import-students-save.php" method="post" enctype="multipart/form-data" class="mt-6 space-y-5">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(
          $_SESSION["csrf_token"],
      ) ?>">
            <div class="rounded-xl border border-slate-200 p-4">
                <div>
                    <p class="text-sm font-bold text-slate-700">Student spreadsheet</p>
                    <p class="mt-1 text-sm text-slate-500">Choose the completed CSV or Excel .xlsx file to import.</p>
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-3"><input class="sr-only" type="file"
                        name="student_file" id="student_file"
                        accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        required><label for="student_file"
                        class="cursor-pointer rounded-lg bg-blue-50 px-4 py-2 text-sm font-bold text-blue-700 hover:bg-blue-100">Choose
                        file</label><span id="student-file-name"
                        class="min-w-0 max-w-full truncate text-sm font-semibold text-slate-500">No file chosen</span>
                </div>
            </div>
            <p class="text-sm leading-6 text-slate-500">Maximum 1,000 rows and 5 MB. LRNs already in the system
                are skipped. Legacy <code class="rounded bg-slate-100 px-1.5 py-0.5">.xls</code> files must be saved as
                CSV or <code class="rounded bg-slate-100 px-1.5 py-0.5">.xlsx</code> first.</p>
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900">
                <strong>Biometric photo required later:</strong> importing creates student profiles with a placeholder
                image. Open each profile afterward to upload a unique, clear face photo before using the scanner.</div>
            <button
                class="w-full rounded-xl bg-blue-600 px-5 py-3 font-bold text-white shadow-sm hover:bg-blue-700">Import
                students</button>
        </form>
    </section>
</div>
<script>
    document.getElementById('student_file').addEventListener('change', event => {
        const file = event.target.files[0],
            name = document.getElementById('student-file-name');
        name.textContent = file ? file.name : 'No file chosen';
        name.className = 'min-w-0 max-w-full truncate text-sm font-semibold ' + (file ? 'text-emerald-600' :
            'text-slate-500')
    });
</script>
<?php adminFoot(); ?>
