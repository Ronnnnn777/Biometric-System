<?php
session_start();
if(!empty($_SESSION['is-authenticated'])){header('Location: '.(($_SESSION['user-role']??'')==='teacher'?'../teacher/home.php':'home.php'));exit;}
require '../config/db_conn.php';
require '../includes/default-admin.php';
require '../includes/csrf-token.php';
ensureDefaultAdministrator($conn);
$resetSuccess=$_SESSION['reset-success']??'';
$loginError=$_SESSION['login-error']??'';
unset($_SESSION['reset-success'],$_SESSION['login-error']);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Sign in | Biometric</title><script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-800">
  <main class="grid min-h-screen lg:grid-cols-2">
    <section class="hidden bg-gradient-to-br from-blue-600 via-blue-700 to-slate-950 p-12 text-white lg:flex lg:flex-col lg:justify-between">
      <a href="../index.php" class="flex items-center gap-3 text-xl font-bold"><img src="../image/logo.png" alt="" class="h-11 w-11 rounded-xl">Biometric.</a>
      <div><p class="text-sm font-bold uppercase tracking-widest text-blue-200">Attendance management</p><h1 class="mt-4 max-w-lg text-5xl font-black tracking-tight">A clearer view of every school day.</h1><p class="mt-6 max-w-md text-lg leading-8 text-blue-100">Register students, watch attendance, and find the records you need.</p></div>
      <p class="text-sm text-blue-200">Secure school access</p>
    </section>
    <section class="flex items-center justify-center bg-slate-50 p-5">
      <div class="w-full max-w-md"><div class="mt-6 rounded-2xl bg-white p-7 shadow-xl shadow-slate-900/10 sm:p-9">
        <p class="text-sm font-bold uppercase tracking-widest text-blue-600">Welcome back</p><h2 class="mt-2 text-3xl font-black tracking-tight text-slate-900">Sign in</h2>
        <p id="login-help" class="mt-2 text-slate-500">Use your email address and password to continue.</p>
        <?php if($resetSuccess): ?><p class="mt-4 rounded-xl bg-emerald-50 p-3 text-sm font-semibold text-emerald-700"><?= htmlspecialchars($resetSuccess) ?></p><?php endif; ?>
        <?php if($loginError): ?><p class="mt-4 rounded-xl bg-rose-50 p-3 text-sm font-semibold text-rose-700"><?= htmlspecialchars($loginError) ?></p><?php endif; ?>
        <form id="login-form" action="auth.php" method="post" class="mt-8 space-y-5">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <div><label for="username" class="mb-2 block text-sm font-bold text-slate-700">Email address</label><input class="w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100" type="email" name="username" id="username" autocomplete="username" required autofocus></div>
          <div><label for="password" class="mb-2 block text-sm font-bold text-slate-700">Password</label><input class="w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100" type="password" name="password" id="password" autocomplete="current-password" required></div>
          <div id="first-time-passwords" class="hidden space-y-5 rounded-xl border border-blue-100 bg-blue-50 p-4">
            <p class="text-sm font-semibold text-blue-900">First-time setup: choose a new administrator password.</p>
            <label class="block text-sm font-bold text-slate-700">New Password<input class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" type="password" name="new_password" id="new-password" minlength="8" autocomplete="new-password"></label>
            <label class="block text-sm font-bold text-slate-700">Confirm New Password<input class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" type="password" name="confirm_password" id="confirm-password" minlength="8" autocomplete="new-password"></label>
          </div>
          <div class="text-right"><a href="forgot-password.php" class="text-sm font-bold text-blue-600 hover:text-blue-700">Forgot password?</a></div>
          <button id="login-button" class="w-full rounded-xl bg-blue-600 px-4 py-3 font-bold text-white shadow-sm hover:bg-blue-700">Sign in</button>
        </form>
      </div></div>
    </section>
  </main>
  <script>
    const form=document.getElementById('login-form'),email=document.getElementById('username'),password=document.getElementById('password'),setup=document.getElementById('first-time-passwords'),newPassword=document.getElementById('new-password'),confirmPassword=document.getElementById('confirm-password'),button=document.getElementById('login-button'),help=document.getElementById('login-help');
    const isDefaultCredentials=()=>email.value.trim().toLowerCase()==='admin@biometrics.com'&&password.value==='password';
    function updateFirstTimeSetup(){
      if(isDefaultCredentials()){
        if(!setup.classList.contains('hidden'))return;
        setup.classList.remove('hidden');newPassword.required=true;confirmPassword.required=true;
        help.textContent='First-time setup is required. Create a new password to continue.';
        button.textContent='Save new password and sign in';newPassword.focus();return;
      }
      if(!setup.classList.contains('hidden')){
        setup.classList.add('hidden');newPassword.required=false;confirmPassword.required=false;newPassword.value='';confirmPassword.value='';
        help.textContent='Use your email address and password to continue.';button.textContent='Sign in';
      }
    }
    email.addEventListener('input',updateFirstTimeSetup);
    password.addEventListener('input',updateFirstTimeSetup);
    form.addEventListener('submit',event=>{
      if(isDefaultCredentials()&&setup.classList.contains('hidden')){event.preventDefault();updateFirstTimeSetup();return;}
      if(!setup.classList.contains('hidden')&&(newPassword.value!==confirmPassword.value||newPassword.value.length<8||newPassword.value==='password')){
        event.preventDefault();help.textContent='Use matching passwords with at least 8 characters and do not reuse the default password.';newPassword.focus();
      }
    });
    window.addEventListener('pageshow',event=>{if(event.persisted)window.location.reload()});
  </script>
</body>
</html>
