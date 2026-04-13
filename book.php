<?php
session_start();
require_once 'includes/db.php';

$book_id = $_GET['id'] ?? 0;
if ($book_id <= 0) {
    header("Location: index.php");
    exit();
}

$stmt = $pdo->prepare("
    SELECT b.*, c.name as category_name 
    FROM books b 
    LEFT JOIN categories c ON b.category_id = c.id 
    WHERE b.id = ?
");
$stmt->execute([$book_id]);
$book = $stmt->fetch();

if (!$book) {
    header("Location: index.php");
    exit();
}

// عداد المشاهدات بحماية Session
if (!isset($_SESSION['viewed_' . $book_id])) {
    $pdo->prepare("UPDATE books SET views = views + 1 WHERE id = ?")->execute([$book_id]);
    $_SESSION['viewed_' . $book_id] = true;
}

// جلب التعليقات
$stmt = $pdo->prepare("SELECT * FROM comments WHERE book_id = ? ORDER BY created_at DESC");
$stmt->execute([$book_id]);
$comments = $stmt->fetchAll();

// جلب تقييم المستخدم الحالي
$user_rating = 0;
if (isset($_SESSION['user_id'])) {
    $st = $pdo->prepare("SELECT rating FROM ratings WHERE user_id = ? AND book_id = ?");
    $st->execute([$_SESSION['user_id'], $book_id]);
    $res = $st->fetch();
    if ($res) $user_rating = $res['rating'];
}

// حالة المفضلة
$is_fav = false;
if (isset($_SESSION['user_id'])) {
    $stmt_fav = $pdo->prepare("SELECT id FROM favorites WHERE user_id = ? AND book_id = ?");
    $stmt_fav->execute([$_SESSION['user_id'], $book_id]);
    if ($stmt_fav->fetch()) $is_fav = true;
}

// إضافة تعليق
$message = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_SESSION['user_id'])) {
        $message = "<div class='alert alert-warning'>يجب تسجيل الدخول أولاً للتعليق.</div>";
    } else {
        $comment_text = trim($_POST['comment'] ?? '');
        $user_name    = $_SESSION['username'];
        $user_id      = $_SESSION['user_id'];

        if (empty($comment_text)) {
            $message = "<div class='alert alert-danger'>يرجى كتابة تعليق.</div>";
        } elseif (strlen($comment_text) < 5) {
            $message = "<div class='alert alert-danger'>التعليق قصير جداً.</div>";
        } else {
            $stmt = $pdo->prepare("INSERT INTO comments (book_id, user_id, user_name, comment, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$book_id, $user_id, $user_name, $comment_text]);
            header("Location: book.php?id=$book_id");
            exit();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($book['title']); ?> - مكتبة الثقافة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; background: #f8f9fa; }
        .book-cover { max-width: 100%; height: auto; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .comment-box { background: white; padding: 15px; border-radius: 10px; margin-bottom: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-right: 4px solid #667eea; }
        .star { font-size: 30px; cursor: pointer; color: #ccc; transition: color 0.2s; }
        .star.active, .star:hover { color: #ffc107; }
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
    <div class="row">
        <div class="col-md-4 text-center">
            <?php if ($book['cover_image']): ?>
                <img src="assets/uploads/covers/<?php echo htmlspecialchars($book['cover_image']); ?>" 
                     class="book-cover" alt="<?php echo htmlspecialchars($book['title']); ?>">
            <?php else: ?>
                <div class="bg-secondary text-white d-flex align-items-center justify-content-center" 
                     style="height:400px; border-radius:15px;">
                    <h3>لا يوجد غلاف</h3>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-md-8">
            <h1 class="mb-3"><?php echo htmlspecialchars($book['title']); ?></h1>
            <p class="lead text-muted">المؤلف: <?php echo htmlspecialchars($book['author']); ?></p>
            <p class="lead text-muted">التصنيف: <?php echo htmlspecialchars($book['category_name']); ?></p>
            <?php if ($book['description']): ?>
                <p class="text-muted"><?php echo nl2br(htmlspecialchars($book['description'])); ?></p>
            <?php endif; ?>
            <p class="text-muted">تاريخ الإضافة: <?php echo date('Y-m-d', strtotime($book['created_at'])); ?></p>

            <div class="my-4 d-flex flex-wrap gap-2">
                <a href="view.php?id=<?php echo $book['id']; ?>" class="btn btn-primary btn-lg">قراءة الكتاب</a>
                <a href="increment_downloads.php?id=<?php echo $book['id']; ?>" class="btn btn-success btn-lg" target="_blank">تحميل PDF</a>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="toggle_favorite.php?id=<?php echo $book_id; ?>" 
                       class="btn <?php echo $is_fav ? 'btn-danger' : 'btn-outline-danger'; ?> btn-lg">
                        <?php echo $is_fav ? '❤️ في المفضلة' : '🤍 أضف للمفضلة'; ?>
                    </a>
                <?php endif; ?>
            </div>

            <p class="text-muted">👁️ <?php echo $book['views']; ?> مشاهدة | ⬇️ <?php echo $book['downloads']; ?> تحميل</p>

            <!-- نظام التقييم -->
            <?php if (isset($_SESSION['user_id'])): ?>
                <div class="mb-3">
                    <h6 class="fw-bold">تقييمك لهذا الكتاب:</h6>
                    <div id="star-rating">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <span class="star <?php echo $i <= $user_rating ? 'active' : ''; ?>" 
                                  data-value="<?php echo $i; ?>">★</span>
                        <?php endfor; ?>
                    </div>
                    <small id="rating-msg" class="text-success" style="display:none;">تم حفظ تقييمك!</small>
                </div>
            <?php endif; ?>

            <!-- متوسط التقييم -->
            <div class="mb-3">
                <small class="text-muted">
                    متوسط التقييم: 
                    <?php 
                    $avg = round($book['avg_rating'] ?? 0);
                    for ($i = 1; $i <= 5; $i++) echo $i <= $avg ? '★' : '☆';
                    echo ' (' . number_format($book['avg_rating'] ?? 0, 1) . ')';
                    ?>
                </small>
            </div>
        </div>
    </div>

    <!-- قسم التعليقات -->
    <div class="row mt-5">
        <div class="col-12">
            <h3 class="mb-4">التعليقات (<?php echo count($comments); ?>)</h3>
            <?php echo $message; ?>

            <?php if (isset($_SESSION['user_id'])): ?>
                <form method="POST" class="mb-5 p-4 bg-light rounded shadow">
                    <div class="mb-3">
                        <label class="form-label">تعليقك</label>
                        <textarea name="comment" class="form-control" rows="4" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">أضف تعليقك</button>
                </form>
            <?php else: ?>
                <div class="alert alert-info mb-5">
                    <a href="admin/user_login.php">سجل الدخول</a> لإضافة تعليق.
                </div>
            <?php endif; ?>

            <?php if (count($comments) > 0): ?>
                <?php foreach ($comments as $comment): ?>
                    <div class="comment-box">
                        <strong><?php echo htmlspecialchars($comment['user_name']); ?></strong>
                        <small class="text-muted float-start"><?php echo date('Y-m-d H:i', strtotime($comment['created_at'])); ?></small>
                        <p class="mt-2"><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></p>
                        <?php 
                        $current_user_id = $_SESSION['user_id'] ?? 0;
                        $is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
                        if ($current_user_id == $comment['user_id'] || $is_admin): ?>
                            <a href="delete_comment.php?comment_id=<?php echo $comment['id']; ?>&book_id=<?php echo $book_id; ?>&from=book" 
                               class="btn btn-danger btn-sm mt-2"
                               onclick="return confirm('هل أنت متأكد؟');">
                                <?php echo $is_admin ? 'حذف' : 'حذف تعليقي'; ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-muted">لا توجد تعليقات بعد. كن أول من يعلق!</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.star').forEach(star => {
    star.onclick = function() {
        let rating = this.getAttribute('data-value');
        let bookId = <?php echo (int)$book_id; ?>;

        fetch('submit_rating.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `book_id=${bookId}&rating=${rating}`
        })
        .then(r => r.text())
        .then(data => {
            if (data === 'success') {
                document.querySelectorAll('.star').forEach(s => {
                    s.classList.remove('active');
                    if (s.getAttribute('data-value') <= rating) s.classList.add('active');
                });
                document.getElementById('rating-msg').style.display = 'block';
            }
        });
    };
});
</script>
</body>
</html>