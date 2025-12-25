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


$stmt = $pdo->prepare("SELECT * FROM books WHERE id = ?");
$stmt->execute([$book_id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$book) {
    header("Location: dashboard.php");
    exit();
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title']);
    $author = trim($_POST['author']);
    $category = trim($_POST['category']);

    if (empty($title) || empty($author) || empty($category)) {
        $message = "<div class='alert alert-danger'>يرجى ملء جميع الحقول المطلوبة.</div>";
    } else {
      
        $cover_new_name = $book['cover_image'];
        $pdf_new_name = $book['pdf_file'];

       
        if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] == 0) {
            $uploads_dir_cover = '../assets/uploads/covers/';
            $cover_name = basename($_FILES['cover_image']['name']);
            $cover_ext = strtolower(pathinfo($cover_name, PATHINFO_EXTENSION));
            $allowed_cover = ['jpg', 'jpeg', 'png', 'gif'];

            if (in_array($cover_ext, $allowed_cover)) {
                $cover_new_name = uniqid('cover_') . '.' . $cover_ext;
                if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $uploads_dir_cover . $cover_new_name)) {
                  
                    if ($book['cover_image'] && file_exists($uploads_dir_cover . $book['cover_image'])) {
                        unlink($uploads_dir_cover . $book['cover_image']);
                    }
                } else {
                    $message = "<div class='alert alert-danger'>فشل رفع صورة الغلاف.</div>";
                }
            } else {
                $message = "<div class='alert alert-danger'>صورة الغلاف يجب أن تكون jpg, jpeg, png أو gif.</div>";
            }
        }

        
        if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] == 0 && empty($message)) {
            $uploads_dir_pdf = '../assets/uploads/pdfs/';
            $pdf_name = basename($_FILES['pdf_file']['name']);
            $pdf_ext = strtolower(pathinfo($pdf_name, PATHINFO_EXTENSION));

            if ($pdf_ext === 'pdf') {
                $pdf_new_name = uniqid('pdf_') . '.' . $pdf_ext;
                if (move_uploaded_file($_FILES['pdf_file']['tmp_name'], $uploads_dir_pdf . $pdf_new_name)) {
                   
                    if ($book['pdf_file'] && file_exists($uploads_dir_pdf . $book['pdf_file'])) {
                        unlink($uploads_dir_pdf . $book['pdf_file']);
                    }
                } else {
                    $message = "<div class='alert alert-danger'>فشل رفع ملف PDF.</div>";
                }
            } else {
                $message = "<div class='alert alert-danger'>الملف يجب أن يكون PDF فقط.</div>";
            }
        }

        
        if (empty($message)) {
            $stmt = $pdo->prepare("UPDATE books SET title = ?, author = ?, category = ?, cover_image = ?, pdf_file = ? WHERE id = ?");
            $stmt->execute([$title, $author, $category, $cover_new_name, $pdf_new_name, $book_id]);

            $message = "<div class='alert alert-success'>تم تعديل الكتاب بنجاح!</div>";
           
            $book['title'] = $title;
            $book['author'] = $author;
            $book['category'] = $category;
            $book['cover_image'] = $cover_new_name;
            $book['pdf_file'] = $pdf_new_name;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تعديل الكتاب: <?php echo htmlspecialchars($book['title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; background: #f8f9fa; }
        .navbar { background: #667eea; }
        .current-cover img { max-width: 150px; border-radius: 8px; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">لوحة التحكم</a>
            <a href="dashboard.php" class="btn btn-outline-light">رجوع</a>
        </div>
    </nav>

    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header bg-warning text-dark">
                        <h4 class="mb-0">تعديل الكتاب: <?php echo htmlspecialchars($book['title']); ?></h4>
                    </div>
                    <div class="card-body">
                        <?php echo $message; ?>

                        
                        <div class="mb-3 current-cover text-center">
                            <?php if ($book['cover_image']): ?>
                                <img src="../assets/uploads/covers/<?php echo htmlspecialchars($book['cover_image']); ?>" alt="الغلاف الحالي">
                                <p class="text-muted mt-2">الغلاف الحالي</p>
                            <?php else: ?>
                                <div class="bg-secondary text-white p-3">لا يوجد غلاف حالياً</div>
                            <?php endif; ?>
                        </div>

                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label class="form-label">عنوان الكتاب *</label>
                                <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($book['title']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">اسم المؤلف *</label>
                                <input type="text" name="author" class="form-control" value="<?php echo htmlspecialchars($book['author']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">التصنيف *</label>
                                <input type="text" name="category" class="form-control" value="<?php echo htmlspecialchars($book['category']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">صورة غلاف جديدة (اختياري - سيحذف القديم تلقائياً)</label>
                                <input type="file" name="cover_image" class="form-control" accept="image/*">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">ملف PDF جديد (اختياري - سيحذف القديم تلقائياً)</label>
                                <input type="file" name="pdf_file" class="form-control" accept=".pdf">
                            </div>
                            <button type="submit" class="btn btn-warning btn-lg">حفظ التعديلات</button>
                            <a href="dashboard.php" class="btn btn-secondary btn-lg me-2">إلغاء</a>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>