<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

require_once '../includes/db.php';
require_once '../includes/csrf.php';

//  حذف كتاب واحد 
if (isset($_GET['delete_id']) && is_numeric($_GET['delete_id'])) {
    $id   = (int)$_GET['delete_id'];
    $stmt = $pdo->prepare("SELECT cover_image, pdf_file FROM books WHERE id = ?");
    $stmt->execute([$id]);
    $book = $stmt->fetch();
    if ($book) {
        $cover = '../assets/uploads/covers/' . $book['cover_image'];
        $pdf   = '../assets/uploads/pdfs/'   . $book['pdf_file'];
        if ($book['cover_image'] && file_exists($cover)) unlink($cover);
        if ($book['pdf_file']   && file_exists($pdf))   unlink($pdf);
       
        $pdo->prepare("UPDATE import_queue SET book_id = NULL WHERE book_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM books WHERE id = ?")->execute([$id]);
    }
    header("Location: dashboard.php?deleted=1");
    exit();
}

//  حذف الكل 
if (isset($_POST['delete_all'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        header("Location: dashboard.php?error=csrf");
        exit();
    }
    $all = $pdo->query("SELECT cover_image, pdf_file FROM books")->fetchAll();
    foreach ($all as $b) {
        $cover = '../assets/uploads/covers/' . $b['cover_image'];
        $pdf   = '../assets/uploads/pdfs/'   . $b['pdf_file'];
        if ($b['cover_image'] && file_exists($cover)) unlink($cover);
        if ($b['pdf_file']   && file_exists($pdf))   unlink($pdf);
    }
    
    $pdo->exec("UPDATE import_queue SET book_id = NULL WHERE book_id IS NOT NULL");
    $pdo->exec("DELETE FROM books");
    header("Location: dashboard.php?deleted_all=1");
    exit();
}

//  إحصائيات 
$total_books     = $pdo->query("SELECT COUNT(*) FROM books")->fetchColumn();
$total_users     = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total_comments  = $pdo->query("SELECT COUNT(*) FROM comments")->fetchColumn();
$most_downloaded = $pdo->query(
    "SELECT title, downloads FROM books ORDER BY downloads DESC LIMIT 1"
)->fetch();

//  بحث الكتب 
$search = trim($_GET['search'] ?? '');

if ($search !== '') {
    $stmt = $pdo->prepare("
        SELECT b.*, c.name as category_name
        FROM books b
        LEFT JOIN categories c ON b.category_id = c.id
        WHERE b.title LIKE ? OR b.author LIKE ?
        ORDER BY b.created_at DESC
    ");
    $like = "%$search%";
    $stmt->execute([$like, $like]);
} else {
    $stmt = $pdo->query("
        SELECT b.*, c.name as category_name
        FROM books b
        LEFT JOIN categories c ON b.category_id = c.id
        ORDER BY b.created_at DESC
    ");
}
$books = $stmt->fetchAll();

//  تعليقات 
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
    <title>لوحة التحكم — مكتبة الثقافة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body        { font-family: 'Cairo', sans-serif; background: #f8f9fa; }
        .navbar     { background: #667eea; }
        .stat-card  { border-radius: 12px; border: none; }
        .table th   { background: #667eea; color: white; }
        .card       { transition: .2s; }
        .card:hover { box-shadow: 0 8px 24px rgba(0,0,0,.12); }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="dashboard.php">مكتبة الثقافة — الإدارة</a>
        <div class="d-flex gap-2 flex-wrap">
            <a href="add_book.php"            class="btn btn-light btn-sm">إضافة كتاب</a>
            <a href="bulk_upload.php"         class="btn btn-light btn-sm">الرفع الجماعي</a>
            <a href="classification_stats.php" class="btn btn-warning btn-sm">📊 إحصائيات التصنيف</a>
            <a href="logout.php"              class="btn btn-danger btn-sm">خروج</a>
        </div>
    </div>
</nav>

<div class="container mt-4">

    <?php if (isset($_GET['deleted'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            ✅ تم حذف الكتاب بنجاح.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['deleted_all'])): ?>
        <div class="alert alert-warning alert-dismissible fade show">
            ⚠️ تم حذف جميع الكتب.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error']) && $_GET['error'] === 'csrf'): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            ❌ طلب غير صالح.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- بطاقات إحصائيات -->
    <div class="row g-3 mb-5">
        <div class="col-md-3">
            <div class="card stat-card shadow-sm p-3 text-center bg-primary text-white">
                <div class="fs-1">📚</div>
                <h3 class="fw-bold"><?php echo $total_books; ?></h3>
                <p class="mb-0">إجمالي الكتب</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card shadow-sm p-3 text-center bg-success text-white">
                <div class="fs-1">👥</div>
                <h3 class="fw-bold"><?php echo $total_users; ?></h3>
                <p class="mb-0">المستخدمين</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card shadow-sm p-3 text-center bg-warning text-dark">
                <div class="fs-1">💬</div>
                <h3 class="fw-bold"><?php echo $total_comments; ?></h3>
                <p class="mb-0">التعليقات</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card shadow-sm p-3 text-center bg-info text-white">
                <div class="fs-1">⬇️</div>
                <h3 class="fw-bold"><?php echo $most_downloaded['downloads'] ?? 0; ?></h3>
                <p class="mb-0 small"><?php echo htmlspecialchars($most_downloaded['title'] ?? '—'); ?></p>
            </div>
        </div>
    </div>

    <!-- شريط البحث + حذف الكل -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0">
            الكتب
            <span class="badge bg-secondary ms-1"><?php echo count($books); ?></span>
            <?php if ($search): ?>
                <small class="text-muted fs-6"> — نتائج: "<?php echo htmlspecialchars($search); ?>"</small>
            <?php endif; ?>
        </h4>

        <div class="d-flex gap-2 flex-wrap">
            <!-- بحث -->
            <form method="GET" class="d-flex gap-2">
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="ابحث بالعنوان أو المؤلف..."
                       value="<?php echo htmlspecialchars($search); ?>"
                       style="min-width: 230px;">
                <button type="submit" class="btn btn-primary btn-sm">بحث</button>
                <?php if ($search): ?>
                    <a href="dashboard.php" class="btn btn-secondary btn-sm">مسح</a>
                <?php endif; ?>
            </form>

            <!-- زر حذف الكل -->
            <button type="button" class="btn btn-danger btn-sm"
                    data-bs-toggle="modal" data-bs-target="#deleteAllModal"
                    <?php echo $total_books == 0 ? 'disabled' : ''; ?>>
                🗑️ حذف الكل (<?php echo $total_books; ?>)
            </button>
        </div>
    </div>

    <!-- جدول الكتب -->
    <?php if (count($books) > 0): ?>
    <div class="table-responsive mb-5">
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
                            <img src="../assets/uploads/covers/<?php echo htmlspecialchars($book['cover_image']); ?>"
                                 width="45" height="60" class="rounded" style="object-fit:cover;"
                                 alt="غلاف">
                        <?php else: ?>
                            <div class="bg-secondary text-white d-flex align-items-center justify-content-center rounded"
                                 style="width:45px;height:60px;font-size:10px;">لا غلاف</div>
                        <?php endif; ?>
                    </td>
                    <td><strong><?php echo htmlspecialchars($book['title']); ?></strong></td>
                    <td><?php echo htmlspecialchars($book['author']); ?></td>
                    <td>
                        <span class="badge bg-secondary">
                            <?php echo htmlspecialchars($book['category_name'] ?? '—'); ?>
                        </span>
                    </td>
                    <td><?php echo (int)$book['downloads']; ?></td>
                    <td><?php echo (int)$book['views']; ?></td>
                    <td><?php echo date('Y-m-d', strtotime($book['created_at'])); ?></td>
                    <td>
                        <a href="edit_book.php?id=<?php echo $book['id']; ?>"
                           class="btn btn-warning btn-sm">تعديل</a>
                        <a href="dashboard.php?delete_id=<?php echo $book['id']; ?>"
                           class="btn btn-danger btn-sm"
                           onclick="return confirm('هل أنت متأكد من حذف «<?php echo addslashes($book['title']); ?>»؟');">
                           حذف
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <div class="alert alert-info mb-5">
            <?php echo $search
                ? "🔍 لا توجد نتائج لـ \"" . htmlspecialchars($search) . "\""
                : "لا توجد كتب بعد."; ?>
        </div>
    <?php endif; ?>

    <!-- جدول التعليقات -->
    <h4 class="mb-3">إدارة التعليقات
        <span class="badge bg-secondary ms-1"><?php echo count($comments); ?></span>
    </h4>
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
                           onclick="return confirm('حذف هذا التعليق؟');">حذف</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <div class="alert alert-info text-center">لا توجد تعليقات حتى الآن.</div>
    <?php endif; ?>

</div><!-- /container -->

<!-- Modal تأكيد حذف الكل -->
<div class="modal fade" id="deleteAllModal" tabindex="-1" aria-labelledby="deleteAllLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteAllLabel">⚠️ تأكيد حذف جميع الكتب</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>هذا الإجراء <strong>لا يمكن التراجع عنه</strong>.</p>
                <p>سيتم حذف <strong class="text-danger"><?php echo $total_books; ?> كتاب</strong>
                   مع جميع ملفاتهم (PDF + الأغلفة) من السيرفر نهائياً.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <form method="POST" class="d-inline">
                    <?php echo csrf_input(); ?>
                    <button type="submit" name="delete_all" class="btn btn-danger">
                        نعم، احذف جميع الكتب
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>