<?php
require_once __DIR__.'/../includes/promotion-planner.php';
require_once __DIR__.'/../includes/promotion-review.php';
$tests=0;
function checkPromotion(string $name, callable $test): void {
    global $tests; $test(); $tests++; echo "PASS $name\n";
}
function expectPromotion($actual,$expected): void {
    if ($actual!==$expected) throw new RuntimeException('Expected '.json_encode($expected).'; got '.json_encode($actual));
}
function fixtureStudent(string $id,int $grade=7,string $section='Camia',string $strand=''): array {
    return ['STUDENT_ID'=>$id,'STUDENT_NAME'=>'Student '.$id,'GRADE'=>$grade,'SECTION'=>$section,'STRAND'=>$strand,'ENROLLMENT_STATUS'=>'Enrolled','IS_ARCHIVED'=>0];
}
function fixtureResult(array $student,array $quarters,string $subject='Math'): array {
    $final=studentFinalGrade($quarters);
    return ['STUDENT_ID'=>$student['STUDENT_ID'],'SUBJECT'=>$subject,'GRADE'=>$student['GRADE'],'STRAND'=>$student['STRAND'],
        'Q1_GRADE'=>$quarters[0],'Q2_GRADE'=>$quarters[1],'Q3_GRADE'=>$quarters[2],'Q4_GRADE'=>$quarters[3],
        'FINAL_GRADE'=>$final,'RESULT'=>studentResultRemark($final)];
}
function fixtureContext(array $averages,array $capacities=[40,40,40]): array {
    $context=['year'=>'2026-2027','students'=>[],'results'=>[],'required'=>[['GRADE'=>7,'STRAND'=>'','SUBJECT'=>'Math']],
        'sections'=>[],'honors'=>[['GRADE'=>8,'STRAND'=>'','SECTION_ID'=>1]],'default'=>40];
    foreach ($averages as $index=>$average) {
        $student=fixtureStudent(sprintf('LRN%04d',$index)); $context['students'][]=$student;
        $context['results'][]=fixtureResult($student,is_array($average)?$average:array_fill(0,4,$average));
    }
    foreach ($capacities as $index=>$capacity) $context['sections'][]=['SECTION_ID'=>$index+1,'SECTION'=>['Amethyst','Emerald','Jade','Fourth'][$index] ?? 'Other'.$index,
        'GRADE'=>8,'STRAND'=>'','CAPACITY'=>$capacity,'SORT_ORDER'=>$index,'IS_ACTIVE'=>1];
    return $context;
}
function fixturePlan(array $context): array {return buildPromotionPlan($context,promotionValidatedTargets(promotionDefaultTargets($context['students'])));}
function sectionCounts(array $plan): array {
    $counts=[];foreach ($plan['destinations'] as $destination) foreach ($destination['groups'] as $group) $counts[$group['section']['SECTION']]=count($group['students']);return $counts;
}
foreach ([95=>true,90=>true,'89.99'=>false,'89.50'=>false] as $value=>$expected) checkPromotion('Honor threshold '.$value,function()use($value,$expected){
    $plan=fixturePlan(fixtureContext([(float)$value]));expectPromotion($plan['summary']['honor'],$expected?1:0);
});
checkPromotion('A rounded subject final of 90 does not round an 89.9975 average into honor',function(){
    $plan=fixturePlan(fixtureContext([[90,90,90,89.99]]));expectPromotion($plan['summary']['honor'],0);expectPromotion($plan['students']['LRN0000']['standing']['average'],89.9975);
});
checkPromotion('Only 15 honor qualifiers occupy honor seats',function(){
    $plan=fixturePlan(fixtureContext(array_merge(array_fill(0,15,95),array_fill(0,70,85))));
    expectPromotion(sectionCounts($plan),['Amethyst'=>15,'Emerald'=>35,'Jade'=>35]);
});
foreach ([70=>[35,35],71=>[36,35]] as $count=>$expected) checkPromotion("$count regular students balance across two sections",function()use($count,$expected){
    $counts=sectionCounts(fixturePlan(fixtureContext(array_fill(0,$count,85))));expectPromotion([$counts['Emerald'],$counts['Jade']],$expected);expectPromotion($counts['Amethyst'],0);
});
checkPromotion('90 regular students across three regular sections balance 30/30/30',function(){
    $context=fixtureContext(array_fill(0,90,85));$context['honors']=[];
    expectPromotion(array_values(sectionCounts(fixturePlan($context))),[30,30,30]);
});
checkPromotion('100 regular students create one regular overflow section and balance 34/33/33',function(){
    $plan=fixturePlan(fixtureContext(array_fill(0,100,85)));
    expectPromotion(sectionCounts($plan),['Amethyst'=>0,'Emerald'=>34,'Jade'=>33,'Grade 8 - Section 4'=>33]);
});
checkPromotion('Honor overflow is ranked by average and flagged without regular substitution',function(){
    $plan=fixturePlan(fixtureContext([90,95,93,95],[2,40,40]));
    expectPromotion($plan['blocked'],true);expectPromotion($plan['destinations']['8|']['groups'][0]['students'],['LRN0001','LRN0003']);
    expectPromotion(count($plan['reviews']),2);expectPromotion(sectionCounts($plan),['Amethyst'=>2,'Emerald'=>0,'Jade'=>0]);
});
checkPromotion('Different section capacities are respected',function(){
    $plan=fixturePlan(fixtureContext(array_fill(0,51,85),[40,10,40]));
    foreach ($plan['destinations']['8|']['groups'] as $group) if(count($group['students'])>sectionCapacity($group['section']))throw new RuntimeException('Capacity exceeded');
    expectPromotion(array_values(sectionCounts($plan)),[0,10,21,20]);
});
checkPromotion('Missing quarters and missing subjects are incomplete',function(){
    $context=fixtureContext([[95,95,95,null]]);$plan=fixturePlan($context);expectPromotion($plan['summary']['incomplete'],1);expectPromotion($plan['summary']['honor'],0);
    $context['required'][]=['GRADE'=>7,'STRAND'=>'','SUBJECT'=>'Science'];expectPromotion(fixturePlan($context)['blocked'],true);
});
checkPromotion('Missing curriculum and inconsistent final grades require review',function(){
    $context=fixtureContext([95]);$context['required']=[];expectPromotion(fixturePlan($context)['blocked'],true);
    $context=fixtureContext([95]);$context['results'][0]['FINAL_GRADE']=90;expectPromotion(fixturePlan($context)['blocked'],true);
});
checkPromotion('Retained and summer-class rules remain separate from sectioning',function(){
    foreach ([2=>'Summer class required',3=>'Retained'] as $failed=>$expected) {
        $student=fixtureStudent('RETAIN');$results=[];$required=[];
        foreach (range(1,8) as $subject) {$required[]='Subject'.$subject;$results[]=fixtureResult($student,array_fill(0,4,$subject<=$failed?70:95),'Subject'.$subject);}
        $standing=promotionAcademicStanding($student,$results,$required);expectPromotion($standing['decision'],$expected);expectPromotion($standing['honor'],false);
    }
});
checkPromotion('Archived and transferred students are excluded',function(){
    $context=fixtureContext([95,95]);$context['students'][0]['ENROLLMENT_STATUS']='Archived';$context['students'][1]['ENROLLMENT_STATUS']='Transferred';
    expectPromotion(fixturePlan($context)['summary']['promoted'],0);
});
checkPromotion('Existing retained occupants count toward balanced populations',function(){
    $context=fixtureContext(array_fill(0,70,85));$staying=fixtureStudent('STAY',8,'Emerald');
    $context['students'][]=$staying;
    foreach ([1,2,3] as $n) {$context['required'][]=['GRADE'=>8,'STRAND'=>'','SUBJECT'=>'Fail'.$n];$context['results'][]=fixtureResult($staying,[70,70,70,70],'Fail'.$n);}
    $plan=fixturePlan($context);$counts=sectionCounts($plan);expectPromotion($counts['Emerald']+1,36);expectPromotion($counts['Jade'],35);expectPromotion($plan['students']['STAY']['placement'],null);
});
checkPromotion('No inferred honor policy for senior high; strands stay separate',function(){
    $context=fixtureContext([]);$context['honors']=[];$context['sections']=[];
    foreach (['Academic','Techpro'] as $index=>$strand) {
        $student=fixtureStudent('SHS'.$index,11,'Source',$strand);$context['students'][]=$student;$context['results'][]=fixtureResult($student,[95,95,95,95]);
        $context['required'][]=['GRADE'=>11,'STRAND'=>$strand,'SUBJECT'=>'Math'];
        $context['sections'][]=['SECTION_ID'=>100+$index,'SECTION'=>'Section '.$strand,'GRADE'=>12,'STRAND'=>$strand,'CAPACITY'=>40,'SORT_ORDER'=>$index,'IS_ACTIVE'=>1];
    }
    $plan=fixturePlan($context);expectPromotion($plan['summary']['honor'],0);expectPromotion($plan['students']['SHS0']['placement']['STRAND'],'Academic');expectPromotion($plan['students']['SHS1']['placement']['STRAND'],'Techpro');
});
checkPromotion('Repeat calculations against unchanged inputs produce identical assignments',function(){
    $context=fixtureContext(array_merge(array_fill(0,15,95),array_fill(0,100,85)));expectPromotion(fixturePlan($context),fixturePlan($context));
});
checkPromotion('Generated names skip active and inactive collisions',function(){
    $context=fixtureContext(array_fill(0,100,85));$context['sections'][]=['SECTION_ID'=>50,'SECTION'=>'Grade 8 - Section 4','GRADE'=>9,'STRAND'=>'','CAPACITY'=>40,'SORT_ORDER'=>50,'IS_ACTIVE'=>0];
    expectPromotion(isset(sectionCounts(fixturePlan($context))['Grade 8 - Section 5']),true);
});
foreach ([8=>9,9=>10] as $sourceGrade=>$destinationGrade) checkPromotion("Grade $sourceGrade to $destinationGrade uses its configured honor section",function()use($sourceGrade,$destinationGrade){
    $context=fixtureContext([95,89.99]);
    foreach ($context['students'] as &$student) $student['GRADE']=$sourceGrade;unset($student);
    foreach ($context['results'] as &$result) $result['GRADE']=$sourceGrade;unset($result);
    $context['required'][0]['GRADE']=$sourceGrade;$context['honors'][0]['GRADE']=$destinationGrade;
    foreach ($context['sections'] as &$section) $section['GRADE']=$destinationGrade;unset($section);
    $plan=fixturePlan($context);expectPromotion($plan['summary']['honor'],1);expectPromotion($plan['students']['LRN0000']['placement']['GRADE'],$destinationGrade);
});
checkPromotion('Names, original sections and input order cannot create honor eligibility',function(){
    $context=fixtureContext([85,95,90,89.99]);$before=fixturePlan($context);
    $context['students']=array_reverse($context['students']);
    foreach ($context['students'] as &$student) {$student['STUDENT_NAME']='ZZZ';$student['SECTION']='Different original section';}unset($student);
    $after=fixturePlan($context);
    expectPromotion($after['destinations']['8|']['groups'][0]['students'],$before['destinations']['8|']['groups'][0]['students']);
});
checkPromotion('A high average cannot bypass two failed subjects',function(){
    $student=fixtureStudent('HIGH');$required=[];$results=[];
    foreach (range(1,12) as $index) {$subject='Subject'.$index;$required[]=$subject;$results[]=fixtureResult($student,array_fill(0,4,$index<=2?70:100),$subject);}
    $standing=promotionAcademicStanding($student,$results,$required);expectPromotion($standing['average'],95);expectPromotion($standing['decision'],'Summer class required');expectPromotion($standing['honor'],false);
});
checkPromotion('Equivalent target order produces the same canonical targets',function(){
    $posted=[['from_grade'=>8,'from_strand'=>'','targets'=>[['strand'=>'','percent'=>100]]],['from_grade'=>7,'from_strand'=>'','targets'=>[['strand'=>'','percent'=>100]]]];
    expectPromotion(promotionValidatedTargets($posted),promotionValidatedTargets(array_reverse($posted)));
});
checkPromotion('Review pagination counts distinct students and missing records across all pages',function(){
    $context=fixtureContext(array_fill(0,40,95));$context['results']=[];
    $plan=fixturePlan($context);
    $plan['reviews'][]=$plan['reviews'][0];
    $review=promotionReviewPage($plan,0,'',2);
    expectPromotion($review['students'],40);expectPromotion($review['missing'],40);
    expectPromotion($review['ready'],0);expectPromotion($review['total'],40);
    expectPromotion(count($review['rows']),15);expectPromotion($review['pages'],3);
    expectPromotion(count(promotionReviewPage($plan,0,'',999)['rows']),10);
    expectPromotion(promotionReviewPage($plan,8)['total'],0);
    expectPromotion(promotionReviewPage($plan,7,'7||Camia')['total'],40);
    expectPromotion(promotionReviewPage($plan,7,'7|Academic|Camia')['total'],0);
    expectPromotion($plan['blocked'],true);
});
checkPromotion('Partial grades are incomplete records, not missing subjects or ready students',function(){
    $context=fixtureContext([[95,null,null,null],95]);$review=promotionReviewPage(fixturePlan($context));
    expectPromotion($review['missing'],0);expectPromotion($review['incomplete'],1);expectPromotion($review['ready'],1);
});
checkPromotion('Advisory ownership ignores shared subjects and counts subject gaps independently',function(){
    $context=fixtureContext([95]);
    $context['sections'][0]['IS_ACTIVE']=1;
    $teachers=[['TEACHER_ID'=>1,'TEACHER_NAME'=>'A','TEACHER_STATUS'=>'Active'],['TEACHER_ID'=>2,'TEACHER_NAME'=>'B','TEACHER_STATUS'=>'Active'],['TEACHER_ID'=>3,'TEACHER_NAME'=>'Archived','TEACHER_STATUS'=>'Archived']];
    $advisories=[['TEACHER_ID'=>1,'SECTION_ID'=>1,'RELEASED_AT'=>null],['TEACHER_ID'=>2,'SECTION_ID'=>2,'RELEASED_AT'=>null],['TEACHER_ID'=>3,'SECTION_ID'=>1,'RELEASED_AT'=>null]];
    $sections=array_slice($context['sections'],0,2);
    $subjects=[['TEACHER_ID'=>1,'GRADE'=>8,'STRAND'=>'','SECTION'=>'Amethyst','SUBJECT'=>'Filipino'],['TEACHER_ID'=>2,'GRADE'=>8,'STRAND'=>'','SECTION'=>'Emerald','SUBJECT'=>'Filipino'],['TEACHER_ID'=>1,'GRADE'=>7,'STRAND'=>'','SECTION'=>'Camia','SUBJECT'=>'Math']];
    $audit=teacherAssignmentDiagnostics($teachers,$advisories,$subjects,$sections,$context['students'],$context['required']);
    expectPromotion($audit['advisory_reviews'],[]);expectPromotion($audit['assigned_teachers'],2);
    $without=teacherAssignmentDiagnostics($teachers,$advisories,[],$sections,$context['students'],$context['required']);
    expectPromotion($without['advisory_reviews'],[]);expectPromotion(count($without['missing_subject_assignments']),1);
    $advisories[]=['TEACHER_ID'=>1,'SECTION_ID'=>2,'RELEASED_AT'=>null];
    expectPromotion(count(teacherAssignmentDiagnostics($teachers,$advisories,$subjects,$sections,[],[])['advisory_reviews']),2);
});
checkPromotion('Missing advisory ownership blocks rollover without changing student academic eligibility',function(){
    $context=fixtureContext([95]);$teachers=[];$advisories=[];
    foreach ([1,2,3] as $id) {
        $teachers[]=['TEACHER_ID'=>$id,'TEACHER_NAME'=>'Adviser '.$id,'TEACHER_STATUS'=>'Active'];
        $advisories[]=['TEACHER_ID'=>$id,'SECTION_ID'=>$id,'RELEASED_AT'=>null];
    }
    $context['assignments']=['teachers'=>$teachers,'advisories'=>$advisories,'subjects'=>[]];
    $valid=fixturePlan($context);expectPromotion($valid['blocked'],false);
    array_pop($context['assignments']['advisories']);$missing=fixturePlan($context);
    expectPromotion($missing['blocked'],true);expectPromotion($missing['students'],$valid['students']);
    expectPromotion($missing['summary']['incomplete'],0);expectPromotion($missing['summary']['honor'],1);
});
echo "$tests promotion/sectioning tests passed.\n";
