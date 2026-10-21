<?php
session_start();
require_once __DIR__ . '/../includes/icons.php';
require "../config/db_conn.php";
require "../includes/csrf-token.php";
$conn->query(
    "CREATE TABLE IF NOT EXISTS admin_security (ADMIN_USER VARCHAR(50) PRIMARY KEY, SECURITY_QUESTION VARCHAR(255) NOT NULL, SECURITY_ANSWER VARCHAR(255) NOT NULL, UPDATED_AT TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
);
$error = $_SESSION["reset-error"] ?? "";
unset($_SESSION["reset-error"]);
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["step"] ?? "") === "identify"
) {
    if (
        !hash_equals($_SESSION["csrf_token"] ?? "", $_POST["csrf_token"] ?? "")
    ) {
        $error = "Invalid request.";
    } else {
        $username = trim($_POST["username"] ?? "");
        $stmt = $conn->prepare(
            "SELECT s.SECURITY_QUESTION FROM admins a INNER JOIN admin_security s ON s.ADMIN_USER=a.ADMIN_USER WHERE a.ADMIN_USER=?",
        );
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $security = $stmt->get_result()->fetch_assoc();
        if ($security) {
            $_SESSION["password-reset-user"] = $username;
            header("Location: forgot-password.php");
            exit();
        }
        $error = "Password recovery is not configured for that account.";
    }
}
$user = $_SESSION["password-reset-user"] ?? "";
$question = "";
if ($user) {
    $stmt = $conn->prepare(
        "SELECT SECURITY_QUESTION FROM admin_security WHERE ADMIN_USER=?",
    );
    $stmt->bind_param("s", $user);
    $stmt->execute();
    $question = $stmt->get_result()->fetch_assoc()["SECURITY_QUESTION"] ?? "";
    if (!$question) {
        unset($_SESSION["password-reset-user"]);
        $user = "";
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reset password | Biometric</title>
    <script src="../js/theme.js?v=<?= (int) @filemtime(__DIR__ . '/../js/theme.js') ?>"></script>
    <link rel="stylesheet" href="../css/tailwind.css?v=<?= (int) @filemtime(__DIR__ . '/../css/tailwind.css') ?>">
    <link rel="stylesheet" href="../css/theme.css?v=<?= (int) @filemtime(__DIR__ . '/../css/theme.css') ?>">
</head>

<body class="grid min-h-screen place-items-center bg-slate-950 p-5">
    <main class="card w-full max-w-md p-7 shadow-2xl"><a href="login.php"
            class="inline-flex items-center gap-1 text-sm font-bold text-blue-600"><?= icon('arrow-left') ?> Back to sign in</a>
        <h1 class="mt-5 text-2xl font-black text-slate-900">Reset password</h1><?php
if (
    $error
): ?><p class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700"><?= htmlspecialchars(
    $error,
) ?></p><?php endif;
if (
    !$user
): ?><p class="mt-2 text-slate-500">Enter your administrator username to continue.</p>
        <form method="post" class="mt-6 space-y-4"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(
    $_SESSION["csrf_token"],
) ?>"><input type="hidden" name="step" value="identify"><label class="block text-sm font-bold">Administrator
                username<input class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" name="username" required
                    autofocus></label><button
                class="btn btn-primary w-full">Continue</button></form>
        <?php else: ?><p class="mt-3 text-sm font-bold text-slate-700">Security question</p>
        <p class="mt-1 rounded-xl bg-slate-100 p-4 text-slate-800"><?= htmlspecialchars(
    $question,
) ?></p>
        <form action="reset-password.php" method="post" class="mt-6 space-y-4"><input type="hidden" name="csrf_token"
                value="<?= htmlspecialchars(
    $_SESSION["csrf_token"],
) ?>"><label class="block text-sm font-bold">Your answer<input
                    class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" name="security_answer" required
                    autofocus></label><label class="block text-sm font-bold">New password<input
                    class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" type="password" name="new_password"
                    minlength="8" required></label><label class="block text-sm font-bold">Confirm new password<input
                    class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" type="password"
                    name="confirm_password" minlength="8" required></label><button
                class="btn btn-primary w-full">Reset password</button></form><?php endif;
?>
    </main>
</body>

</html>