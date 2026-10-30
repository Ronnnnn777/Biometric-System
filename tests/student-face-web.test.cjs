const assert=require('node:assert/strict');
const {spawn,execFileSync}=require('node:child_process');
const fs=require('node:fs');
const path=require('node:path');
const {createHash}=require('node:crypto');
const {createHarness}=require('./helpers/student-face-camera.cjs');
const root=path.resolve(__dirname,'..');
const php=process.env.PHP_BINARY || 'C:/xampp/php/php.exe';
const directory=fs.mkdtempSync(path.join(root,'tmp','face-web-'));
const fixture=JSON.parse(execFileSync(php,[path.join(__dirname,'face-web-fixture.php'),directory],{encoding:'utf8'}));
const port=18974;
const base=`http://127.0.0.1:${port}/`;
const server=spawn(php,['-d',`session.save_path=${directory}`,'-d',`upload_tmp_dir=${directory}`,'-S',`127.0.0.1:${port}`,'-t',root],{windowsHide:true,stdio:'ignore',env:{...process.env,FACE_CAPTURE_TEST:'1'}});
let checks=0;
async function verify(url,role,expected){
 const response=await fetch(base+url,{headers:role?{cookie:`PHPSESSID=${fixture.sessions[role]}`}:{},redirect:'manual'});
 assert.equal(response.status,expected);
 await response.arrayBuffer();
 checks++;
}
(async()=>{
 try {
  let started=false;
  for(let attempt=0;attempt<40;attempt++){
   try{await fetch(base+'admin/student-face-image.php');started=true;break;}catch{await new Promise(resolve=>setTimeout(resolve,100));}
  }
  assert.ok(started,'Isolated PHP test server starts');
  await verify('admin/student-face-image.php',null,403);
  await verify('admin/student-face-image.php?file=../../config/db_conn.php','administrator',404);
  await verify('admin/student-face-image.php?legacy=../../config/db_conn.php','administrator',404);
  assert.ok(fixture.image,'An existing enrolled reference is available for the route test');
  await verify(fixture.image,null,403);
  await verify(fixture.image,'administrator',200);
  await verify(fixture.image,'mobile_scanner',200);
  await verify(fixture.image,'teacher',403);
  await verify('admin/add-student-reference-images.php',null,302);
  for(const url of ['admin/add-student.php',`admin/edit-student.php?id=${fixture.student_id}`]) {
   const response=await fetch(base+url,{headers:{cookie:`PHPSESSID=${fixture.sessions.administrator}`}});
   assert.equal(response.status,200);
   const html=await response.text();
   for(const label of ['Front View','Slight Left View','Slight Right View','name="face_left"','name="face_right"','lg:grid-cols-3']) assert.ok(html.includes(label),`Reference form contains ${label}`);
   assert.ok(!/Fatal error|Warning:/.test(html),'Reference form renders without PHP errors');
   assert.equal((html.match(/id="webcam"/g)||[]).length,1,'There is exactly one shared video preview');
   assert.ok(!html.includes('main-preview'),'There is no redundant external photo preview');
   assert.ok(!html.includes('preview-help'),'There is no redundant preview panel');
   assert.equal((html.match(/id="face-preview-(front|left|right)"/g)||[]).length,3,'All three reference previews remain');
   const dialogHtml=html.match(/<dialog\b[\s\S]*?<\/dialog>/)[0];
   assert.ok(dialogHtml.includes('id="webcam"'),'Live feed is inside the shared modal');
   assert.equal((html.match(/student-face-references\.js/g)||[]).length,1,'There is exactly one shared controller');
   for(const pose of ['front','left','right']) {
    assert.ok(html.includes(`data-use-camera="${pose}"`));
    assert.ok(html.includes(`data-retake-face="${pose}"`));
    const slot=html.match(new RegExp(`<section[^>]*data-face-slot="${pose}"[\\s\\S]*?</section>`))[0];
    const registered=slot.includes('data-registered="true"');
    const useClasses=slot.match(new RegExp(`data-use-camera="${pose}" class="([^"]*)"`))[1];
    const retakeClasses=slot.match(new RegExp(`data-retake-face="${pose}" class="([^"]*)"`))[1];
    assert.equal(useClasses.split(' ').includes('hidden'),registered,'Saved photos hide Use Camera on initial render');
    assert.equal(retakeClasses.split(' ').includes('hidden'),!registered,'Saved photos show Retake on initial render');
    assert.ok(slot.includes('Choose Photo'),'Choose Photo always remains available');
   }
   checks++;
  }
  async function verifyMultipart(app,expectedPoses,removeRight=false){
   const data=app.formData();
   data.append('csrf_token',fixture.csrf_tokens.administrator);
   const response=await fetch(base+'tests/face-web-fixture.php',{method:'POST',headers:{cookie:`PHPSESSID=${fixture.sessions.administrator}`},body:data});
   assert.equal(response.status,200);
   const raw=await response.text();
   let result;
   try {result=JSON.parse(raw);}catch{throw new Error('Multipart fixture response: '+raw.slice(0,1000));}
   assert.deepEqual(Object.keys(result.received).sort(),expectedPoses.slice().sort());
   for(const pose of expectedPoses){
    const file=app.file(pose);
    const hash=createHash('sha256').update(Buffer.from(await file.arrayBuffer())).digest('hex');
    assert.equal(result.received[pose].sha256,hash,`${pose} arrives byte-for-byte in PHP's upload field`);
    assert.equal(result.received[pose].name,file.name);
   }
   assert.equal(result.remove_right,removeRight);
   checks++;
  }
  const all=createHarness();
  for(const pose of ['front','left','right']){await all.open(pose);await all.capture();}
  await verifyMultipart(all,['front','left','right']);
  await all.retake('left');await all.capture();await all.remove('right');
  await verifyMultipart(all,['front','left'],true);
  const frontOnly=createHarness();await frontOnly.open('front');await frontOnly.capture();
  await verifyMultipart(frontOnly,['front']);
  const edit=createHarness();await edit.upload('left');await edit.open('right');await edit.capture();
  await verifyMultipart(edit,['left','right']);
  console.log(`PASS: ${checks} HTTP authentication, image access, and traversal checks`);
 } finally {
  server.kill();
  // Remove only this harness's workspace-local session files, after the child exits.
  await new Promise(resolve=>server.exitCode!==null?resolve():server.once('exit',resolve));
  for(const name of fs.readdirSync(directory)) fs.unlinkSync(path.join(directory,name));
  fs.rmdirSync(directory);
 }
})().catch(error=>{console.error(error.message);process.exitCode=1;});
