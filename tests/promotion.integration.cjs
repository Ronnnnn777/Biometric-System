// node tests/promotion.integration.cjs (Windows/XAMPP). No writes to the live database.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),assert=require('node:assert/strict'),vm=require('node:vm');
const {spawn,execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),php='C:/xampp/php/php.exe',mysql='C:/xampp/mysql/bin/mysql.exe',dump='C:/xampp/mysql/bin/mysqldump.exe';
const database='biometric_promotion_test_'+crypto.randomBytes(5).toString('hex'),site=path.join(root,'tmp',database),env={...process.env,PROMOTION_TEST_DATABASE:database};
fs.mkdirSync(site,{recursive:true});fs.mkdirSync(path.join(site,'sessions'));
const run=(exe,args,input)=>execFileSync(exe,args,{cwd:root,input,encoding:'utf8',maxBuffer:32*1024*1024,windowsHide:true});
const fixture=(action,input)=>execFileSync(php,['tests/promotion-fixture.php',action],{cwd:root,env,input,encoding:'utf8',maxBuffer:16*1024*1024,windowsHide:true});
const sql=query=>JSON.parse(fixture('sql',query));
const liveFingerprint=()=>crypto.createHash('sha256').update(run(dump,['-u','root','--single-transaction','--skip-comments','--no-create-info','biometric','students','student_subject_results','attendance','student_school_history','teachers','teacher_assignments','teacher_advisory_assignments'])).digest('hex');
const liveBefore=liveFingerprint();
run(mysql,['-u','root','-e',`CREATE DATABASE ${database} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci`]);
run(mysql,['-u','root',database],run(dump,['-u','root','--single-transaction','biometric']).replaceAll('`biometric`.','`'+database+'`.'));
// The local InsightFace virtual environment is generated tooling, not
// application source. Excluding it keeps disposable integration clones small.
for(const dir of ['admin','teacher','includes','config','js','css'])fs.cpSync(path.join(root,dir),path.join(site,dir),{recursive:true,filter:source=>!source.includes(path.sep+'.insightface-venv')});
const config=path.join(site,'config/db_conn.php');fs.writeFileSync(config,fs.readFileSync(config,'utf8').replace('$dbname="biometric";',`$dbname="${database}";`));
fixture('setup');
const authentication=JSON.parse(fixture('admin-session'));
const log=fs.openSync(path.join(site,'server.log'),'w');
const server=spawn(php,['-d','session.save_path='+path.join(site,'sessions'),'-d','display_errors=1','-S','127.0.0.1:18779','-t',site],{cwd:site,env,stdio:['ignore',log,log],windowsHide:true});
let checks=0;
const check=(label,fn)=>{fn();checks++;console.log('PASS '+label);};
async function request(url,body){
 const response=await fetch('http://127.0.0.1:18779/'+url,{redirect:'manual',headers:{Cookie:authentication.cookie},method:body?'POST':'GET',body:body?new URLSearchParams(body):undefined});
 const html=await response.text();assert.doesNotMatch(html,/(?:Fatal error|Parse error|Warning:|Deprecated:)/);return {html,status:response.status,location:response.headers.get('location')};
}
function bodyFor(data){
 const body=new URLSearchParams({csrf_token:authentication.csrf,expected_school_year:data.plan.year,preview_fingerprint:data.plan.fingerprint,current_password:'PromotionFixtureOnly!29',end_school_year_confirmation:'1'});
 data.controls.forEach((source,i)=>{body.set(`plans[${i}][from_grade]`,source.from_grade);body.set(`plans[${i}][from_strand]`,source.from_strand);source.targets.forEach((target,j)=>body.set(`plans[${i}][targets][${j}][strand]`,target.strand));});
 Object.entries(data.plan.choices?.outcomes||{}).forEach(([id,outcome])=>body.set(`outcomes[${id}]`,outcome));
 Object.entries(data.plan.choices?.strand_choices||{}).forEach(([id,strand])=>body.set(`strand_choices[${id}]`,strand));
 return body;
}
const snapshot=()=>{
 const tables=['students','student_subject_results','sections','student_school_history','student_promotion_decisions','school_year_rollover_runs','school_year_rollover_students','school_year_rollover_sections','school_year_settings','attendance'];
 return crypto.createHash('sha256').update(tables.map(table=>fixture('sql','SELECT * FROM '+table)).join('\n')).digest('hex');
};
(async()=>{try{
 for(let i=0;i<40;i++){try{await request('admin/school-year-rollover.php');break;}catch(error){if(error.cause?.code!=='ECONNREFUSED')throw error;await new Promise(resolve=>setTimeout(resolve,100));}}
 let data=JSON.parse(fixture('plan'));
 check('unrecorded/partial grades are advisory and never block rollover',()=>{
  assert.equal(data.plan.blocked,false);
  assert.equal(data.plan.summary.graduated,1);
  assert.ok(data.plan.summary.honor>=15);
 });
 const reviewBefore=snapshot();
 const preview=await request('admin/school-year-rollover.php');
 check('preview is read-only and exposes the Step 4 promotion counts',()=>{
  assert.equal(snapshot(),reviewBefore);
  assert.match(preview.html,/Honor Qualified/);
  assert.match(preview.html,/Regular Students/);
  assert.match(preview.html,/Graduated/);
  assert.match(preview.html,/data-run-rollover/);
  assert.doesNotMatch(preview.html,/Rollover is blocked/);
  assert.ok(preview.html.includes('Grade 8 - Section'));
  assert.ok(preview.html.includes('Total / capacity'));
 });
 for(const script of preview.html.matchAll(/<script>([\s\S]*?)<\/script>/g))new vm.Script(script[1]);checks++;
 check('Step 1 summary headers show dynamic grade names instead of a Details placeholder',()=>{
  assert.match(preview.html,/Step 1 · Advisory classes/);
  assert.doesNotMatch(preview.html,/<summary[^>]*>\s*Details\s*<\/summary>/i);
  assert.match(preview.html,/group-open:rotate-90/);
  assert.match(preview.html,/<summary[^>]*>[\s\S]*?<h3[^>]*>\s*Grade 7\s*<\/h3>[\s\S]*?<\/summary>/);
  assert.match(preview.html,/<summary[^>]*>[\s\S]*?<h3[^>]*>\s*Grade 11 Academic\s*<\/h3>[\s\S]*?<\/summary>/);
  assert.match(preview.html,/toggleSectionDropdown\(this\)/);
  assert.match(preview.html,/section-chevron/);
 });
 fixture('complete');data=JSON.parse(fixture('plan'));check('completed grades produce an executable plan',()=>assert.equal(data.plan.blocked,false));
 sql("UPDATE sections SET CAPACITY=10 WHERE SECTION='Amethyst'");let overflowData=JSON.parse(fixture('plan'));
 check('honor overflow keeps every qualifier in Section 1 without blocking',()=>{
  assert.equal(overflowData.plan.blocked,false);
  assert.equal(overflowData.plan.summary.honor,data.plan.summary.honor);
  const honorGroup=overflowData.plan.destinations['8|'].groups[0];
  assert.equal(honorGroup.section.SECTION,'Amethyst');
  assert.equal(honorGroup.students.length,15);
 });
 sql("UPDATE sections SET CAPACITY=40 WHERE SECTION='Amethyst'");data=JSON.parse(fixture('plan'));
 check('honor qualification is based on grades, including 89.9975 boundary',()=>{assert.equal(data.plan.summary.honor,16);assert.equal(data.plan.students.QA_R003.standing.average,89.9975);assert.equal(data.plan.students.QA_R003.placement.SECTION==='Amethyst',false);});
 const stale=bodyFor(data);sql("UPDATE sections SET CAPACITY=39 WHERE SECTION='Emerald'");const changedSnapshot=snapshot();await request('admin/run-school-year-rollover.php',stale);
 check('stale section settings invalidate the reviewed preview',()=>assert.equal(snapshot(),changedSnapshot));sql("UPDATE sections SET CAPACITY=40 WHERE SECTION='Emerald'");data=JSON.parse(fixture('plan'));
 const beforeFailure=snapshot();fixture('failure-trigger');await request('admin/run-school-year-rollover.php',bodyFor(data));fixture('remove-trigger');
 check('failed write rolls back sections, placements, decisions, run audit, and history',()=>assert.equal(snapshot(),beforeFailure));
 const beforeStudents=sql("SELECT STUDENT_ID,GRADE,STRAND,SECTION,ENROLLMENT_STATUS,STUDENT_STATUS,IS_ARCHIVED,ARCHIVE_REASON,ARCHIVED_AT,ARCHIVED_BY FROM students ORDER BY STUDENT_ID");
 const beforeGrades=fixture('sql','SELECT * FROM student_subject_results ORDER BY STUDENT_ID,SCHOOL_YEAR,SUBJECT');
 const rolled=await request('admin/run-school-year-rollover.php',bodyFor(data));check('rollover endpoint commits one reviewed transaction',()=>{assert.equal(rolled.status,302);assert.equal(sql('SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1')[0].ACTIVE_SCHOOL_YEAR,'2027-2028');});
 const populations=sql("SELECT SECTION,COUNT(*) n FROM students WHERE GRADE=8 AND ENROLLMENT_STATUS='Enrolled' GROUP BY SECTION ORDER BY SECTION");
 check('honor section holds only the 15 qualified students',()=>assert.equal(Number(populations.find(r=>r.SECTION==='Amethyst').n),15));
 check('regular students balance 34/34/34 after the retained override',()=>{assert.equal(Number(populations.find(r=>r.SECTION==='Emerald').n),34);assert.equal(Number(populations.find(r=>r.SECTION==='Jade').n),34);assert.equal(Number(populations.find(r=>r.SECTION==='Grade 8 - Section 4').n),34);});
 check('one promotion per student; every Grade 12 strand graduates and archives',()=>{const students=sql("SELECT STUDENT_ID,GRADE,ENROLLMENT_STATUS,STUDENT_STATUS,STRAND FROM students WHERE STUDENT_ID IN ('QA_GRADE8','QA_GRADE11','QA_GRADE12') ORDER BY STUDENT_ID");assert.equal(Number(students.find(s=>s.STUDENT_ID==='QA_GRADE8').GRADE),9);assert.deepEqual([Number(students.find(s=>s.STUDENT_ID==='QA_GRADE11').GRADE),students.find(s=>s.STUDENT_ID==='QA_GRADE11').ENROLLMENT_STATUS],[12,'Enrolled']);const graduate=students.find(s=>s.STUDENT_ID==='QA_GRADE12');assert.equal(graduate.ENROLLMENT_STATUS,'Graduated');assert.equal(graduate.STUDENT_STATUS,'Archived');});
 check('retained students stay, remedial students advance, and transferred students archive',()=>{
  assert.equal(Number(sql("SELECT GRADE FROM students WHERE STUDENT_ID='QA_RETAINED'")[0].GRADE),7);
  assert.equal(Number(sql("SELECT GRADE FROM students WHERE STUDENT_ID='QA_SUMMER'")[0].GRADE),8);
  const transferred=sql("SELECT GRADE,ENROLLMENT_STATUS,STUDENT_STATUS,ARCHIVE_REASON FROM students WHERE STUDENT_ID='QA_TRANSFERRED'")[0];
  assert.deepEqual([Number(transferred.GRADE),transferred.ENROLLMENT_STATUS,transferred.STUDENT_STATUS,transferred.ARCHIVE_REASON],[7,'Transferred','Archived','Transferred']);
  assert.equal(Number(sql("SELECT GRADE FROM students WHERE STUDENT_ID='QA_ARCHIVED'")[0].GRADE),7);
 });
 check('the target year starts with no carried-forward advisers',()=>assert.equal(Number(sql("SELECT COUNT(*) n FROM teacher_advisory_assignments WHERE SCHOOL_YEAR='2027-2028' AND RELEASED_AT IS NULL")[0].n),0));
 const afterRun=snapshot();await request('admin/run-school-year-rollover.php',bodyFor(data));check('repeated old-year submission is rejected without duplicate records',()=>assert.equal(snapshot(),afterRun));
 check('rollover preserves every existing subject grade',()=>assert.equal(fixture('sql','SELECT * FROM student_subject_results ORDER BY STUDENT_ID,SCHOOL_YEAR,SUBJECT'),beforeGrades));
 const undoBody={csrf_token:authentication.csrf,expected_school_year:'2027-2028',current_password:'PromotionFixtureOnly!29'};
 await request('admin/undo-school-year-rollover.php',undoBody);
 check('undo restores exact original placements and archive flags',()=>assert.deepEqual(sql("SELECT STUDENT_ID,GRADE,STRAND,SECTION,ENROLLMENT_STATUS,STUDENT_STATUS,IS_ARCHIVED,ARCHIVE_REASON,ARCHIVED_AT,ARCHIVED_BY FROM students ORDER BY STUDENT_ID"),beforeStudents));
 check('undo preserves immutable run/student/section history',()=>{assert.equal(Number(sql('SELECT COUNT(*) n FROM school_year_rollover_runs WHERE UNDONE_AT IS NOT NULL')[0].n),1);assert.ok(Number(sql('SELECT COUNT(*) n FROM school_year_rollover_students')[0].n)>0);assert.equal(Number(sql("SELECT IS_ACTIVE FROM sections WHERE SECTION='Grade 8 - Section 4'")[0].IS_ACTIVE),0);});
 sql("UPDATE students SET SECTION='Orchids' WHERE STUDENT_ID='QA_R001'");
 const secondBefore=sql("SELECT STUDENT_ID,GRADE,STRAND,SECTION,ENROLLMENT_STATUS,STUDENT_STATUS,IS_ARCHIVED,ARCHIVE_REASON,ARCHIVED_AT,ARCHIVED_BY FROM students ORDER BY STUDENT_ID");
 data=JSON.parse(fixture('plan'));await request('admin/run-school-year-rollover.php',bodyFor(data));
 check('undo then rerun keeps both run snapshots without duplicate names',()=>{assert.equal(Number(sql('SELECT COUNT(*) n FROM school_year_rollover_runs')[0].n),2);assert.equal(Number(sql("SELECT COUNT(*) n FROM sections WHERE SECTION='Grade 8 - Section 4'")[0].n),1);assert.equal(Number(sql("SELECT COUNT(*) n FROM sections WHERE SECTION='Grade 8 - Section 5'")[0].n),1);});
 await request('admin/undo-school-year-rollover.php',undoBody);
 check('second undo restores newer source placements using its own run snapshots',()=>assert.deepEqual(sql("SELECT STUDENT_ID,GRADE,STRAND,SECTION,ENROLLMENT_STATUS,STUDENT_STATUS,IS_ARCHIVED,ARCHIVE_REASON,ARCHIVED_AT,ARCHIVED_BY FROM students ORDER BY STUDENT_ID"),secondBefore));
 check('live students, grades, attendance, teacher accounts, and history remain unchanged',()=>assert.equal(liveFingerprint(),liveBefore));
 const serverLog=fs.readFileSync(path.join(site,'server.log'),'utf8');check('no PHP warnings or fatal errors',()=>assert.doesNotMatch(serverLog,/PHP (?:Warning|Fatal error|Parse error|Deprecated)/));
 console.log(`${checks} promotion integration checks passed. Disposable schema: ${database}`);
}finally{server.kill();fs.closeSync(log);}})().catch(error=>{console.error(error);process.exitCode=1;});
