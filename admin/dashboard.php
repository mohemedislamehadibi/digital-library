<?php
session_start();


if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}


require_once '../includes/db.php';


$stmt = $pdo->query("SELECT * FROM books ORDER BY created_at DESC");
$books = $stmt->fetchAll(PDO::FETCH_ASSOC);


$search = $_GET['search'] ?? '';
if ($search !== '') {
    $stmt = $pdo->prepare("SELECT * FROM books WHERE title LIKE ? OR author LIKE ? OR category LIKE ? ORDER BY created_at DESC");
    $like = "%$search%";
    $stmt->execute([$like, $like, $like]);
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة تحكم الإدارة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { font-family: 'Cairo', sans-serif; background: #f8f9fa; }
        .navbar { background: #667eea; }
        .card { transition: 0.3s; }
        .card:hover { box-shadow: 0 8px 25px rgba(0,0,0,0.2); }
        .table th { background: #667eea; color: white; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">مكتبة الثقافة - الإدارة</a>
            <div>
                <a href="add_book.php" class="btn btn-light me-2">إضافة كتاب جديد</a>
                <a href="logout.php" class="btn btn-outline-light">تسجيل الخروج</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <h2 class="mb-4">لوحة التحكم</h2>

       
        <form class="mb-4">
            <div class="input-group">
                <input type="text" name="search" class="form-control" placeholder="ابحث بالعنوان أو المؤلف أو التصنيف..." value="<?php echo htmlspecialchars($search); ?>">
                <button class="btn btn-primary" type="submit">بحث</button>
                <?php if ($search !== ''): ?>
                    <a href="dashboard.php" class="btn btn-secondary">مسح البحث</a>
                <?php endif; ?>
            </div>
        </form>

       
        <?php if (count($books) > 0): ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead>
                        <tr>
                            <th>الغلاف</th>
                            <th>العنوان</th>
                            <th>المؤلف</th>
                            <th>التصنيف</th>
                            <th>التحميلات</th>
                            <th>المشاهدات</th>
                            <th>تاريخ الإضافة</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($books as $book): ?>
                            <tr>
                                <td>
                                    <?php if ($book['cover_image']): ?>
                                        <img src="../assets/uploads/covers/<?php echo htmlspecialchars($book['cover_image']); ?>" width="50" height="70" class="rounded">
                                    <?php else: ?>
                                        <div class="bg-secondary text-white d-flex align-items-center justify-content-center" style="width:50px;height:70px;">لا غلاف</div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($book['title']); ?></td>
                                <td><?php echo htmlspecialchars($book['author']); ?></td>
                                <td><?php echo htmlspecialchars($book['category']); ?></td>
                                <td><?php echo $book['downloads']; ?></td>
                                <td><?php echo $book['views']; ?></td>
                                <td><?php echo date('Y-m-d', strtotime($book['created_at'])); ?></td>
                                <td>
                                    <a href="edit_book.php?id=<?php echo $book['id']; ?>" class="btn btn-warning btn-sm">تعديل</a>
                                    <a href="delete_book.php?id=<?php echo $book['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('هل أنت متأكد من حذف هذا الكتاب؟');">حذف</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info text-center">لا توجد كتب حالياً. <a href="add_book.php">أضف أول كتاب الآن</a></div>
        <?php endif; ?>
    </div>
</body>
</html>