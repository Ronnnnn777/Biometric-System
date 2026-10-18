<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/csrf-token.php";
require "../includes/school-year-schema.php";
require "components.php";
ensureSchoolYearRolloverSchema($conn);
$setting = $conn
    ->query("SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1")
    ->fetch_assoc();
$currentYear = $setting["ACTIVE_SCHOOL_YEAR"] ?? "";
$parts = array_map("intval", explode("-", $currentYear));
$nextYear = count($parts) === 2 ? $parts[0] + 1 . "-" . ($parts[1] + 1) : "";
$maps = [];
$mapRows = $conn->query("SELECT * FROM school_year_rollover_maps");
while ($mapRows && ($map = $mapRows->fetch_assoc())) {
    $maps[
        $map["FROM_GRADE"] .
            "|" .
            $map["FROM_STRAND"] .
            "|" .
            $map["FROM_SECTION"]
    ] = $map;
}
$sources = [];
$missing = [];
$sourceRows = $conn->query(
    "SELECT c.GRADE,c.STRAND,c.SECTION,COUNT(s.ID) STUDENTS FROM sections c LEFT JOIN students s ON s.GRADE=c.GRADE AND s.STRAND=c.STRAND AND s.SECTION=c.SECTION AND s.ENROLLMENT_STATUS='Enrolled' WHERE c.IS_ACTIVE=1 AND c.GRADE BETWEEN 7 AND 11 GROUP BY c.GRADE,c.STRAND,c.SECTION ORDER BY c.GRADE,c.STRAND,c.SECTION",
);
while ($sourceRows && ($source = $sourceRows->fetch_assoc())) {
    $key =
        $source["GRADE"] . "|" . $source["STRAND"] . "|" . $source["SECTION"];
    $source["MAP"] = $maps[$key] ?? null;
    $sources[] = $source;
    if (!$source["MAP"]) {
        $missing[] = $source;
    }
}
$graduates =
    (int) ($conn
        ->query(
            "SELECT COUNT(*) TOTAL FROM students WHERE ENROLLMENT_STATUS='Enrolled' AND GRADE=12",
        )
        ->fetch_assoc()["TOTAL"] ?? 0);
adminHead("Confirm school year rollover");
adminSidebar(
    "rollover",
    "Confirm school year rollover",
    "Review the official class mappings before student records are promoted.",
);
?>
<section class="mx-auto max-w-5xl rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 sm:p-8"><a
        href="school-year-rollover.php" class="text-sm font-bold text-blue-600">Ã¢â€ Â Back to mappings</a>
    <h2 class="mt-4 text-2xl font-black text-slate-900">Rollover preview</h2>
    <p class="mt-2 text-slate-500">Current school year: <strong><?= htmlspecialchars(
    $currentYear,
) ?></strong> Ã‚Â· Next school year: <strong><?= htmlspecialchars(
    $nextYear,
) ?></strong></p><?php if (
    $missing
): ?><div class="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-5 text-rose-900">
        <h3 class="font-black">Complete every class mapping first</h3>
        <p class="mt-1 text-sm">The following classes have enrolled students but no next-school-year section yet:</p>
        <ul class="mt-3 list-disc space-y-1 pl-5 text-sm"><?php foreach (
    $missing
    as $source
): ?><li>Grade <?=
 (int) $source["GRADE"] . (
$source["STRAND"] ? " Ã‚Â· " . htmlspecialchars($source["STRAND"]) : ""
) ?> Ã‚Â· <?= htmlspecialchars($source["SECTION"]) ?> (<?= (int) $source[
     "STUDENTS"
 ] ?> students)</li><?php endforeach; ?></ul>
    </div><?php else: ?><div class="mt-6 overflow-x-auto">
        <table class="min-w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500">
                <tr>
                    <th class="px-4 py-3">Current class</th>
                    <th class="px-4 py-3">Students</th>
                    <th class="px-4 py-3">Promote to</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100"><?php foreach (
    $sources
    as $source
):
    $map = $source["MAP"]; ?><tr>
                    <td class="px-4 py-4 font-bold">Grade <?=
(int) $source["GRADE"] . (
$source["STRAND"] ? " Ã‚Â· " . htmlspecialchars($source["STRAND"]) : ""
) ?> Ã‚Â· <?= htmlspecialchars(
     $source["SECTION"],
 ) ?></td>
                    <td class="px-4 py-4"><?= (int) $source[
    "STUDENTS"
] ?></td>
                    <td class="px-4 py-4 text-emerald-700">Grade <?=
(int) $map["TO_GRADE"] . (
$map["TO_STRAND"] ? " Ã‚Â· " . htmlspecialchars($map["TO_STRAND"]) : ""
) ?> Ã‚Â· <?= htmlspecialchars($map["TO_SECTION"]) ?></td>
                </tr><?php
endforeach; ?></tbody>
        </table>
    </div>
    <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
        <strong><?= $graduates ?></strong> enrolled Grade 12 student<?= $graduates ===
1
    ? ""
    : "s" ?> will be marked <strong>Graduated</strong> and moved to the archive. Teacher assignments are not changed automatically.</div>
    <form action="run-school-year-rollover.php" method="post"
        class="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-5"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(
     $_SESSION["csrf_token"],
 ) ?>">
        <h3 class="font-black text-rose-950">Final confirmation</h3>
        <p class="mt-1 text-sm text-rose-800">This updates student grade/section placements and archives graduating
            Grade 12 students. Use it only after the school year has ended.</p><label class="mt-4 flex items-start gap-3 text-sm font-bold text-rose-950"><input name="end_school_year_confirmation" value="1" type="checkbox" required class="mt-1 h-4 w-4">I confirm that the school year has ended and eligible Grade 12 students have graduated.</label><label class="mt-4 block max-w-md text-sm font-bold text-rose-950">Administrator
            password<input name="current_password" type="password" required autocomplete="current-password"
                class="mt-2 w-full rounded-xl border border-rose-300 bg-white px-4 py-3 text-slate-900"></label><button
            class="mt-5 rounded-xl bg-rose-600 px-5 py-3 font-bold text-white hover:bg-rose-700">Confirm <?= htmlspecialchars(
    $currentYear,
) ?> rollover</button>
    </form><?php endif; ?>
</section>
<?php adminFoot(); ?>
