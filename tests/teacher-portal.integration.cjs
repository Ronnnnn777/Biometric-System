// Windows/XAMPP: node tests/teacher-portal.integration.cjs
// Copies the current database into a uniquely named test schema, and serves a
// source copy on a loopback PHP server. All writes stay in the test schema.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const {spawn,execFileSync}=require('node:child_process');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const root=path.resolve(__dirname,'..');
const php='C:/xampp/php/php.exe',mysql='C:/xampp/mysql/bin/mysql.exe',dump='C:/xampp/mysql/bin/mysqldump.exe';
const database='biometric_teacher_test_'+crypto.randomBytes(5).toString('hex');
const site=path.join(root,'tmp',database);
fs.mkdirSync(site,{recursive:true});
const run=(exe,args,input)=>execFileSync(exe,args,{cwd:root,input,encoding:'utf8',maxBuffer:32*1024*1024,windowsHide:true});
const env={...process.env,TEACHER_TEST_DATABASE:database};
const fixture=(action,sql)=>execFileSync(php,['tests/teacher-portal-fixture.php',action],{cwd:root,env,input:sql,encoding:'utf8',windowsHide:true});
const sql=query=>JSON.parse(fixture('sql',query));
run(mysql,['-u','root','-e',`CREATE DATABASE ${database} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci`]);
const snapshot=run(dump,['-u','root','--single-transaction','biometric']).replaceAll('`biometric`.','`'+database+'`.');
const liveGrades=()=>crypto.createHash('sha256').update(run(mysql,['-u','root','-N','-B','biometric','-e','SELECT * FROM student_subject_results ORDER BY STUDENT_ID,SCHOOL_YEAR,SUBJECT'])).digest('hex');
const liveGradeFingerprint=liveGrades();
run(mysql,['-u','root',database],snapshot);
for(const dir of ['teacher','admin','includes','config','js','css'])fs.cpSync(path.join(root,dir),path.join(site,dir),{recursive:true});
for(const file of ['index.php'])fs.copyFileSync(path.join(root,file),path.join(site,file));
const config=path.join(site,'config/db_conn.php');
fs.writeFileSync(config,fs.readFileSync(config,'utf8').replace('$dbname="biometric";',`$dbname="${database}";`));
const data=JSON.parse(fixture('setup'));
const originalGrades=sql('SELECT * FROM student_subject_results ORDER BY STUDENT_ID,SCHOOL_YEAR,SUBJECT');
const log=fs.openSync(path.join(site,'server.log'),'w');
fs.mkdirSync(path.join(site,'sessions'));
const server=spawn(php,['-d','session.save_path='+path.join(site,'sessions'),'-d','display_errors=1','-S','127.0.0.1:18769','-t',site],{cwd:site,env,stdio:['ignore',log,log],windowsHide:true});
let checks=0;
class Client {
 constructor(){this.cookie='';this.csrf='';}
 async request(url,body,accept){
  const headers={Cookie:this.cookie};if(accept)headers.Accept=accept;
  const response=await fetch('http://127.0.0.1:18769/'+url,{method:body?'POST':'GET',body:body?new URLSearchParams(body):undefined,headers,redirect:'manual'});
  const cookie=response.headers.get('set-cookie');if(cookie)this.cookie=cookie.split(';')[0];
  const html=await response.text();assert.doesNotMatch(html,/(?:Fatal error|Parse error|Warning:|Deprecated:)/);
  const token=html.match(/name="csrf_token"\s+value="([^"]+)"/);if(token)this.csrf=token[1];
  return {status:response.status,location:response.headers.get('location'),html};
 }
 async login(email,password){
  // The landing page creates the shared login CSRF token.
  await this.request('index.php');
  assert.ok(this.csrf,'Login CSRF token exists');
  return this.request('admin/auth.php',{username:email,password,csrf_token:this.csrf});
 }
}
function check(label,callback){callback();checks++;console.log('PASS '+label);}
(async()=>{
 try {
  for(let attempt=0;attempt<50;attempt++){
   try{await fetch('http://127.0.0.1:18769/teacher/login.php',{redirect:'manual'});break;}catch{await new Promise(r=>setTimeout(r,100));}
  }
  const clients=new Map();
  for(const teacher of data.teachers){
   const client=new Client();const hazel=teacher.TEACHER_USER==='hazel@biometrics.com';
   const password=hazel?'FixtureOnly!HazelPassword29':'FixtureOnly!TeacherPassword29';
   const login=await client.login(teacher.TEACHER_USER,password);
   assert.match(login.location,hazel?/students\.php$/:/settings\.php$/);
   if(!hazel){
    assert.equal((await client.request('teacher/students.php')).location,'settings.php');
    await client.request('teacher/settings.php');
    const changed=await client.request('teacher/settings-save.php',{action:'password',csrf_token:client.csrf,current_password:password,new_password:'UpdatedFixtureOnly!Password29',confirm_password:'UpdatedFixtureOnly!Password29'});
    assert.equal(changed.status,302);
   }
   const roster=await client.request('teacher/students.php');
   assert.equal(roster.status,200);assert.ok(roster.html.includes(teacher.SECTION));
   assert.ok(roster.html.includes('href="grading.php"'));assert.ok(!roster.html.includes('id="quarterly-grades"'));
   const ids=Array.from(roster.html.matchAll(/data-roster-student-id="(\d+)"/g),m=>Number(m[1]));
   const expected=data.students.filter(s=>s.GRADE===teacher.GRADE&&s.STRAND===teacher.STRAND&&s.SECTION===teacher.SECTION&&s.ENROLLMENT_STATUS==='Enrolled');
   assert.equal(ids.length,expected.length,'The complete advisory roster is rendered');
   for(const id of ids)assert.ok(expected.some(s=>Number(s.ID)===id),'Only advisory students rendered');
   const attendance=await client.request('teacher/get-attendance.php?date=2026-10-28');assert.equal(attendance.status,200);
   const grades=await client.request('teacher/grading.php?quarter=3');assert.equal(grades.status,200);
   if(hazel){assert.ok(grades.html.includes('Quarterly grades'));assert.ok(grades.html.includes('data-page-size="15"'));}
   else assert.ok(grades.html.includes('No subject teaching assignments'));
   clients.set(teacher.SECTION,client);
   checks++;
  }
  console.log('PASS all 19 logins, 18 forced password changes, advisory rosters, attendance and grading pages');
  const archived=new Client();const denied=await archived.login('julius@gmail.com','FixtureOnly!HazelPassword29');
  check('archived teacher login denied',()=>assert.equal(denied.location,'login.php'));
  const hazel=clients.get('Amethyst'),camia=clients.get('Camia');
  const amethyst=data.students.filter(s=>s.SECTION==='Amethyst');const outside=data.students.find(s=>s.SECTION==='Jade');
  const profile=await hazel.request(`teacher/student-profile.php?id=${outside.ID}&grade=8&section=Amethyst`);
  check('tampered student profile denied',()=>assert.equal(profile.status,404));
  const unauthorized=await hazel.request('teacher/get-attendance.php?date=2026-10-28&grade=8&section=Jade');
  check('attendance cannot expand to another section in the same grade',()=>assert.ok(!unauthorized.html.includes(outside.STUDENT_ID)));
  const rendered=await hazel.request('teacher/grading.php?quarter=3');
  for(const script of rendered.html.matchAll(/<script>([\s\S]*?)<\/script>/g))new vm.Script(script[1]);
  check('rendered grading JavaScript parses',()=>assert.ok(rendered.html.includes('Back to Students')));
  const payload={csrf_token:hazel.csrf,grade:'8',section:'Amethyst',subject:'Filipino',quarter_period:'3'};
  for(const student of amethyst){payload[`q1[${student.ID}]`]='80';payload[`q2[${student.ID}]`]='90';payload[`q3[${student.ID}]`]='85';payload[`q4[${student.ID}]`]='95';}
  const allBody=new URLSearchParams(payload);for(const student of amethyst)allBody.append('student_ids[]',student.ID);
  // Client accepts URLSearchParams so repeated student_ids[] retain every row.
  const save=await hazel.request('teacher/save-subject-result.php',allBody,'application/json');
  const saved=JSON.parse(save.html);
  check('Save All saves all 46 students across pages',()=>assert.equal(saved.saved_ids.length,46));
  check('final grade computed correctly',()=>assert.equal(Number(sql("SELECT FINAL_GRADE FROM student_subject_results WHERE SUBJECT='Filipino' AND SCHOOL_YEAR='2026-2027' LIMIT 1")[0].FINAL_GRADE),87.5));
  const savedPlan=JSON.parse(fixture('plan'));
  check('rollover finds saved teacher grades by LRN and year while keeping other missing subjects blocked',()=>{
   for(const student of amethyst){
    const standing=savedPlan.students[student.STUDENT_ID].standing;
    assert.equal(standing.missing_subjects.length,7);
    assert.equal(standing.missing_subjects.includes('Filipino'),false);
    assert.equal(standing.incomplete_subjects.includes('Filipino'),false);
    assert.equal(standing.honor,false);
   }
   assert.equal(savedPlan.blocked,true);
  });
  const gradedProfile=await hazel.request('teacher/student-profile.php?id='+amethyst[0].ID+'&grade=8&section=Amethyst');
  check('teacher profile uses annual completeness rather than a passing running average',()=>{
   assert.match(gradedProfile.html,/Incomplete grades/);assert.match(gradedProfile.html,/Open quarterly grading/);
   assert.doesNotMatch(gradedProfile.html,/name="result"/);
  });
  const legacyBefore=fixture('sql','SELECT * FROM student_subject_results ORDER BY STUDENT_ID,SCHOOL_YEAR,SUBJECT');
  await hazel.request('teacher/save-subject-result.php',{csrf_token:hazel.csrf,student_record_id:amethyst[0].ID,grade:8,section:'Amethyst',subject:'Filipino',result:'Failed'});
  check('legacy result-only request cannot overwrite computed quarterly results',()=>assert.equal(fixture('sql','SELECT * FROM student_subject_results ORDER BY STUDENT_ID,SCHOOL_YEAR,SUBJECT'),legacyBefore));
  const rowBody=new URLSearchParams(allBody);rowBody.set('save_student',amethyst[0].ID);rowBody.set(`q3[${amethyst[0].ID}]`,'100');
  const rowSaved=JSON.parse((await hazel.request('teacher/save-subject-result.php',rowBody,'application/json')).html);
  check('individual save affects only selected student',()=>assert.deepEqual(rowSaved.saved_ids,[Number(amethyst[0].ID)]));
  const invalid=new URLSearchParams(rowBody);invalid.set(`q3[${amethyst[0].ID}]`,'101');
  check('server rejects grades above 100',()=>{});
  assert.deepEqual(JSON.parse((await hazel.request('teacher/save-subject-result.php',invalid,'application/json')).html).saved_ids,[]);
  const wrongSubject=new URLSearchParams(rowBody);wrongSubject.set('subject','Science');
  assert.deepEqual(JSON.parse((await hazel.request('teacher/save-subject-result.php',wrongSubject,'application/json')).html).saved_ids,[]);checks++;
  const wrongClass=new URLSearchParams(payload);wrongClass.append('student_ids[]',outside.ID);wrongClass.set('section','Jade');wrongClass.set(`q1[${outside.ID}]`,'99');
  assert.deepEqual(JSON.parse((await hazel.request('teacher/save-subject-result.php',wrongClass,'application/json')).html).saved_ids,[]);checks++;
  const noSubject=new URLSearchParams(payload);noSubject.set('csrf_token',camia.csrf);noSubject.set('grade','7');noSubject.set('section','Camia');
  const camiaStudent=data.students.find(s=>s.SECTION==='Camia');noSubject.append('student_ids[]',camiaStudent.ID);noSubject.set(`q1[${camiaStudent.ID}]`,'99');
  assert.deepEqual(JSON.parse((await camia.request('teacher/save-subject-result.php',noSubject,'application/json')).html).saved_ids,[]);checks++;
  console.log('PASS unauthorized class, subject and advisory-only grade submissions denied');
  console.log(fixture('constraints'));checks++;
  sql("UPDATE school_year_settings SET ACTIVE_SCHOOL_YEAR='2027-2028' WHERE ID=1");
  assert.equal(Number(sql('SELECT COUNT(*) n FROM current_teacher_advisory_assignments')[0].n),0);
  assert.equal(Number(sql('SELECT COUNT(*) n FROM current_teacher_subject_assignments')[0].n),0);
  assert.ok((await hazel.request('teacher/students.php')).html.includes('No advisory section assigned'));checks++;
  sql("UPDATE school_year_settings SET ACTIVE_SCHOOL_YEAR='2026-2027' WHERE ID=1");
  assert.equal(Number(sql('SELECT COUNT(*) n FROM current_teacher_advisory_assignments')[0].n),19);checks++;
  console.log('PASS school-year switching isolates permissions and switching back restores them');
  console.log(fixture('carry-year'));
  assert.equal(Number(sql("SELECT COUNT(*) n FROM teacher_advisory_assignments WHERE SCHOOL_YEAR='2027-2028' AND RELEASED_AT IS NULL")[0].n),19);checks++;
  assert.equal(Number(sql("SELECT COUNT(*) n FROM teacher_assignments WHERE SCHOOL_YEAR='2027-2028'")[0].n),1);checks++;
  sql("UPDATE school_year_settings SET ACTIVE_SCHOOL_YEAR='2027-2028' WHERE ID=1");
  assert.equal(Number(sql('SELECT COUNT(*) n FROM current_teacher_advisory_assignments')[0].n),19);checks++;
  assert.equal(sql('SELECT SECTION FROM current_teacher_advisory_assignments WHERE TEACHER_ID=6')[0].SECTION,'Amethyst');checks++;
  sql("UPDATE school_year_settings SET ACTIVE_SCHOOL_YEAR='2026-2027' WHERE ID=1");
  const adminData=JSON.parse(fixture('admin-session'));const admin=new Client();admin.cookie=adminData.cookie;admin.csrf=adminData.csrf;
  const emeraldTeacher=data.teachers.find(t=>t.SECTION==='Emerald');
  const emeraldSubjects={id:emeraldTeacher.TEACHER_ID,first_name:'Patricia Anne',last_name:'Mendoza',csrf_token:admin.csrf,
   'assignment_grade[0]':8,'assignment_strand[0]':'','assignment_section[0]':'Emerald','assignment_subject[0]':'Filipino',
   'assignment_grade[1]':7,'assignment_strand[1]':'','assignment_section[1]':'Camia','assignment_subject[1]':'English'};
  await admin.request('admin/update-teacher.php',emeraldSubjects);
  check('shared Filipino subject and several teaching classes preserve exactly one Emerald advisory',()=>{
   assert.equal(sql('SELECT SECTION FROM current_teacher_advisory_assignments WHERE TEACHER_ID='+emeraldTeacher.TEACHER_ID)[0].SECTION,'Emerald');
   assert.equal(Number(sql('SELECT COUNT(*) n FROM teacher_assignments WHERE TEACHER_ID='+emeraldTeacher.TEACHER_ID)[0].n),2);
   assert.equal(sql('SELECT SECTION FROM teachers WHERE TEACHER_ID='+emeraldTeacher.TEACHER_ID)[0].SECTION,'Emerald');
   assert.equal(Number(sql("SELECT COUNT(*) n FROM current_teacher_subject_assignments WHERE SUBJECT='Filipino'")[0].n),2);
  });
  const emeraldRoster=await clients.get('Emerald').request('teacher/students.php');
  check('additional subject classes never appear in the advisory roster or profile',()=>{
   assert.equal((emeraldRoster.html.match(/data-section-card /g)||[]).length,1);
   assert.doesNotMatch(emeraldRoster.html,/>Camia</);
   assert.doesNotMatch(emeraldRoster.html,/data-roster-student-id=/);
  });
  const teachingClass=await clients.get('Emerald').request('teacher/grading.php?grade=7&section=Camia');
  check('explicit subject teaching outside the advisory remains authorized for grading',()=>assert.match(teachingClass.html,/Quarterly grades/));
  const explicitZero=await admin.request('admin/update-teacher.php',{...emeraldSubjects,advisory_section_id:0});
  check('an active teacher cannot clear their advisory while keeping teaching subjects',()=>{
   assert.equal(explicitZero.status,302);
   assert.equal(sql('SELECT SECTION FROM teachers WHERE TEACHER_ID='+emeraldTeacher.TEACHER_ID)[0].SECTION,'Emerald');
   assert.equal(Number(sql('SELECT COUNT(*) n FROM current_teacher_advisory_assignments WHERE TEACHER_ID='+emeraldTeacher.TEACHER_ID)[0].n),1);
  });
  await admin.request('admin/save-teacher.php',{csrf_token:admin.csrf,first_name:'No',last_name:'Advisory',teacher_user:'missing-advisory@fixture.example',teacher_pass:'FixtureOnly!NewPassword29',teacher_confirm:'FixtureOnly!NewPassword29'});
  check('teacher creation without an advisory rolls back the account',()=>assert.equal(Number(sql("SELECT COUNT(*) n FROM teachers WHERE TEACHER_USER='missing-advisory@fixture.example'")[0].n),0));
  fixture('promotion-ready');
  const beforeCoverage=JSON.parse(fixture('plan'));
  const teachingRows=sql('SELECT * FROM teacher_assignments');
  sql('DELETE FROM teacher_assignments');
  const noCoverage=JSON.parse(fixture('plan'));
  check('complete saved grades remain promotion-eligible without any subject teaching assignments',()=>{
   assert.deepEqual(noCoverage.students,beforeCoverage.students);
   assert.equal(noCoverage.blocked,false);assert.equal(noCoverage.assignment_diagnostics.advisory_reviews.length,0);
   assert.ok(noCoverage.assignment_diagnostics.missing_subject_assignments.length>0);
  });
  for(const row of teachingRows)sql(`INSERT INTO teacher_assignments (TEACHER_ID,GRADE,STRAND,SECTION,SUBJECT,SCHOOL_YEAR) VALUES (${row.TEACHER_ID},${row.GRADE},'${row.STRAND}','${row.SECTION}','${row.SUBJECT}','${row.SCHOOL_YEAR}')`);
  const studentsBeforeRollover=sql('SELECT ID,GRADE,STRAND,SECTION,ENROLLMENT_STATUS FROM students ORDER BY ID');
  const rolloverBody=new URLSearchParams({csrf_token:admin.csrf,current_password:'FixtureOnly!AdminPassword29',end_school_year_confirmation:'1'});
  const sources=sql("SELECT DISTINCT GRADE,STRAND FROM students WHERE ENROLLMENT_STATUS='Enrolled' AND GRADE BETWEEN 7 AND 11 ORDER BY GRADE,STRAND");
  sources.forEach((source,index)=>{
   rolloverBody.set(`plans[${index}][from_grade]`,source.GRADE);rolloverBody.set(`plans[${index}][from_strand]`,source.STRAND);
   rolloverBody.set(`plans[${index}][targets][0][strand]`,Number(source.GRADE)===10?'Academic':source.STRAND);
   rolloverBody.set(`plans[${index}][targets][0][percent]`,'100');
  });
  const promotionPreview=await admin.request('admin/school-year-rollover.php');
  rolloverBody.set('expected_school_year','2026-2027');
  rolloverBody.set('preview_fingerprint',promotionPreview.html.match(/name="preview_fingerprint" value="([^"]+)"/)[1]);
  const rolled=await admin.request('admin/run-school-year-rollover.php',rolloverBody);assert.equal(rolled.status,302);
  assert.equal(sql('SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1')[0].ACTIVE_SCHOOL_YEAR,'2027-2028');checks++;
  assert.equal(Number(sql('SELECT COUNT(*) n FROM current_teacher_advisory_assignments')[0].n),19);checks++;
  const undone=await admin.request('admin/undo-school-year-rollover.php',{csrf_token:admin.csrf,current_password:'FixtureOnly!AdminPassword29',target_school_year:'2026-2027'});assert.equal(undone.status,302);
  assert.equal(sql('SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1')[0].ACTIVE_SCHOOL_YEAR,'2026-2027');checks++;
  assert.deepEqual(sql('SELECT ID,GRADE,STRAND,SECTION,ENROLLMENT_STATUS FROM students ORDER BY ID'),studentsBeforeRollover);checks++;
  console.log('PASS full student rollover and undo preserve adviser sections and restore student placements');
  const camiaTeacher=data.teachers.find(t=>t.SECTION==='Camia'),roseTeacher=data.teachers.find(t=>t.SECTION==='Rose');
  const sections=sql("SELECT SECTION_ID,SECTION FROM sections WHERE SECTION IN ('Camia','Rose')");
  const camiaSection=sections.find(s=>s.SECTION==='Camia').SECTION_ID,roseSection=sections.find(s=>s.SECTION==='Rose').SECTION_ID;
  const edit=await admin.request('admin/edit-teacher.php?id='+camiaTeacher.TEACHER_ID);assert.equal(edit.status,200);
  for(const script of edit.html.matchAll(/<script>([\s\S]*?)<\/script>/g))new vm.Script(script[1]);
  assert.ok(edit.html.includes('name="advisory_section_id"'));checks++;
  const rejected=await admin.request('admin/update-teacher.php',{id:camiaTeacher.TEACHER_ID,first_name:'Should Rollback',last_name:'Conflict',advisory_section_id:roseSection,csrf_token:admin.csrf});
  assert.equal(rejected.status,302);
  assert.equal(sql('SELECT TEACHER_NAME FROM teachers WHERE TEACHER_ID='+camiaTeacher.TEACHER_ID)[0].TEACHER_NAME,camiaTeacher.TEACHER_NAME);checks++;
  const noCsrf=await admin.request('admin/delete-teacher.php',{id:camiaTeacher.TEACHER_ID});assert.equal(noCsrf.status,400);checks++;
  const archive=await admin.request('admin/delete-teacher.php',{id:camiaTeacher.TEACHER_ID,csrf_token:admin.csrf});assert.equal(archive.status,302);
  assert.equal(Number(sql('SELECT COUNT(*) n FROM current_teacher_advisory_assignments WHERE TEACHER_ID='+camiaTeacher.TEACHER_ID)[0].n),0);
  assert.ok(Number(sql('SELECT COUNT(*) n FROM teacher_advisory_assignments WHERE TEACHER_ID='+camiaTeacher.TEACHER_ID+' AND RELEASED_AT IS NOT NULL')[0].n)>0);checks++;
  check('stale advisory rows belonging to archived teachers do not reserve a class',()=>assert.match(fixture('stale-archived-advisory'),/without an active advisory conflict/));
  const newAccount={csrf_token:admin.csrf,first_name:'New',last_name:'Adviser',teacher_user:'new-adviser@fixture.example',teacher_pass:'FixtureOnly!NewPassword29',teacher_confirm:'FixtureOnly!NewPassword29',advisory_section_id:camiaSection,
   'assignment_grade[0]':8,'assignment_strand[0]':'','assignment_section[0]':'Emerald','assignment_subject[0]':'Filipino'};
  await admin.request('admin/save-teacher.php',newAccount);
  const createdTeacher=sql("SELECT TEACHER_ID,GRADE,SECTION FROM teachers WHERE TEACHER_USER='new-adviser@fixture.example'")[0];
  check('Admin creation uses the chosen advisory even when the teaching class differs',()=>{
   assert.ok(createdTeacher);assert.equal(createdTeacher.SECTION,'Camia');assert.equal(Number(createdTeacher.GRADE),7);
   assert.equal(sql('SELECT SECTION FROM current_teacher_advisory_assignments WHERE TEACHER_ID='+createdTeacher.TEACHER_ID)[0].SECTION,'Camia');
   assert.equal(sql('SELECT SECTION FROM current_teacher_subject_assignments WHERE TEACHER_ID='+createdTeacher.TEACHER_ID)[0].SECTION,'Emerald');
  });
  await admin.request('admin/delete-teacher.php',{id:createdTeacher.TEACHER_ID,csrf_token:admin.csrf});
  const replaced=await admin.request('admin/update-teacher.php',{id:roseTeacher.TEACHER_ID,first_name:'Angela Mae',last_name:'Cruz',advisory_section_id:camiaSection,csrf_token:admin.csrf});assert.equal(replaced.status,302);
  assert.equal(sql('SELECT SECTION FROM current_teacher_advisory_assignments WHERE TEACHER_ID='+roseTeacher.TEACHER_ID)[0].SECTION,'Camia');checks++;
  const restore=await admin.request('admin/restore-archived-teacher.php',{id:camiaTeacher.TEACHER_ID,csrf_token:admin.csrf});assert.equal(restore.status,302);
  check('restoration without an advisory keeps the account archived',()=>assert.equal(sql('SELECT TEACHER_STATUS FROM teachers WHERE TEACHER_ID='+camiaTeacher.TEACHER_ID)[0].TEACHER_STATUS,'Archived'));
  await admin.request('admin/restore-archived-teacher.php',{id:camiaTeacher.TEACHER_ID,advisory_section_id:camiaSection,csrf_token:admin.csrf});
  check('restoration cannot reclaim an occupied advisory',()=>assert.equal(sql('SELECT TEACHER_STATUS FROM teachers WHERE TEACHER_ID='+camiaTeacher.TEACHER_ID)[0].TEACHER_STATUS,'Archived'));
  await admin.request('admin/restore-archived-teacher.php',{id:camiaTeacher.TEACHER_ID,advisory_section_id:roseSection,csrf_token:admin.csrf});
  check('restoration activates the existing account with one available advisory',()=>{
   assert.equal(sql('SELECT SECTION FROM current_teacher_advisory_assignments WHERE TEACHER_ID='+camiaTeacher.TEACHER_ID)[0].SECTION,'Rose');
   assert.equal(Number(sql("SELECT COUNT(*) n FROM teachers WHERE TEACHER_STATUS='Active'")[0].n),19);
   assert.deepEqual(sql("SELECT t.TEACHER_ID,COUNT(a.SECTION_ID) n FROM teachers t LEFT JOIN current_teacher_advisory_assignments a ON a.TEACHER_ID=t.TEACHER_ID WHERE t.TEACHER_STATUS='Active' GROUP BY t.TEACHER_ID HAVING n<>1"),[]);
  });
  console.log('PASS admin adviser conflict rolls back, CSRF, archival release, reassignment and safe restoration');
  assert.equal(liveGrades(),liveGradeFingerprint);checks++;
  assert.doesNotMatch(fs.readFileSync(path.join(site,'server.log'),'utf8'),/PHP (?:Warning|Fatal error|Parse error|Deprecated)/);checks++;
  console.log('PASS live grade records unchanged after integration tests');
  console.log(`Completed ${checks} integration checks. Isolated schema: ${database}`);
 } finally {server.kill();fs.closeSync(log);}
})().catch(error=>{console.error(error);process.exitCode=1;});
