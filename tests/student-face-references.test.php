<?php
/** Uses a transaction and rolls back every fixture. Run against the local test installation. */
require __DIR__ . '/../config/db_conn.php';
require __DIR__ . '/../includes/student-face-references.php';
function checkFace(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
$before = $conn->query('SELECT ID, STUDENT_ID, IMAGE_PATH FROM students ORDER BY ID')->fetch_all(MYSQLI_ASSOC);
$attendanceBefore = $conn->query('SELECT COUNT(*) FROM attendance')->fetch_row()[0];
ensureStudentFaceReferences($conn);
ensureStudentFaceReferences($conn);
checkFace($before === $conn->query('SELECT ID, STUDENT_ID, IMAGE_PATH FROM students ORDER BY ID')->fetch_all(MYSQLI_ASSOC), 'Rerunning migration preserves existing students and image paths');
checkFace($attendanceBefore === $conn->query('SELECT COUNT(*) FROM attendance')->fetch_row()[0], 'Migration preserves attendance count');
checkFace(!(bool)$conn->query("SELECT s.ID FROM students s LEFT JOIN student_face_references r ON r.STUDENT_ID=s.ID AND r.POSE='front' WHERE s.IMAGE_PATH<>'' AND (r.ID IS NULL OR r.IMAGE_PATH<>s.IMAGE_PATH) LIMIT 1")->fetch_row(), 'Legacy images are front references without file duplication');
$_POST=[]; $_FILES=[];
$failed=false;
try { prepareStudentFaceUploads(true); } catch (RuntimeException $error) { $failed=str_contains($error->getMessage(),'front view'); }
checkFace($failed,'New enrollment requires a front view');
checkFace(prepareStudentFaceUploads(false)===[],'Existing students need no new uploads');
$conn->begin_transaction();
$created=[];
$sources=[];
try {
    $lrn = 'face-test-' . bin2hex(random_bytes(4));
    $stmt = $conn->prepare("INSERT INTO students (STUDENT_ID,STUDENT_NAME,IMAGE_PATH) VALUES (?,'Face Test','image/student-preview.png')");
    $stmt->bind_param('s', $lrn); $stmt->execute(); $id = (int)$conn->insert_id;
    $insert = $conn->prepare('INSERT INTO student_face_references (STUDENT_ID,POSE,IMAGE_PATH) VALUES (?,?,?)');
    $path='image/student-preview.png';
    foreach (['front','left','right'] as $pose) { $insert->bind_param('iss',$id,$pose,$path); $insert->execute(); }
    checkFace(count(studentFaceReferences($conn,$id))===3,'One student supports all three pose slots');
    $failed=false;
    try { $insert->execute(); } catch (mysqli_sql_exception $error) { $failed=true; }
    checkFace($failed,'Duplicate student/pose is rejected');
    $pose='other'; $failed=false;
    try { $insert->execute(); } catch (mysqli_sql_exception $error) { $failed=true; }
    checkFace($failed,'A fourth pose is rejected');
    $pose='front'; $invalidId=2147483647; $insert->bind_param('iss',$invalidId,$pose,$path); $failed=false;
    try { $insert->execute(); } catch (mysqli_sql_exception $error) { $failed=true; }
    checkFace($failed,'Foreign key rejects nonexistent students');
    // Exercise storage independently of Python quality validation using synthetic fixtures.
    $prepared=[];
    foreach (['front','left','right'] as $pose) {
        $source=tempnam(__DIR__.'/../tmp','face-storage-');
        $sources[]=$source;
        file_put_contents($source,file_get_contents(__DIR__.'/../image/student-preview.png')."\nfixture-$pose");
        $prepared[$pose]=['source'=>$source,'extension'=>'png'];
    }
    saveStudentFaceUploads($conn,$id,$prepared,$created);
    $stored=studentFaceReferences($conn,$id);
    $correct=count($stored)===3;
    foreach ($prepared as $pose=>$photo) $correct=$correct && hash_file('sha256',recognitionReferencePath($stored[$pose]))===hash_file('sha256',$photo['source']);
    checkFace($correct,'All three pose files persist independently with their exact source bytes');
    $profile=$conn->query("SELECT IMAGE_PATH FROM students WHERE ID=$id")->fetch_assoc();
    checkFace($profile['IMAGE_PATH']===$stored['front'],'Saving the front reference synchronizes the primary profile image');
    saveStudentFaceUploads($conn,$id,['left'=>$prepared['left']],$created);
    $retaken=studentFaceReferences($conn,$id);
    checkFace($retaken['left']!==$stored['left'] && $retaken['front']===$stored['front'] && $retaken['right']===$stored['right'],'Retaking left replaces only the left reference');
    saveStudentFaceUploads($conn,$id,['left'=>['remove'=>true],'right'=>['remove'=>true]],$created);
    checkFace(array_keys(studentFaceReferences($conn,$id))===['front'],'Optional removals keep the front reference');
    $refs=recognitionStudentReferences($conn);
    checkFace(count(array_filter($refs,fn($ref)=>$ref['student_id']===$lrn))===1,'One front photo remains eligible for matching');
    $conn->query("UPDATE students SET ENROLLMENT_STATUS='Archived' WHERE ID=$id");
    checkFace(!array_filter(recognitionStudentReferences($conn),fn($ref)=>$ref['student_id']===$lrn),'Archived students are excluded from matching');
    $conn->query("UPDATE student_face_references SET IMAGE_PATH='missing-test-file.jpg' WHERE STUDENT_ID=$id");
    $failed=false;
    try { saveStudentFaceUploads($conn,$id,['left'=>['remove'=>true]],$created); } catch (RuntimeException $error) { $failed=true; }
    checkFace($failed,'Removal is rejected when no usable reference remains');
    checkFace(recognitionReferencePath('../../Windows/win.ini')===null,'Path traversal is rejected');
} finally {
    $conn->rollback();
    foreach (array_merge($created,$sources) as $file) if (is_file($file)) unlink($file);
}
