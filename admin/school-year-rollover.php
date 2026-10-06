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

adminHead('School year rollover');
adminSidebar('rollover','School year rollover','Map each current class to its official next-school-year class before confirming promotion.');
?>
<section class="mx-auto max-w-5xl rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 sm:p-8">
  <h2 class="text-2xl font-black text-slate-900">School Year Rollover</h2>
  <p class="mt-2 text-slate-500">Set the official destination for every class, then save all mappings together. This page only saves the plan; it does not move students yet.</p>
  <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><strong>Important:</strong> Grade 12 students will be handled as Graduated in the final confirmation step. Review every mapping with the official section list before promotion.</div>

  <form action="save-school-year-rollover-map.php" method="post" class="mt-7">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <div class="overflow-x-auto">
      <table class="min-w-full text-left text-sm">
        <thead class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500"><tr><th class="px-4 py-3">Current class</th><th class="px-4 py-3">Students</th><th class="px-4 py-3">Next school year class</th></tr></thead>
        <tbody class="divide-y divide-slate-100">
          <?php foreach($sources as $index=>$source):
            $key=$source['GRADE'].'|'.$source['STRAND'].'|'.$source['SECTION'];
            $map=$maps[$key]??null;
            $nextGrade=(int)$source['GRADE']+1;
          ?>
            <tr>
              <td class="px-4 py-4 font-bold text-slate-900"><input type="hidden" name="mappings[<?= $index ?>][from_grade]" value="<?= (int)$source['GRADE'] ?>"><input type="hidden" name="mappings[<?= $index ?>][from_strand]" value="<?= htmlspecialchars($source['STRAND']) ?>"><input type="hidden" name="mappings[<?= $index ?>][from_section]" value="<?= htmlspecialchars($source['SECTION']) ?>">Grade <?= (int)$source['GRADE'] ?><?= $source['STRAND']?' · '.htmlspecialchars($source['STRAND']):'' ?> · <?= htmlspecialchars($source['SECTION']) ?></td>
              <td class="px-4 py-4 text-slate-600"><?= (int)$source['STUDENTS'] ?></td>
              <td class="px-4 py-4">
                <select name="mappings[<?= $index ?>][target]" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2" required>
                  <option value="">Choose Grade <?= $nextGrade ?> section</option>
                  <?php foreach($targets as $target): if((int)$target['GRADE']!==$nextGrade) continue;
                    $value=$target['GRADE'].'|'.$target['STRAND'].'|'.$target['SECTION'];
                    $selected=$map&&$map['TO_GRADE']==$target['GRADE']&&$map['TO_STRAND']===$target['STRAND']&&$map['TO_SECTION']===$target['SECTION'];
                  ?>
                    <option value="<?= htmlspecialchars($value) ?>" <?= $selected?'selected':'' ?>>Grade <?= (int)$target['GRADE'] ?><?= $target['STRAND']?' · '.htmlspecialchars($target['STRAND']):'' ?> · <?= htmlspecialchars($target['SECTION']) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="mt-6 flex justify-end"><button class="rounded-xl bg-blue-600 px-5 py-3 font-bold text-white hover:bg-blue-700">Save all mappings</button></div>
  </form>
</section>
<div class="mx-auto mt-6 flex max-w-5xl justify-end"><a href="school-year-rollover-preview.php" class="rounded-xl bg-rose-600 px-5 py-3 font-bold text-white hover:bg-rose-700">Review and confirm rollover</a></div>
<?php adminFoot(); ?>
