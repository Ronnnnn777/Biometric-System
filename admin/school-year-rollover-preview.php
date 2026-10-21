<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/csrf-token.php";
require "../includes/school-year-schema.php";
require "../includes/student-promotion-schema.php";
require "../includes/student-placement-options.php";
require "../includes/section-catalog-schema.php";
require "../includes/sectioning.php";
require "components.php";

ensureSchoolYearRolloverSchema($conn);
ensureStudentPromotionSchema($conn);
ensureSectionCatalogColumns($conn);
seedDefaultTeacherSections($conn);

$setting = $conn->query("SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1")->fetch_assoc();
$currentYear = (string) ($setting["ACTIVE_SCHOOL_YEAR"] ?? "");
$parts = array_map("intval", explode("-", $currentYear));
$nextYear = count($parts) === 2 ? ($parts[0] + 1) . "-" . ($parts[1] + 1) : "";
$defaultCapacity = defaultSectionCapacity($conn);

$planRows = $conn->query(
    "SELECT FROM_GRADE,FROM_STRAND,TO_GRADE,TO_STRAND,SPLIT_PERCENT
       FROM school_year_promotion_plan
      ORDER BY FROM_GRADE,FROM_STRAND,TO_STRAND",
);
$savedPlans = [];
while ($planRows && ($plan = $planRows->fetch_assoc())) {
    $savedPlans[$plan["FROM_GRADE"] . "|" . $plan["FROM_STRAND"]][] = $plan;
}

$failedByStudent = [];
$failedQuery = $conn->prepare(
    "SELECT STUDENT_ID, COUNT(DISTINCT SUBJECT) FAILED_SUBJECTS
       FROM student_subject_results
      WHERE SCHOOL_YEAR=? AND RESULT='Failed'
      GROUP BY STUDENT_ID",
);
$failedQuery->bind_param("s", $currentYear);
$failedQuery->execute();
$failedRows = $failedQuery->get_result();
while ($failedRows && ($failedRow = $failedRows->fetch_assoc())) {
    $failedByStudent[(string) $failedRow["STUDENT_ID"]] = (int) $failedRow["FAILED_SUBJECTS"];
}

$sources = [];
$sourceRows = $conn->query(
    "SELECT GRADE, STRAND FROM students
      WHERE ENROLLMENT_STATUS='Enrolled' AND GRADE BETWEEN 7 AND 11
      GROUP BY GRADE, STRAND
      ORDER BY GRADE, STRAND",
);
$studentQuery = $conn->prepare(
    "SELECT STUDENT_ID,LAST_NAME,FIRST_NAME,SECTION
       FROM students
      WHERE GRADE=? AND STRAND=? AND ENROLLMENT_STATUS='Enrolled'
      ORDER BY SECTION,LAST_NAME,FIRST_NAME,STUDENT_ID",
);

while ($sourceRows && ($row = $sourceRows->fetch_assoc())) {
    $fromGrade = (int) $row["GRADE"];
    $fromStrand = (string) $row["STRAND"];
    $toGrade = $fromGrade + 1;
    $targetStrands = studentStrandsForGrade($toGrade);
    $saved = $savedPlans[$fromGrade . "|" . $fromStrand] ?? [];

    // Target percentages per strand; Grade 10 -> 11 may split across both.
    $targets = [];
    if ($targetStrands) {
        if ($fromGrade === 10) {
            foreach ($saved as $plan) {
                $strand = (string) $plan["TO_STRAND"];
                if (in_array($strand, $targetStrands, true)) {
                    $targets[$strand] = (int) $plan["SPLIT_PERCENT"];
                }
            }
            if (!$targets) {
                $targets["Academic"] = 100;
            }
            foreach ($targetStrands as $strand) {
                $targets[$strand] = $targets[$strand] ?? 0;
            }
        } else {
            $carry = in_array($fromStrand, $targetStrands, true) ? $fromStrand : $targetStrands[0];
            foreach ($saved as $plan) {
                $strand = (string) $plan["TO_STRAND"];
                if (in_array($strand, $targetStrands, true)) {
                    $carry = $strand;
                    break;
                }
            }
            $targets[$carry] = 100;
        }
    } else {
        $targets[""] = 100;
    }

    $studentQuery->bind_param("is", $fromGrade, $fromStrand);
    $studentQuery->execute();
    $students = $studentQuery->get_result();
    $promotedIds = [];
    $summer = 0;
    $retained = 0;
    while ($students && ($student = $students->fetch_assoc())) {
        $decision = studentPromotionDecision((int) ($failedByStudent[(string) $student["STUDENT_ID"]] ?? 0));
        if ($decision === "Promoted") {
            $promotedIds[] = (string) $student["STUDENT_ID"];
        } elseif ($decision === "Summer class required") {
            $summer++;
        } else {
            $retained++;
        }
    }

    $chunks = splitStudentsByWeights($promotedIds, array_values($targets));
    $targetDetails = [];
    $targetIndex = 0;
    foreach ($targets as $strand => $percent) {
        $chunk = $chunks[$targetIndex] ?? [];
        $targetSections = orderedTargetSections($conn, $toGrade, $strand);
        $targetDetails[] = [
            "strand" => $strand,
            "percent" => (int) $percent,
            "count" => count($chunk),
            "groups" => sectionPlanForBatch($chunk, $targetSections, $toGrade, $strand, $defaultCapacity),
        ];
        $targetIndex++;
    }

    $sources[] = [
        "from_grade" => $fromGrade,
        "from_strand" => $fromStrand,
        "to_grade" => $toGrade,
        "promoted" => count($promotedIds),
        "summer" => $summer,
        "retained" => $retained,
        "is_split_grade" => $fromGrade === 10,
        "targets" => $targetDetails,
    ];
}

