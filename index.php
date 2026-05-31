<?php
session_start();
require_once 'includes/db.php';

$search          = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';


$query  = "SELECT b.*, c.name as category_name
           FROM books b
           LEFT JOIN categories c ON b.category_id = c.id
           WHERE 1=1";
$params = [];

if ($search !== '') {
    $query .= " AND (b.title LIKE ? OR b.author LIKE ?)";
    $like   = "%$search%";
    $params[] = $like;
    $params[] = $like;
}

if ($category_filter !== '') {
    $query .= " AND b.category_id = ?";
    $params[] = $category_filter;
}

$query .= " ORDER BY b.created_at DESC";
$stmt   = $pdo->prepare($query);
$stmt->execute($params);
$books  = $stmt->fetchAll();

// جلب التصنيفات
$categories = $pdo->query("SELECT * FROM categories ORDER BY id")->fetchAll();
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مكتبة الثقافة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { font-family: 'Cairo', sans-serif; background: linear-gradient(135deg, #667eea, #764ba2); min-height: 100vh; }
        .card { transition: 0.3s; border: none; border-radius: 15px; overflow: hidden; }
        .card:hover { transform: translateY(-10px); box-shadow: 0 15px 30px rgba(0,0,0,0.3); }
        .card-img-top { height: 300px; object-fit: cover; }
        .search-section { background: rgba(255,255,255,0.9); border-radius: 15px; padding: 20px; }
        .star { color: #ffc107; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">مكتبة الثقافة</a>
        <div class="ms-auto d-flex align-items-center">
            <?php if (isset($_SESSION['user_id'])): ?>
                <span class="text-white me-3">أهلاً، <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <a href="profile.php" class="btn btn-outline-light btn-sm me-2">حسابي</a>
                <a href="logout.php" class="btn btn-danger btn-sm">خروج</a>
            <?php elseif (isset($_SESSION['admin_logged_in'])): ?>
                <a href="admin/dashboard.php" class="btn btn-warning btn-sm me-2">لوحة التحكم</a>
                <a href="admin/logout.php" class="btn btn-danger btn-sm">خروج الأدمن</a>
            <?php else: ?>
                <a href="admin/user_login.php" class="btn btn-outline-light btn-sm me-2">تسجيل الدخول</a>
                <a href="admin/register.php" class="btn btn-light btn-sm text-primary">إنشاء حساب</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="container my-5">
    <!-- قسم البحث -->
    <div class="search-section shadow-lg mb-5">
        <form class="row g-3">
            <div class="col-md-6">
                <input type="text" name="search" class="form-control form-control-lg"
                       placeholder="ابحث بالعنوان أو المؤلف..."
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-4">
                <select name="category" class="form-select form-select-lg">
                    <option value="">جميع التصنيفات</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>"
                            <?php echo $category_filter == $cat['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-lg w-100">بحث</button>
            </div>
        </form>
        <?php if ($search !== '' || $category_filter !== ''): ?>
            <a href="index.php" class="btn btn-secondary mt-3">مسح الفلاتر</a>
        <?php endif; ?>
    </div>

    <!-- عرض الكتب -->
    <?php if (count($books) > 0): ?>
        <div class="row row-cols-1 row-cols-md-3 row-cols-lg-4 g-4">
            <?php foreach ($books as $book): ?>
                <div class="col">
                    <div class="card h-100 shadow">
                        <?php if ($book['cover_image']): ?>
                            <img src="assets/uploads/covers/<?php echo htmlspecialchars($book['cover_image']); ?>"
                                 class="card-img-top"
                                 alt="<?php echo htmlspecialchars($book['title']); ?>">
                        <?php else: ?>
                            <div class="bg-secondary d-flex align-items-center justify-content-center text-white" style="height:300px;">
                                <h4>لا يوجد غلاف</h4>
                            </div>
                        <?php endif; ?>
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title"><?php echo htmlspecialchars($book['title']); ?></h5>
                            <p class="card-text text-muted small">المؤلف: <?php echo htmlspecialchars($book['author']); ?></p>
                            <p class="card-text text-muted small">التصنيف: <?php echo htmlspecialchars($book['category_name']); ?></p>
                            <!-- النجوم — يستخدم avg_rating المخزون مباشرة ✅ -->
                            <div class="mb-2">
                                <?php
                                $avg = round($book['avg_rating'] ?? 0);
                                for ($i = 1; $i <= 5; $i++):
                                ?>
                                    <span class="star"><?php echo $i <= $avg ? '★' : '☆'; ?></span>
                                <?php endfor; ?>
                                <small class="text-muted">(<?php echo number_format($book['avg_rating'] ?? 0, 1); ?>)</small>
                            </div>
                            <div class="mt-auto">
                                <a href="book.php?id=<?php echo $book['id']; ?>" class="btn btn-primary w-100 mb-2">عرض التفاصيل</a>
                                <a href="view.php?id=<?php echo $book['id']; ?>" class="btn btn-outline-primary w-100 mb-2">قراءة</a>
                                <a href="increment_downloads.php?id=<?php echo $book['id']; ?>" class="btn btn-success text-white w-100" target="_blank">تحميل PDF</a>
                            </div>
                            <div class="text-center mt-2">
                                <small class="text-muted">👁️ <?php echo $book['views']; ?> | ⬇️ <?php echo $book['downloads']; ?></small>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-info text-center fs-4">لا توجد كتب متاحة حالياً.</div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
