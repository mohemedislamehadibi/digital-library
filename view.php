<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'includes/db.php';

$book_id = $_GET['id'] ?? 0;
if ($book_id <= 0) {
    header("Location: index.php");
    exit();
}

$stmt = $pdo->prepare("SELECT title, pdf_file, views FROM books WHERE id = ?");
$stmt->execute([$book_id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$book || empty($book['pdf_file'])) {
    die("الكتاب غير متوفر أو ملف PDF مفقود.");
}


$stmt = $pdo->prepare("UPDATE books SET views = views + 1 WHERE id = ?");
$stmt->execute([$book_id]);


$pdf_url = "assets/uploads/pdfs/" . urlencode($book['pdf_file']);
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>قراءة: <?php echo htmlspecialchars($book['title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; margin: 0; padding: 0; background: #f8f9fa; overflow: hidden; }
        #toolbar { 
            background: #667eea; 
            padding: 15px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.2); 
            position: fixed; 
            top: 0; 
            width: 100%; 
            z-index: 1000; 
        }
        #pdf-container { 
            margin-top: 70px; 
            height: calc(100vh - 70px); 
            width: 100%;
        }
        iframe { border: none; }
    </style>
</head>
<body>
    <div id="toolbar" class="text-white">
        <div class="container-fluid d-flex justify-content-between align-items-center">
            <h5 class="mb-0">قراءة: <?php echo htmlspecialchars($book['title']); ?></h5>
            <a href="book.php?id=<?php echo $book_id; ?>" class="btn btn-outline-light btn-sm">رجوع إلى التفاصيل</a>
        </div>
    </div>
<div id="pdf-container">
    <iframe 
        src="<?php echo $pdf_url; ?>#view=FitH&toolbar=1&navpanes=1&scrollbar=1"
        style="width: 100%; height: 100%;"
        allowfullscreen
        webkitallowfullscreen>
    </iframe>
</div>
</body>
</html>