$grade12Query = $conn->query("SELECT STUDENT_ID FROM students WHERE GRADE=12 AND ENROLLMENT_STATUS='Enrolled'");
$grade12Graduates = 0;
$grade12Summer = 0;
$grade12Retained = 0;
while ($grade12Query && ($grade12 = $grade12Query->fetch_assoc())) {
    $decision = studentPromotionDecision((int) ($failedByStudent[(string) $grade12["STUDENT_ID"]] ?? 0));
    if ($decision === "Promoted") {
        $grade12Graduates++;
    } elseif ($decision === "Summer class required") {
        $grade12Summer++;
    } else {
        $grade12Retained++;
    }
}

adminHead("Confirm school year rollover");
adminSidebar(
    "rollover",
    "Confirm school year rollover",
    "Review the projected grade promotion and section assignments before student records change.",
);
?>
<section class="card card-body mx-auto max-w-5xl">
    <h2 class="text-2xl font-black text-slate-900">Rollover preview</h2>
    <p class="mt-2 text-slate-500">Current school year: <strong><?= htmlspecialchars($currentYear) ?></strong> · Next
        school year: <strong><?= htmlspecialchars($nextYear) ?></strong></p>
    <p class="mt-1 text-sm text-slate-500">Each batch is chunked at <strong><?= (int) $defaultCapacity ?></strong> students
        per section, spilling into newly created sections when needed.</p>

    <?php if (!$sources): ?>
    <div class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-5 text-sm text-slate-600">No enrolled students in
        Grades 7–11 are available for promotion.</div>
    <?php else: ?>
    <div class="mt-6 space-y-4">
        <?php foreach ($sources as $source): ?>
        <div class="rounded-xl border border-slate-200 p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="font-black text-slate-900">Grade <?= (int) $source["from_grade"] ?><?= $source["from_strand"] !== "" ? " · " . htmlspecialchars($source["from_strand"]) : "" ?>
                    <span class="text-slate-400">&rarr;</span> Grade <?= (int) $source["to_grade"] ?></h3>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-500"><?= (int) $source["promoted"] ?>
                    promoted · <?= (int) $source["summer"] ?> summer · <?= (int) $source["retained"] ?> retained</p>
            </div>
            <div class="mt-3 space-y-3">
                <?php foreach ($source["targets"] as $target): ?>
                <?php if ((int) $target["percent"] <= 0) continue; ?>
                <div>
                    <?php if ($source["is_split_grade"]): ?>
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500"><?= htmlspecialchars($target["strand"]) ?> · <?= (int) $target["percent"] ?>%</p>
                    <?php endif; ?>
                    <?php if (!$target["groups"]): ?>
                    <p class="mt-1 text-sm text-slate-500">No promoted students in this batch.</p>
                    <?php else: ?>
                    <ul class="mt-2 grid gap-2 sm:grid-cols-2">
                        <?php foreach ($target["groups"] as $group): ?>
                        <li class="rounded-lg bg-slate-50 px-4 py-3 text-sm">
                            <span class="font-bold text-slate-900"><?= htmlspecialchars($group["section"]["SECTION"]) ?></span>
                            <span class="text-slate-600">· <?= count($group["students"]) ?> student<?= count($group["students"]) === 1 ? "" : "s" ?></span>
                            <?php if (!empty($group["section"]["VIRTUAL"])): ?><span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800">will be created</span><?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
        <strong><?= $grade12Graduates ?></strong> enrolled Grade 12 student<?= $grade12Graduates === 1 ? "" : "s" ?>
        will be marked <strong>Graduated</strong> and moved to the archive.
        <?php if ($grade12Summer || $grade12Retained): ?>
        <?= $grade12Summer ?> require summer class and <?= $grade12Retained ?> will be retained.
        <?php endif; ?>
        Teacher assignments are not changed automatically.
    </div>

    <form action="run-school-year-rollover.php" method="post"
        class="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-5">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">
        <?php foreach ($sources as $index => $source): ?>
        <input type="hidden" name="plans[<?= $index ?>][from_grade]" value="<?= (int) $source["from_grade"] ?>">
        <input type="hidden" name="plans[<?= $index ?>][from_strand]" value="<?= htmlspecialchars($source["from_strand"]) ?>">
        <?php foreach ($source["targets"] as $targetIndex => $target): ?>
        <input type="hidden" name="plans[<?= $index ?>][targets][<?= $targetIndex ?>][strand]" value="<?= htmlspecialchars($target["strand"]) ?>">
        <input type="hidden" name="plans[<?= $index ?>][targets][<?= $targetIndex ?>][percent]" value="<?= (int) $target["percent"] ?>">
        <?php endforeach; ?>
        <?php endforeach; ?>
        <h3 class="font-black text-rose-950">Final confirmation</h3>
        <p class="mt-1 text-sm text-rose-800">This updates student grade/section placements, auto-sections each batch at
            <?= (int) $defaultCapacity ?> students per section, and archives graduating Grade 12 students. Use it only after
            the school year has ended.</p>
        <label class="mt-4 flex items-start gap-3 text-sm font-bold text-rose-950"><input name="end_school_year_confirmation" value="1" type="checkbox" required class="mt-1 h-4 w-4">I confirm that the school year has ended and eligible Grade 12 students have graduated.</label>
        <label class="mt-4 block max-w-md text-sm font-bold text-rose-950">Administrator password<input
                name="current_password" type="password" required autocomplete="current-password"
                class="mt-2 w-full rounded-xl border border-rose-300 bg-white px-4 py-3 text-slate-900"></label>
        <button class="btn btn-danger mt-5">Confirm <?= htmlspecialchars($currentYear) ?> rollover</button>
    </form>
</section>
<?php adminFoot(); ?>
