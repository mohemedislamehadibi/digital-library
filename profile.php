<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/csrf.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: admin/user_login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT username, email, created_at FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header("Location: index.php");
    exit();
}

$stmt_favs = $pdo->prepare("
    SELECT b.id, b.title, b.cover_image, b.author
    FROM favorites f
    JOIN books b ON f.book_id = b.id
    WHERE f.user_id = ?
    ORDER BY f.created_at DESC
");
$stmt_favs->execute([$user_id]);
$favorites = $stmt_favs->fetchAll();

$stmt_comments = $pdo->prepare("
    SELECT c.comment, c.created_at, b.id AS book_id, b.title AS book_title, r.rating
    FROM comments c
    JOIN books b ON c.book_id = b.id
    LEFT JOIN ratings r ON (r.book_id = b.id AND r.user_id = c.user_id)
    WHERE c.user_id = ?
    ORDER BY c.created_at DESC
");
$stmt_comments->execute([$user_id]);
$comments = $stmt_comments->fetchAll();

$ratings_count = $pdo->prepare("SELECT COUNT(*) FROM ratings WHERE user_id = ?");
$ratings_count->execute([$user_id]);
$ratings_count = $ratings_count->fetchColumn();

$status_message = "";
if (isset($_GET['status']) && $_GET['status'] == 'updated') {
    $status_message = "<div class='alert alert-success alert-dismissible fade show'>تم تحديث بياناتك بنجاح! <button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
} elseif (isset($_GET['error'])) {
    $errors = [
        'exists'           => 'اسم المستخدم أو البريد الإلكتروني مستخدم بالفعل!',
        'empty'            => 'يرجى ملء جميع الحقول!',
        'short_password'   => 'كلمة المرور يجب أن تكون 6 أحرف على الأقل!',
        'invalid_email'    => 'صيغة البريد الإلكتروني غير صحيحة!',
        'invalid_request'  => 'طلب غير صالح، حاول مجدداً!',
    ];
    $msg = $errors[$_GET['error']] ?? 'حدث خطأ غير معروف!';
    $status_message = "<div class='alert alert-danger alert-dismissible fade show'>$msg <button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بروفايلي - <?php echo htmlspecialchars($user['username']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; background-color: #f8f9fa; }
        .user-avatar { width: 80px; height: 80px; font-size: 30px; display: flex; align-items: center; justify-content: center; margin: 0 auto; }
        .nav-pills .nav-link.active { background-color: #0d6efd; }
        .card { border-radius: 12px; transition: transform 0.2s; }
        .book-card:hover { transform: translateY(-5px); }
        .stat-card h3 { color: #0d6efd; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm mb-4">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">مكتبة الثقافة</a>
        <div class="ms-auto d-flex align-items-center">
            <a href="index.php" class="btn btn-outline-light btn-sm me-2">الرئيسية</a>
            <a href="logout.php" class="btn btn-danger btn-sm">خروج</a>
        </div>
    </div>
</nav>

<div class="container">
    <div class="row g-3 mb-4 text-center">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3 stat-card">
                <h3 class="fw-bold mb-0"><?php echo count($favorites); ?></h3>
                <small class="text-muted">المفضلة</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3 stat-card">
                <h3 class="fw-bold mb-0 text-success"><?php echo count($comments); ?></h3>
                <small class="text-muted">تعليقات</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3 stat-card">
                <h3 class="fw-bold mb-0 text-warning"><?php echo $ratings_count; ?></h3>
                <small class="text-muted">تقييمات</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3 stat-card">
                <h3 class="fw-bold mb-0 text-info">
                    <?php
                    $days = round((time() - strtotime($user['created_at'])) / (60 * 60 * 24));
                    echo $days <= 0 ? 1 : $days;
                    ?>
                </h3>
                <small class="text-muted">أيام معنا</small>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4 mb-4">
            <div class="card border-0 shadow-sm p-4 text-center">
                <div class="user-avatar bg-primary text-white rounded-circle mb-3 shadow-sm">
                    <?php echo mb_substr(htmlspecialchars($user['username']), 0, 1, 'UTF-8'); ?>
                </div>
                <h4 class="fw-bold mb-1"><?php echo htmlspecialchars($user['username']); ?></h4>
                <p class="text-muted small mb-3"><?php echo htmlspecialchars($user['email']); ?></p>
                <hr>
                <p class="text-muted small mb-0">تاريخ الانضمام: <?php echo date('Y-m-d', strtotime($user['created_at'])); ?></p>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card border-0 shadow-sm p-4 h-100">
                <ul class="nav nav-pills mb-4 bg-light p-2 rounded justify-content-center" id="pills-tab" role="tablist">
                    <li class="nav-item flex-fill">
                        <button class="nav-link active w-100 fw-bold" data-bs-toggle="pill" data-bs-target="#fav-content" type="button">❤️ المفضلة</button>
                    </li>
                    <li class="nav-item flex-fill">
                        <button class="nav-link w-100 fw-bold" data-bs-toggle="pill" data-bs-target="#comm-content" type="button">💬 نشاطاتي</button>
                    </li>
                    <li class="nav-item flex-fill">
                        <button class="nav-link w-100 fw-bold" data-bs-toggle="pill" data-bs-target="#settings-content" type="button">⚙️ الإعدادات</button>
                    </li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade show active" id="fav-content">
                        <?php if (count($favorites) > 0): ?>
                            <div class="row row-cols-2 row-cols-lg-3 g-3">
                                <?php foreach ($favorites as $f_book): ?>
                                    <div class="col">
                                        <div class="card h-100 border-0 shadow-sm book-card overflow-hidden">
                                            <?php
                                            $cover_src = filter_var($f_book['cover_image'], FILTER_VALIDATE_URL)
                                                ? $f_book['cover_image']
                                                : 'assets/uploads/covers/' . htmlspecialchars($f_book['cover_image']);
                                            ?>
                                            <img src="<?php echo $cover_src; ?>" class="card-img-top" style="height:140px; object-fit:cover;" alt="غلاف">
                                            <div class="card-body p-2 text-center">
                                                <h6 class="card-title small fw-bold mb-2 text-truncate"><?php echo htmlspecialchars($f_book['title']); ?></h6>
                                                <a href="book.php?id=<?php echo $f_book['id']; ?>" class="btn btn-primary btn-sm w-100">عرض</a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5 text-muted">لم تضف أي كتاب للمفضلة بعد.</div>
                        <?php endif; ?>
                    </div>

                    <div class="tab-pane fade" id="comm-content">
                        <?php if (count($comments) > 0): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($comments as $comment): ?>
                                    <div class="list-group-item px-0 py-3 border-bottom border-light">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <a href="book.php?id=<?php echo $comment['book_id']; ?>" class="text-decoration-none fw-bold text-primary">
                                                <?php echo htmlspecialchars($comment['book_title']); ?>
                                            </a>
                                            <div class="text-warning small">
                                                <?php for ($i = 1; $i <= 5; $i++) echo ($i <= $comment['rating']) ? '★' : '☆'; ?>
                                            </div>
                                        </div>
                                        <p class="mb-0 text-dark small bg-white p-2 rounded"><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></p>
                                        <small class="text-muted"><?php echo date('Y-m-d H:i', strtotime($comment['created_at'])); ?></small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5 text-muted">لم تشارك بأي تعليق حتى الآن.</div>
                        <?php endif; ?>
                    </div>

                    <div class="tab-pane fade" id="settings-content">
                        <?php echo $status_message; ?>
                        <form action="update_profile.php" method="POST">
                            <?php echo csrf_input(); ?>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">اسم المستخدم</label>
                                <input type="text" name="username" class="form-control form-control-sm"
                                       value="<?php echo htmlspecialchars($user['username']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">البريد الإلكتروني</label>
                                <input type="email" name="email" class="form-control form-control-sm"
                                       value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-danger">كلمة المرور الجديدة (اتركها فارغة لعدم التغيير)</label>
                                <input type="password" name="new_password" class="form-control form-control-sm" placeholder="كلمة المرور الجديدة">
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">تأكيد كلمة المرور الجديدة</label>
                                <input type="password" name="confirm_password" class="form-control form-control-sm" placeholder="تأكيد كلمة المرور">
                            </div>
                            <hr>
                            <button type="submit" class="btn btn-primary w-100 fw-bold">حفظ التغييرات</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>