<?php
session_start();
require '../includes/csrf-token.php';
require 'page-guard.php';
require 'components.php';
$toast=$_SESSION['toast']['message']??null;
unset($_SESSION['toast']);
adminHead('Register student');
adminSidebar('students','Register student','Create a student profile for attendance recognition');
?>
<div class="grid gap-7 lg:grid-cols-[minmax(0,1fr)_360px]">
    <section class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 sm:p-8">
        <p class="text-sm font-bold uppercase tracking-widest text-blue-600">Student profile</p>
        <h2 class="mt-2 text-2xl font-black text-slate-900">Registration details</h2>
        <p class="mt-2 text-slate-500">All fields marked below are required.</p>
        <?php if(($_GET['msg']??'')==='duplicate'): ?><div class="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">That student ID is already registered. Try a different ID.</div><?php endif; ?>
        <form action="save-student.php" method="post" enctype="multipart/form-data" class="mt-7 space-y-5">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div class="grid gap-5 sm:grid-cols-2">
                <label class="block text-sm font-bold text-slate-700">Student ID<input class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100" name="student_id" placeholder="e.g. 2024-0001" required></label>
                <label class="block text-sm font-bold text-slate-700">Section<select class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100" name="student_section" required><option value="">Choose a section</option><option>BSIT-4A</option><option>BSIT-4B</option><option>BSCS-4A</option><option>BSCS-4B</option></select></label>
            </div>
            <label class="block text-sm font-bold text-slate-700">Full name<input class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100" name="student_name" placeholder="Enter the student’s full name" required></label>
            <div class="rounded-xl border border-slate-200 p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div><p class="text-sm font-bold text-slate-700">Biometric image</p><p class="text-sm text-slate-500">Upload a clear face photo or use a connected camera.</p></div>
                    <div class="flex flex-wrap items-center gap-2">
                        <select id="camera-select" class="max-w-48 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700" aria-label="Choose camera" disabled><option>Loading available cameras…</option></select>
                        <button id="use-camera" type="button" class="rounded-lg border border-blue-200 px-3 py-2 text-sm font-bold text-blue-700 hover:bg-blue-50">Use camera</button>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <input class="sr-only" type="file" name="student_image" id="student_image" accept="image/*">
                    <label for="student_image" class="cursor-pointer rounded-lg bg-blue-50 px-4 py-2 text-sm font-bold text-blue-700 hover:bg-blue-100">Choose photo</label>
                    <span id="file-name" class="min-w-0 max-w-full truncate text-sm text-slate-500">No file chosen</span>
                    <input type="hidden" name="captured_image" id="captured_image">
                </div>
                <p id="camera-status" class="mt-2 hidden text-sm font-semibold text-emerald-700"></p>
            </div>
            <button class="w-full rounded-xl bg-blue-600 px-5 py-3 font-bold text-white shadow-sm hover:bg-blue-700">Register student</button>
        </form>
    </section>
    <aside class="h-fit rounded-2xl bg-slate-900 p-6 text-white">
        <p class="text-sm font-bold uppercase tracking-widest text-blue-300">Preview</p>
        <div class="mt-5 aspect-square overflow-hidden rounded-xl bg-slate-800">
            <img id="main-preview" src="../image/student-preview.png" alt="Student image preview" class="h-full w-full object-cover">
            <video id="webcam" class="hidden h-full w-full object-cover" autoplay muted playsinline></video>
        </div>
        <div id="camera-controls" class="mt-3 hidden gap-3">
            <button id="capture-photo" type="button" class="flex-1 rounded-lg bg-blue-600 px-4 py-3 text-sm font-bold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-slate-500" disabled>Capture photo</button>
            <button id="retake-photo" type="button" class="hidden flex-1 rounded-lg bg-white/15 px-4 py-3 text-sm font-bold text-white hover:bg-white/25">Retake photo</button>
        </div>
        <canvas id="photo-canvas" class="hidden"></canvas>
        <p id="preview-help" class="mt-4 text-sm leading-6 text-slate-300">Use a well-lit, front-facing image for the best recognition result.</p>
    </aside>
