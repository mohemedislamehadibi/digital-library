<?php
require_once 'includes/db.php';



$book_id = $_GET['id'] ?? 0;
if ($book_id <= 0) {
    http_response_code(400);
    exit();
}


$stmt = $pdo->prepare("UPDATE books SET downloads = downloads + 1 WHERE id = ?");
$stmt->execute([$book_id]);


$stmt = $pdo->prepare("SELECT pdf_file FROM books WHERE id = ?");
$stmt->execute([$book_id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);

if ($book && $book['pdf_file']) {
    $file_path = 'assets/uploads/pdfs/' . $book['pdf_file'];
    
    if (file_exists($file_path)) {
       
        header('Content-Description: File Transfer');
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));
        
       
        readfile($file_path);
        exit();
    }
}


http_response_code(404);
echo "الملف غير موجود.";
?>