<?php
require "page-guard.php";
require_once __DIR__ . '/../includes/csrf-token.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(400); exit('Invalid request.');
}
if ($_SESSION["user-role"] !== "administrator") {
    http_response_code(403);
    exit("Administrators only.");
}
require "../config/db_conn.php";
require "../includes/teacher-profile-schema.php";
require "../includes/teacher-assignment-schema.php";
require "../includes/adviser-assignment-schema.php";
require "../includes/student-placement-options.php";
require "../includes/section-catalog-schema.php";
ensureTeacherProfileColumns($conn);
ensureSectionCatalogColumns($conn);
seedDefaultTeacherSections($conn);
ensureTeacherAssignmentSchema($conn);
ensureAdviserAssignmentSchema($conn);
$firstName = trim($_POST["first_name"] ?? "");
$lastName = trim($_POST["last_name"] ?? "");
$name = trim($firstName . " " . $lastName);
$user = trim($_POST["teacher_user"] ?? "");
$pass = $_POST["teacher_pass"] ?? "";
$confirm = $_POST["teacher_confirm"] ?? "";
$assignments = postedTeacherAssignments($conn);
if (isset($_POST['assignment_grade']) && !$assignments) {
    http_response_code(400); exit('Choose valid subject teaching assignments or remove the empty assignment rows.');
}
if (
    $firstName === "" ||
    $lastName === "" ||
    !filter_var($user, FILTER_VALIDATE_EMAIL) ||
    strlen($pass) < 8 ||
    $pass !== $confirm
) {
    exit("Please provide valid teacher account details.");
}
$primary = [
    "grade" => 0,
    "strand" => "",
    "section" => "",
    "subject" => "",
];
$conn->begin_transaction();
try {
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $stmt = $conn->prepare(
        "INSERT INTO teachers (TEACHER_USER,TEACHER_PASS,TEACHER_NAME,FIRST_NAME,LAST_NAME,GRADE,STRAND,SECTION,SUBJECT) VALUES (?,?,?,?,?,?,?,?,?)",
    );
    $stmt->bind_param(
        "sssssisss",
        $user,
        $hash,
        $name,
        $firstName,
        $lastName,
        $primary["grade"],
        $primary["strand"],
        $primary["section"],
        $primary["subject"],
    );
    if (!$stmt->execute()) {
        http_response_code(409);
        exit("That teacher username already exists.");
    }
    $teacherId = (int) $conn->insert_id;
    $conn->query("UPDATE teachers SET MUST_CHANGE_PASSWORD=1 WHERE TEACHER_ID=".$teacherId);
    if ($assignments) {
        replaceTeacherAssignments($conn, $teacherId, $assignments);
    }
    $importedAssignments = empty($_POST['advisory_section_id']) ? assignRememberedAdviserClasses($conn, $teacherId, $name) : 0;
    if (!empty($_POST['advisory_section_id'])) setTeacherAdvisory($conn, $teacherId, (int)$_POST['advisory_section_id']);
    requireTeacherAdvisory($conn,$teacherId);
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    $_SESSION['toast'] = ['message' => $error instanceof mysqli_sql_exception ? 'No changes saved. The email or advisory section is already assigned, or the assignment could not be saved.' : 'No changes saved. '.$error->getMessage(), 'type' => 'error'];
    header('Location: teacher-list.php'); exit;
}
$_SESSION["toast"] = [
    "message" => $assignments
        ? "Teacher account and assignment created successfully."
        : ($importedAssignments
            ? "Teacher account created and matched to " .
                $importedAssignments .
                " imported adviser class" .
                ($importedAssignments === 1 ? "." : "es.")
            : 'Teacher account and advisory section created successfully.'),
    "type" => "success",
];
header("Location: teacher-list.php");
exit();
