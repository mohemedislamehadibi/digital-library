<?php
session_start();


if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

require_once '../includes/db.php';

$message = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
   
    $title = trim($_POST['title']);
    $author = trim($_POST['author']);
    $category = trim($_POST['category']);

   
    if (empty($title) || empty($author) || empty($category)) {
        $message = "<div class='alert alert-danger'>يرجى ملء جميع الحقول المطلوبة.</div>";
    } elseif (!isset($_FILES['cover_image']) || !isset($_FILES['pdf_file'])) {
        $message = "<div class='alert alert-danger'>يرجى رفع صورة الغلاف وملف PDF.</div>";
    } else {
      
        $uploads_dir_cover = '../assets/uploads/covers/';
        $uploads_dir_pdf = '../assets/uploads/pdfs/';

        $cover_name = basename($_FILES['cover_image']['name']);
        $pdf_name = basename($_FILES['pdf_file']['name']);

      
        $cover_ext = strtolower(pathinfo($cover_name, PATHINFO_EXTENSION));
        $pdf_ext = strtolower(pathinfo($pdf_name, PATHINFO_EXTENSION));

        $cover_new_name = uniqid('cover_') . '.' . $cover_ext;
        $pdf_new_name = uniqid('pdf_') . '.' . $pdf_ext;

        $allowed_cover = ['jpg', 'jpeg', 'png', 'gif'];
        $allowed_pdf = ['pdf'];

       
        if (!in_array($cover_ext, $allowed_cover)) {
            $message = "<div class='alert alert-danger'>صورة الغلاف يجب أن تكون jpg, jpeg, png أو gif.</div>";
        } elseif (!in_array($pdf_ext, $allowed_pdf)) {
            $message = "<div class='alert alert-danger'>الملف يجب أن يكون PDF فقط.</div>";
        } elseif ($_FILES['cover_image']['size'] > 5000000 || $_FILES['pdf_file']['size'] > 50000000) { // 5MB للصورة، 50MB للـPDF
            $message = "<div class='alert alert-danger'>حجم الملف كبير جداً.</div>";
        } else {
            
            if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $uploads_dir_cover . $cover_new_name) &&
                move_uploaded_file($_FILES['pdf_file']['tmp_name'], $uploads_dir_pdf . $pdf_new_name)) {

               
                $stmt = $pdo->prepare("INSERT INTO books (title, author, category, cover_image, pdf_file, created_at, downloads, views) 
                                       VALUES (?, ?, ?, ?, ?, NOW(), 0, 0)");
                $stmt->execute([$title, $author, $category, $cover_new_name, $pdf_new_name]);

                $message = "<div class='alert alert-success'>تم إضافة الكتاب بنجاح!</div>";
               
                $_POST = [];
            } else {
                $message = "<div class='alert alert-danger'>فشل في رفع الملفات. تأكد من صلاحيات المجلدات.</div>";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إضافة كتاب جديد</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; background: #f8f9fa; }
        .navbar { background: #667eea; }
        .form-control, .form-select { margin-bottom: 1rem; }
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
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">إضافة كتاب جديد</h4>
                    </div>
                    <div class="card-body">
                        <?php echo $message; ?>
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label class="form-label">عنوان الكتاب *</label>
                                <input type="text" name="title" class="form-control" value="<?php echo $_POST['title'] ?? ''; ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">اسم المؤلف *</label>
                                <input type="text" name="author" class="form-control" value="<?php echo $_POST['author'] ?? ''; ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">التصنيف *</label>
                                <input type="text" name="category" class="form-control" value="<?php echo $_POST['category'] ?? ''; ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">صورة الغلاف (JPG, PNG, GIF) *</label>
                                <input type="file" name="cover_image" class="form-control" accept="image/*" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">ملف PDF *</label>
                                <input type="file" name="pdf_file" class="form-control" accept=".pdf" required>
                            </div>
                            <button type="submit" class="btn btn-success btn-lg">إضافة الكتاب</button>
                            <a href="dashboard.php" class="btn btn-secondary btn-lg me-2">إلغاء</a>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>