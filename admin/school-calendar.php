<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/csrf-token.php";
require "../includes/school-calendar-schema.php";
require "components.php";
ensureSchoolCalendarSchema($conn);

$rangeStart = date("Y-m-d", strtotime("-2 months"));
$rangeEnd = date("Y-m-d", strtotime("+10 months"));
$entries = schoolCalendarEntries($conn, $rangeStart, $rangeEnd);
$today = date("Y-m-d");
$todayInfo = schoolDayInfo($conn, $today);

adminHead("School calendar");
adminSidebar("school-calendar", "School calendar", "Manage holidays, suspensions, and makeup days.");
?>
<div class="card p-5">
    <div class="border-b border-slate-100 pb-5">
        <p class="text-slate-500">Attendance follows this calendar. Dates left unset default to Monday–Friday as
            school days and Saturday–Sunday as non-school days. A non-school day never creates an absence.</p>
        <p class="mt-2 text-sm font-bold text-slate-700">Today, <?= htmlspecialchars(date("F j, Y")) ?>:
            <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700"><?= htmlspecialchars(
                schoolCalendarTypeLabel($todayInfo["type"]),
            ) ?></span>
        </p>
    </div>

    <form action="school-calendar-save.php" method="post"
        class="mt-5 flex flex-wrap items-end gap-3">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">
        <input type="hidden" name="action" value="save">
        <label class="table-filter-control flex-1 text-sm font-bold text-slate-700">Date<input type="date"
                name="calendar_date" value="<?= htmlspecialchars($today) ?>" required
                class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3"></label>
        <label class="table-filter-control flex-1 text-sm font-bold text-slate-700">Day type<select name="day_type"
                class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3">
                <?php foreach (SCHOOL_CALENDAR_TYPES as $type): ?><option value="<?= htmlspecialchars(
                    $type,
                ) ?>"><?= htmlspecialchars(schoolCalendarTypeLabel($type)) ?></option><?php endforeach; ?>
            </select></label>
        <label class="table-filter-control flex-[2] text-sm font-bold text-slate-700">Label (optional)<input
                type="text" name="label" maxlength="120" placeholder="e.g. Araw ng Kagitingan, typhoon signal #2"
                class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3"></label>
        <button class="btn btn-primary">Save date</button>
    </form>

    <div class="mt-6 w-full overflow-x-auto">
        <table class="w-full text-left">
            <thead class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500">
                <tr>
                    <th class="px-4 py-3">Date</th>
                    <th class="px-4 py-3">Day</th>
                    <th class="px-4 py-3">Type</th>
                    <th class="px-4 py-3">Label</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (!$entries): ?>
                <tr>
                    <td colspan="5" class="px-4 py-12 text-center">
                        <p class="text-base font-black text-slate-800">No calendar exceptions recorded.</p>
                        <p class="mt-1 text-sm text-slate-500">Every Monday–Friday is treated as a school day until
                            you add a holiday, suspension, or special schedule.</p>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($entries as $entry):

                    $type = $entry["DAY_TYPE"];
                    $chip = match ($type) {
                        "school", "special" => "bg-emerald-50 text-emerald-700",
                        "holiday" => "bg-rose-50 text-rose-700",
                        "suspension" => "bg-amber-50 text-amber-700",
                        default => "bg-slate-100 text-slate-600",
                    };
                    ?>
                <tr>
                    <td class="px-4 py-3 font-bold text-slate-900"><?= htmlspecialchars($entry["CALENDAR_DATE"]) ?></td>
                    <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars(
                        date("l", strtotime($entry["CALENDAR_DATE"])),
                    ) ?></td>
                    <td class="px-4 py-3"><span
                            class="rounded-full px-3 py-1 text-xs font-bold <?= $chip ?>"><?= htmlspecialchars(
    schoolCalendarTypeLabel($type),
) ?></span></td>
                    <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars($entry["LABEL"] ?: "—") ?></td>
                    <td class="px-4 py-3">
                        <form action="school-calendar-save.php" method="post"
                            onsubmit="return confirm('Remove this calendar entry?')">
                            <input type="hidden" name="csrf_token"
                                value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="calendar_date"
                                value="<?= htmlspecialchars($entry["CALENDAR_DATE"]) ?>">
                            <button class="btn btn-secondary btn-sm">Remove</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php adminFoot(); ?>