</div>
<?php if($toast): ?><div id="toast" class="fixed bottom-5 right-5 rounded-xl bg-slate-900 px-5 py-4 font-semibold text-white shadow-xl"><?= htmlspecialchars($toast) ?></div><?php endif; ?>
<style>#camera-controls:not(.hidden){display:flex}</style>
<script>
const input=document.getElementById('student_image'),fileName=document.getElementById('file-name'),preview=document.getElementById('main-preview'),video=document.getElementById('webcam'),useCamera=document.getElementById('use-camera'),cameraSelect=document.getElementById('camera-select'),capture=document.getElementById('capture-photo'),retake=document.getElementById('retake-photo'),controls=document.getElementById('camera-controls'),canvas=document.getElementById('photo-canvas'),captured=document.getElementById('captured_image'),status=document.getElementById('camera-status'),help=document.getElementById('preview-help');
let stream=null;
const stopCamera=()=>{stream?.getTracks().forEach(track=>track.stop());stream=null;video.srcObject=null;capture.disabled=true};
const showStatus=message=>{status.textContent=message;status.classList.remove('hidden')};
async function loadCameras(){try{const permissionStream=await navigator.mediaDevices.getUserMedia({video:true,audio:false});permissionStream.getTracks().forEach(track=>track.stop());const devices=(await navigator.mediaDevices.enumerateDevices()).filter(device=>device.kind==='videoinput');const saved=localStorage.getItem('attendance-camera');cameraSelect.innerHTML=devices.map((device,index)=>`<option value="${device.deviceId}">${device.label||`Camera ${index+1}`}</option>`).join('');if(saved&&[...cameraSelect.options].some(option=>option.value===saved))cameraSelect.value=saved;cameraSelect.disabled=!devices.length;if(!devices.length)cameraSelect.innerHTML='<option>No camera found</option>'}catch(error){cameraSelect.innerHTML='<option value="">Camera permission needed</option>';cameraSelect.disabled=false;console.error('Unable to list cameras.',error)}}
async function startCamera(){if(!navigator.mediaDevices?.getUserMedia){showStatus('This browser does not support camera access.');return}stopCamera();preview.classList.add('hidden');video.classList.remove('hidden');controls.classList.remove('hidden');capture.classList.remove('hidden');retake.classList.add('hidden');help.textContent='Position the student’s face in the center, then capture the photo.';try{const deviceId=cameraSelect.value||localStorage.getItem('attendance-camera')||'';stream=await navigator.mediaDevices.getUserMedia({video:{deviceId:deviceId?{exact:deviceId}:undefined,width:{ideal:1280},height:{ideal:720}},audio:false});video.srcObject=stream;await video.play();capture.disabled=false;useCamera.textContent='Stop camera';status.classList.add('hidden')}catch(error){console.error('Unable to open camera.',error);stopCamera();video.classList.add('hidden');preview.classList.remove('hidden');controls.classList.add('hidden');useCamera.textContent='Use camera';showStatus('Camera access was not available. Check browser permissions and try again.')}}
function resetToPhoto(){stopCamera();video.classList.add('hidden');preview.classList.remove('hidden');controls.classList.add('hidden');useCamera.textContent='Use camera';help.textContent='Use a well-lit, front-facing image for the best recognition result.'}
useCamera.addEventListener('click',()=>stream?resetToPhoto():startCamera());
cameraSelect.addEventListener('change',()=>{localStorage.setItem('attendance-camera',cameraSelect.value);if(stream)startCamera()});
capture.addEventListener('click',()=>{if(!video.videoWidth)return;canvas.width=video.videoWidth;canvas.height=video.videoHeight;canvas.getContext('2d').drawImage(video,0,0,canvas.width,canvas.height);captured.value=canvas.toDataURL('image/jpeg',.9);preview.src=captured.value;input.value='';fileName.textContent='No file chosen';stopCamera();video.classList.add('hidden');preview.classList.remove('hidden');capture.classList.add('hidden');retake.classList.remove('hidden');useCamera.textContent='Use camera';help.textContent='Photo captured. Retake it if needed, or register the student.';showStatus('Camera photo selected. You can still replace it by uploading a file.')});
retake.addEventListener('click',()=>startCamera());
input.addEventListener('change',()=>{if(input.files[0]){preview.src=URL.createObjectURL(input.files[0]);captured.value='';fileName.textContent=input.files[0].name;retake.classList.add('hidden');showStatus('Uploaded photo selected.')}});
window.addEventListener('beforeunload',stopCamera);loadCameras();
</script>
<?php adminFoot(); ?>
