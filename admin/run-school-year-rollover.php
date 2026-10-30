<?php
require 'page-guard.php';
require_once '../config/db_conn.php';
require_once '../includes/promotion-runner.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function rolloverFail(string $message): void
{
    $_SESSION['toast']=['message'=>$message,'type'=>'error'];
    header('Location: school-year-rollover.php'); exit;
}
if ($_SERVER['REQUEST_METHOD']!=='POST' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],(string)($_POST['csrf_token'] ?? ''))) rolloverFail('The rollover request expired.');
if (($_POST['end_school_year_confirmation'] ?? '')!=='1') rolloverFail('Confirm that the school year has ended.');
$admin=$conn->prepare('SELECT ADMIN_PASS FROM admins WHERE ADMIN_USER=?');
$admin->bind_param('s',$_SESSION['admin-user']); $admin->execute(); $account=$admin->get_result()->fetch_assoc();
if (!$account || !password_verify((string)($_POST['current_password'] ?? ''),$account['ADMIN_PASS'])) rolloverFail('Administrator password is incorrect. No student records were changed.');
try {
    $posted=$_POST['plans'] ?? [];
    if (!is_array($posted)) throw new RuntimeException('Choose valid promotion targets.');
    $targets=promotionValidatedTargets($posted);
    $result=executePromotionRollover($conn,(string)($_POST['expected_school_year'] ?? ''),$targets,(string)($_POST['preview_fingerprint'] ?? ''),$_SESSION['admin-user']);
    $s=$result['summary'];
    $_SESSION['toast']=['message'=>"Rollover complete: {$s['promoted']} promoted, {$s['honor']} honor-qualified, {$s['retained']} retained, {$s['summer']} requiring summer class, {$s['graduated']} graduated; {$result['created']} regular overflow sections created. Active school year: {$result['next_year']}.",'type'=>'success'];
} catch (mysqli_sql_exception $error) {
    error_log('Rollover database failure: '.$error->getMessage());
    rolloverFail('Rollover failed. The transaction was rolled back; student placements and school-year history were not changed.');
} catch (RuntimeException $error) { rolloverFail($error->getMessage()); }
catch (Throwable $error) {
    error_log('Rollover failed: '.$error->getMessage());
    rolloverFail('Rollover failed. The transaction was rolled back; student placements and school-year history were not changed.');
}
header('Location: school-year-rollover.php'); exit;
