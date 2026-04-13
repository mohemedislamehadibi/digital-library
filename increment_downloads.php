<?php
session_start();
require_once 'includes/db.php';

$book_id = (int)($_GET['id'] ?? 0);
if ($book_id <= 0) {
    header("Location: index.php");
    exit();
}

$stmt = $pdo->prepare("SELECT title, pdf_file FROM books WHERE id = ?");
$stmt->execute([$book_id]);
$book = $stmt->fetch();

if (!$book || empty($book['pdf_file'])) {
    header("Location: index.php");
    exit();
}

// زيادة العداد
$pdo->prepare("UPDATE books SET downloads = downloads + 1 WHERE id = ?")->execute([$book_id]);

// التحقق إذا كان رابط خارجي
if (filter_var($book['pdf_file'], FILTER_VALIDATE_URL)) {
    // رابط خارجي — وجّه مباشرة
    header("Location: " . $book['pdf_file']);
    exit();
}

// ملف محلي
$file_path = 'assets/uploads/pdfs/' . $book['pdf_file'];

if (!file_exists($file_path)) {
    header("Location: index.php");
    exit();
}

$download_name = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $book['title']) . '.pdf';
header('Content-Description: File Transfer');
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $download_name . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($file_path));
readfile($file_path);
exit();
?>