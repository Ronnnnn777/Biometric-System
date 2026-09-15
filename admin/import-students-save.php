<?php
session_start();
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/student-profile-schema.php';
require '../includes/student-placement-options.php';
require '../includes/student-import.php';
ensureStudentProfileColumns($conn);

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid request.');
}

function importRedirect(array $result): void
{
    $_SESSION['student-import-result'] = $result;
    header('Location: import-students.php');
    exit;
}

$file = $_FILES['student_file'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
    importRedirect(['error' => 'Choose a CSV or .xlsx file to import.']);
}
if ($file['size'] > 5 * 1024 * 1024) importRedirect(['error' => 'The import file must be 5 MB or smaller.']);

$extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
if (!in_array($extension, ['csv', 'xlsx'], true)) importRedirect(['error' => 'Use a CSV or modern Excel .xlsx file. Save older .xls files as .xlsx or CSV first.']);

try {
    $allRows = studentImportReadRows($file['tmp_name'], $extension);
} catch (Throwable $error) {
    importRedirect(['error' => $error->getMessage()]);
}

if (count($allRows) < 2) importRedirect(['error' => 'The file needs a header row and at least one student row.']);
$headers = array_shift($allRows);
$headerMap = studentImportHeaderMap($headers);
if (!isset($headerMap['student_id'], $headerMap['grade'], $headerMap['age'], $headerMap['address'], $headerMap['section']) || (!isset($headerMap['student_name']) && (!isset($headerMap['first_name']) || !isset($headerMap['last_name'])))) {
    importRedirect(['error' => 'Required columns: Student ID, First Name, Last Name, Grade, Age, Strand, Address, Section. You may use Student Name instead of first and last name.']);
}
if (count($allRows) > 1000) importRedirect(['error' => 'Import up to 1,000 student rows at a time.']);

$exists = $conn->prepare('SELECT 1 FROM students WHERE STUDENT_ID = ? LIMIT 1');
$insert = $conn->prepare('INSERT INTO students (STUDENT_ID, STUDENT_NAME, FIRST_NAME, LAST_NAME, GRADE, AGE, STRAND, ADDRESS, SECTION, ENROLLMENT_STATUS, IMAGE_PATH) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$seen = [];
$inserted = 0;
$skipped = [];
$defaultImage = 'image/student-preview.png';

$conn->begin_transaction();
try {
    foreach ($allRows as $index => $row) {
        $rowNumber = $index + 2;
        $studentId = studentImportCell($row, $headerMap, 'student_id');
        $firstName = studentImportCell($row, $headerMap, 'first_name');
        $lastName = studentImportCell($row, $headerMap, 'last_name');
        $fullName = studentImportCell($row, $headerMap, 'student_name');
        if (($firstName === '' || $lastName === '') && $fullName !== '') {
            $parts = preg_split('/\s+/', $fullName, 2);
            $firstName = $firstName ?: ($parts[0] ?? '');
            $lastName = $lastName ?: ($parts[1] ?? $firstName);
        }
        $grade = (int) studentImportCell($row, $headerMap, 'grade');
        $age = (int) studentImportCell($row, $headerMap, 'age');
        $strand = studentImportCell($row, $headerMap, 'strand');
        $address = studentImportCell($row, $headerMap, 'address');
        $section = studentImportCell($row, $headerMap, 'section');
        $enrollmentStatus = studentImportCell($row, $headerMap, 'enrollment_status') ?: 'Enrolled';
        $studentName = trim($firstName . ' ' . $lastName);

        if ($studentId === '' && $firstName === '' && $lastName === '' && $grade === 0 && $age === 0 && $address === '' && $section === '') continue;
        if ($studentId === '' || $firstName === '' || $lastName === '' || !isValidStudentAge($age) || $address === '' || !isValidStudentPlacement($grade, $strand, $section) || !isValidStudentEnrollmentStatus($enrollmentStatus)) {
            $skipped[] = "Row {$rowNumber}: missing or invalid Student ID, name, age, address, grade, strand, section, or status.";
            continue;
        }
        if (strlen($studentId) > 80 || strlen($firstName) > 80 || strlen($lastName) > 80 || strlen($strand) > 20 || strlen($address) > 255 || strlen($section) > 80 || strlen($enrollmentStatus) > 20) {
            $skipped[] = "Row {$rowNumber}: one or more values are too long.";
            continue;
        }
        if (isset($seen[$studentId])) {
            $skipped[] = "Row {$rowNumber}: Student ID {$studentId} is duplicated in this file.";
            continue;
        }
        $seen[$studentId] = true;
        $exists->bind_param('s', $studentId);
        $exists->execute();
        if ($exists->get_result()->num_rows) {
            $skipped[] = "Row {$rowNumber}: Student ID {$studentId} is already registered.";
            continue;
        }

        $insert->bind_param('ssssiisssss', $studentId, $studentName, $firstName, $lastName, $grade, $age, $strand, $address, $section, $enrollmentStatus, $defaultImage);
        $insert->execute();
        $inserted++;
    }
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    importRedirect(['error' => 'Nothing was imported because the database could not save the file: ' . $error->getMessage()]);
}

importRedirect(['inserted' => $inserted, 'skipped' => $skipped]);
