<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

require_once '../includes/db.php';

// جلب التصنيفات من قاعدة البيانات
$categories = $pdo->query("SELECT * FROM categories ORDER BY id")->fetchAll();

$message = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title    = trim($_POST['title'] ?? '');
    $author   = trim($_POST['author'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 5);
    $description = trim($_POST['description'] ?? '');

    if (empty($title) || empty($author)) {
        $message = "<div class='alert alert-danger'>يرجى ملء جميع الحقول المطلوبة.</div>";
    } elseif (!isset($_FILES['cover_image']) || !isset($_FILES['pdf_file'])) {
        $message = "<div class='alert alert-danger'>يرجى رفع صورة الغلاف وملف PDF.</div>";
    } else {
        $uploads_dir_cover = '../assets/uploads/covers/';
        $uploads_dir_pdf   = '../assets/uploads/pdfs/';

        $cover_ext   = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
        $pdf_ext     = strtolower(pathinfo($_FILES['pdf_file']['name'], PATHINFO_EXTENSION));

        $cover_new_name = uniqid('cover_') . '.' . $cover_ext;
        $pdf_new_name   = uniqid('pdf_') . '.' . $pdf_ext;

        $allowed_cover = ['jpg', 'jpeg', 'png', 'gif'];

        if (!in_array($cover_ext, $allowed_cover)) {
            $message = "<div class='alert alert-danger'>صورة الغلاف يجب أن تكون jpg, jpeg, png أو gif.</div>";
        } elseif ($pdf_ext !== 'pdf') {
            $message = "<div class='alert alert-danger'>الملف يجب أن يكون PDF فقط.</div>";
        } elseif ($_FILES['cover_image']['size'] > 5000000) {
            $message = "<div class='alert alert-danger'>حجم صورة الغلاف يجب أن يكون أقل من 5MB.</div>";
        } elseif ($_FILES['pdf_file']['size'] > 50000000) {
            $message = "<div class='alert alert-danger'>حجم ملف PDF يجب أن يكون أقل من 50MB.</div>";
        } else {
            if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $uploads_dir_cover . $cover_new_name) &&
                move_uploaded_file($_FILES['pdf_file']['tmp_name'], $uploads_dir_pdf . $pdf_new_name)) {

                $stmt = $pdo->prepare("INSERT INTO books (title, author, description, category_id, cover_image, pdf_file, created_at, downloads, views) 
                                       VALUES (?, ?, ?, ?, ?, ?, NOW(), 0, 0)");
                $stmt->execute([$title, $author, $description, $category_id, $cover_new_name, $pdf_new_name]);

                $message = "<div class='alert alert-success'>تم إضافة الكتاب بنجاح!</div>";
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
                                <input type="text" name="title" class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">اسم المؤلف *</label>
                                <input type="text" name="author" class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['author'] ?? ''); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">التصنيف *</label>
                                <select name="category_id" class="form-select" required>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>" 
                                            <?php echo (($_POST['category_id'] ?? 5) == $cat['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">وصف الكتاب</label>
                                <textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">صورة الغلاف (JPG, PNG, GIF) * — حد أقصى 5MB</label>
                                <input type="file" name="cover_image" class="form-control" accept="image/*" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">ملف PDF * — حد أقصى 50MB</label>
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