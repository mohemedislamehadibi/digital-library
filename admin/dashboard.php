<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

require_once '../includes/db.php';

// إحصائيات
$total_books    = $pdo->query("SELECT COUNT(*) FROM books")->fetchColumn();
$total_users    = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total_comments = $pdo->query("SELECT COUNT(*) FROM comments")->fetchColumn();
$most_downloaded = $pdo->query("SELECT title, downloads FROM books ORDER BY downloads DESC LIMIT 1")->fetch();

// جلب الكتب مع اسم التصنيف
$books = $pdo->query("
    SELECT b.*, c.name as category_name 
    FROM books b 
    LEFT JOIN categories c ON b.category_id = c.id 
    ORDER BY b.created_at DESC
")->fetchAll();

// جلب التعليقات
$comments = $pdo->query("
    SELECT c.*, b.title 
    FROM comments c 
    JOIN books b ON c.book_id = b.id 
    ORDER BY c.created_at DESC
")->fetchAll();
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
        .card:hover { box-shadow: 0 8px 25px rgba(0,0,0,0.15); }
        .table th { background: #667eea; color: white; }
        .stat-card { border-radius: 12px; border: none; }
        .stat-icon { font-size: 2.5rem; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">مكتبة الثقافة - الإدارة</a>
            <div>
                <a href="add_book.php" class="btn btn-light me-2">إضافة كتاب</a>
                <a href="bulk_upload.php" class="btn btn-light me-2">الرفع الجماعي</a>
                <a href="logout.php" class="btn btn-danger">تسجيل الخروج</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">

        <!-- بطاقات الإحصائيات -->
        <div class="row g-3 mb-5">
            <div class="col-md-3">
                <div class="card stat-card shadow-sm p-3 text-center bg-primary text-white">
                    <div class="stat-icon">📚</div>
                    <h3 class="fw-bold"><?php echo $total_books; ?></h3>
                    <p class="mb-0">إجمالي الكتب</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card shadow-sm p-3 text-center bg-success text-white">
                    <div class="stat-icon">👥</div>
                    <h3 class="fw-bold"><?php echo $total_users; ?></h3>
                    <p class="mb-0">المستخدمين</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card shadow-sm p-3 text-center bg-warning text-dark">
                    <div class="stat-icon">💬</div>
                    <h3 class="fw-bold"><?php echo $total_comments; ?></h3>
                    <p class="mb-0">التعليقات</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card shadow-sm p-3 text-center bg-info text-white">
                    <div class="stat-icon">⬇️</div>
                    <h3 class="fw-bold"><?php echo $most_downloaded['downloads'] ?? 0; ?></h3>
                    <p class="mb-0">أكثر تحميلاً: <?php echo htmlspecialchars($most_downloaded['title'] ?? '-'); ?></p>
                </div>
            </div>
        </div>

        <!-- جدول الكتب -->
        <h4 class="mb-3">الكتب</h4>
        <?php if (count($books) > 0): ?>
            <div class="table-responsive mb-5">
                <table class="table table-striped table-hover">
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
                                        <img src="../assets/uploads/covers/<?php echo htmlspecialchars($book['cover_image']); ?>" 
                                             width="50" height="70" class="rounded">
                                    <?php else: ?>
                                        <div class="bg-secondary text-white d-flex align-items-center justify-content-center" 
                                             style="width:50px;height:70px;">لا غلاف</div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($book['title']); ?></td>
                                <td><?php echo htmlspecialchars($book['author']); ?></td>
                                <td><?php echo htmlspecialchars($book['category_name']); ?></td>
                                <td><?php echo $book['downloads']; ?></td>
                                <td><?php echo $book['views']; ?></td>
                                <td><?php echo date('Y-m-d', strtotime($book['created_at'])); ?></td>
                                <td>
                                    <a href="edit_book.php?id=<?php echo $book['id']; ?>" 
                                       class="btn btn-warning btn-sm">تعديل</a>
                                    <a href="delete_book.php?id=<?php echo $book['id']; ?>" 
                                       class="btn btn-danger btn-sm"
                                       onclick="return confirm('هل أنت متأكد من حذف هذا الكتاب؟');">حذف</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info">لا توجد كتب بعد.</div>
        <?php endif; ?>

        <!-- جدول التعليقات -->
        <h4 class="mb-3">إدارة التعليقات</h4>
        <?php if (count($comments) > 0): ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead>
                        <tr>
                            <th>الكتاب</th>
                            <th>اسم المستخدم</th>
                            <th>التعليق</th>
                            <th>التاريخ</th>
                            <th>إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($comments as $c): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($c['title']); ?></td>
                                <td><strong><?php echo htmlspecialchars($c['user_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($c['comment']); ?></td>
                                <td><?php echo date('Y-m-d H:i', strtotime($c['created_at'])); ?></td>
                                <td>
                                    <a href="../delete_comment.php?comment_id=<?php echo $c['id']; ?>&book_id=<?php echo $c['book_id']; ?>&from=dashboard" 
                                       class="btn btn-danger btn-sm"
                                       onclick="return confirm('هل أنت متأكد من حذف هذا التعليق؟');">حذف</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info text-center">لا توجد تعليقات حتى الآن.</div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>