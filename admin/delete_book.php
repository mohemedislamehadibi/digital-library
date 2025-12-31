
<?php
session_start();


if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

require_once '../includes/db.php';


$book_id = $_GET['id'] ?? 0;
if ($book_id <= 0) {
    header("Location: dashboard.php");
    exit();
}


$stmt = $pdo->prepare("SELECT cover_image, pdf_file FROM books WHERE id = ?");
$stmt->execute([$book_id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$book) {
    header("Location: dashboard.php");
    exit();
}


$cover_path = '../assets/uploads/covers/' . $book['cover_image'];
$pdf_path = '../assets/uploads/pdfs/' . $book['pdf_file'];


if ($book['cover_image'] && file_exists($cover_path)) {
    unlink($cover_path); 
}
if ($book['pdf_file'] && file_exists($pdf_path)) {
    unlink($pdf_path); 
}


$stmt = $pdo->prepare("DELETE FROM books WHERE id = ?");
$stmt->execute([$book_id]);


$_SESSION['message'] = "<div class='alert alert-success'>تم حذف الكتاب بنجاح مع جميع ملفاته.</div>";


header("Location: dashboard.php");
exit();
?>