<?php
session_start();
require_once '../includes/csrf.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

require_once '../includes/db.php';
// جلب بيانات الكتاب الحالي
$book_id = (int)($_GET['id'] ?? 0);
if ($book_id <= 0) {
    header("Location: dashboard.php");
    exit();
}

$stmt = $pdo->prepare("SELECT b.*, c.name as category_name FROM books b LEFT JOIN categories c ON b.category_id = c.id WHERE b.id = ?");
$stmt->execute([$book_id]);
$book = $stmt->fetch();

if (!$book) {
    header("Location: dashboard.php");
    exit();
}

$categories = $pdo->query("SELECT * FROM categories ORDER BY id")->fetchAll();
$message = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = "<div class='alert alert-danger'>طلب غير صالح!</div>";
    } else {
        $title       = trim($_POST['title'] ?? '');
        $author      = trim($_POST['author'] ?? '');
        $category_id = (int)($_POST['category_id'] ?? 5);
        $description = trim($_POST['description'] ?? '');

        if (empty($title) || empty($author)) {
            $message = "<div class='alert alert-danger'>يرجى ملء جميع الحقول المطلوبة.</div>";
        } else {
            $cover_new_name = $book['cover_image'];
            $pdf_new_name   = $book['pdf_file'];

            if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] == 0) {
                $cover_ext     = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
                $allowed_cover = ['jpg', 'jpeg', 'png', 'gif'];

                if (!in_array($cover_ext, $allowed_cover)) {
                    $message = "<div class='alert alert-danger'>صورة الغلاف يجب أن تكون jpg, jpeg, png أو gif.</div>";
                } elseif ($_FILES['cover_image']['size'] > 5000000) {
                    $message = "<div class='alert alert-danger'>حجم صورة الغلاف يجب أن يكون أقل من 5MB.</div>";
                } else {
                    $cover_new_name    = uniqid('cover_') . '.' . $cover_ext;
                    $uploads_dir_cover = '../assets/uploads/covers/';
                    if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $uploads_dir_cover . $cover_new_name)) {
                        if ($book['cover_image'] && file_exists($uploads_dir_cover . $book['cover_image'])) {
                            unlink($uploads_dir_cover . $book['cover_image']);
                        }
                    } else {
                        $message = "<div class='alert alert-danger'>فشل رفع صورة الغلاف.</div>";
                    }
                }
            }

            if (empty($message) && isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] == 0) {
                $pdf_ext = strtolower(pathinfo($_FILES['pdf_file']['name'], PATHINFO_EXTENSION));

                if ($pdf_ext !== 'pdf') {
                    $message = "<div class='alert alert-danger'>الملف يجب أن يكون PDF فقط.</div>";
                } elseif ($_FILES['pdf_file']['size'] > 50000000) {
                    $message = "<div class='alert alert-danger'>حجم ملف PDF يجب أن يكون أقل من 50MB.</div>";
                } else {
                    $pdf_new_name    = uniqid('pdf_') . '.pdf';
                    $uploads_dir_pdf = '../assets/uploads/pdfs/';
                    if (move_uploaded_file($_FILES['pdf_file']['tmp_name'], $uploads_dir_pdf . $pdf_new_name)) {
                        if ($book['pdf_file'] && file_exists($uploads_dir_pdf . $book['pdf_file'])) {
                            unlink($uploads_dir_pdf . $book['pdf_file']);
                        }
                    } else {
                        $message = "<div class='alert alert-danger'>فشل رفع ملف PDF.</div>";
                    }
                }
            }

            if (empty($message)) {
                $stmt = $pdo->prepare("UPDATE books SET title=?, author=?, description=?, category_id=?, cover_image=?, pdf_file=? WHERE id=?");
                $stmt->execute([$title, $author, $description, $category_id, $cover_new_name, $pdf_new_name, $book_id]);

                consume_csrf_token(); 

                $message = "<div class='alert alert-success'>تم تعديل الكتاب بنجاح!</div>";
                $book['title']       = $title;
                $book['author']      = $author;
                $book['description'] = $description;
                $book['category_id'] = $category_id;
                $book['cover_image'] = $cover_new_name;
                $book['pdf_file']    = $pdf_new_name;
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
    <title>تعديل الكتاب: <?php echo htmlspecialchars($book['title']); ?></title>
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
                    <div class="card-header bg-warning text-dark">
                        <h4 class="mb-0">تعديل: <?php echo htmlspecialchars($book['title']); ?></h4>
                    </div>
                    <div class="card-body">
                        <?php echo $message; ?>
                        <div class="mb-3 text-center">
                            <?php if ($book['cover_image']): ?>
                                <img src="../assets/uploads/covers/<?php echo htmlspecialchars($book['cover_image']); ?>"
                                     style="max-width:150px; border-radius:8px;" alt="الغلاف الحالي">
                                <p class="text-muted mt-2">الغلاف الحالي</p>
                            <?php endif; ?>
                        </div>
                        <form method="POST" enctype="multipart/form-data">
                            <?php echo csrf_input(); ?>
                            <div class="mb-3">
                                <label class="form-label">عنوان الكتاب *</label>
                                <input type="text" name="title" class="form-control"
                                       value="<?php echo htmlspecialchars($book['title']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">اسم المؤلف *</label>
                                <input type="text" name="author" class="form-control"
                                       value="<?php echo htmlspecialchars($book['author']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">التصنيف *</label>
                                <select name="category_id" class="form-select" required>
                                    <?php foreach ($categories as $cat): ?>
<option value="<?php echo $cat['id']; ?>"
<?php echo $book['category_id'] == $cat['id'] ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($cat['name']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="mb-3">
<label class="form-label">وصف الكتاب</label>
<textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars($book['description'] ?? ''); ?></textarea>
</div>
<div class="mb-3">
<label class="form-label">صورة غلاف جديدة (اختياري) — حد أقصى 5MB</label>
<input type="file" name="cover_image" class="form-control" accept="image/*">
</div>
<div class="mb-3">
<label class="form-label">ملف PDF جديد (اختياري) — حد أقصى 50MB</label>
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