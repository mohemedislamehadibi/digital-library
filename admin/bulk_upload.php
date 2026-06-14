<?php

set_time_limit(0);
ini_set('memory_limit', '256M');

session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

require_once '../includes/db.php';
require_once '../includes/BookProcessor.php';

// ── CSRF helpers ──────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die('طلب غير صالح — توكن CSRF خاطئ.');
    }
}
// ─────────────────────────────────────────────────────────────────────────────


// ══ CSV Mode ══════════════════════════════════════════════════════════════════

$csv_message = "";
$csv_log     = [];

if (isset($_POST["import_csv"])) {
    verifyCsrf();   

    $ext = strtolower(pathinfo($_FILES["csv_file"]["name"], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        $csv_message = "<div class='alert alert-danger'>يجب أن يكون الملف بصيغة CSV!</div>";
    } elseif ($_FILES["csv_file"]["size"] <= 0) {
        $csv_message = "<div class='alert alert-danger'>الملف فارغ!</div>";
    } else {
        $file      = fopen($_FILES["csv_file"]["tmp_name"], "r");
        fgetcsv($file);
        $count     = 0;
        $errors    = 0;
        $processor = new BookProcessor($pdo);

        try {
            while (($col = fgetcsv($file, 1000, ",")) !== false) {
                if (count($col) < 2) { $errors++; continue; }
                $title   = trim($col[0]);
                $pdf_url = trim($col[1]);
                if (empty($title) || empty($pdf_url)) { $errors++; continue; }

                $chk = $pdo->prepare("SELECT id FROM books WHERE title = ? LIMIT 1");
                $chk->execute([$title]);
                if ($chk->fetch()) {
                    $csv_log[] = "⚠️ <b>" . htmlspecialchars($title) . "</b> — موجود مسبقاً";
                    $errors++;
                    continue;
                }

                $book_data   = $processor->getBookDataWithCache($title);
                $author      = 'غير معروف';
                $description = '';
                $cover       = 'default_book.jpg';

                if ($book_data) {
                    $author      = $book_data['author']      ?? 'غير معروف';
                    $description = $book_data['description'] ?? '';
                    $cover       = $processor->saveCover($book_data['cover'] ?? null, $title);

                    $src      = $book_data['api_source'] ?? 'api';
                    $has_desc = !empty($book_data['description']) ? '📝 وصف موجود' : '⚠️ بدون وصف';
                    $csv_log[] = "✅ <b>" . htmlspecialchars($title) . "</b> — $src | $has_desc | المؤلف: " . htmlspecialchars($author);
                } else {
                    $csv_log[] = "⚠️ <b>" . htmlspecialchars($title) . "</b> — لم يُعثر على بيانات API (يُصنَّف بالعنوان)";
                }

                $cat_text = ($book_data['category'] ?? '')
                          . ' ' . $description
                          . ' ' . $title;

               $cat_id = $processor->smartClassify($title, $description);

               
                $stmt = $pdo->prepare("INSERT INTO books (title,author,description,category_id,pdf_file,cover_image,created_at,downloads,views) VALUES (?,?,?,?,?,?,NOW(),0,0)");
                $stmt->execute([
                    $title,
                    $author,
                    $description,
                    $cat_id,
                    $pdf_url,
                    $cover,
                ]);
                $count++;
            }
            $csv_message = "<div class='alert alert-success'>✅ تم إضافة <b>$count</b> كتاب"
                         . ($errors > 0 ? " — تخطي <b>$errors</b>" : "")
                         . " — <a href='classification_stats.php'>عرض الإحصائيات</a></div>";
        } catch (Exception $e) {
            error_log('[bulk_upload CSV] ' . $e->getMessage());   // #3
            $csv_message = "<div class='alert alert-danger'>❌ عذرًا، حدث خطأ أثناء الاستيراد. يرجى المحاولة مجدداً.</div>";
        } finally {
            fclose($file);
        }
    }
}


// ══ PDF Batch Mode ════════════════════════════════════════════════════════════

$pdf_message = "";
$pdf_log     = [];

if (isset($_POST["import_pdfs"])) {
    verifyCsrf();   // #1

    $files  = $_FILES["pdf_files"];
    $count  = 0;
    $errors = 0;

    if (empty($files["name"][0])) {
        $pdf_message = "<div class='alert alert-danger'>يرجى اختيار ملفات PDF!</div>";
    } else {
        $dir_pdf   = '../assets/uploads/pdfs/';
        $dir_cover = '../assets/uploads/covers/';
        if (!is_dir($dir_pdf))   mkdir($dir_pdf,   0755, true);
        if (!is_dir($dir_cover)) mkdir($dir_cover, 0755, true);

       
        $processor = new BookProcessor($pdo);

        for ($i = 0; $i < count($files["name"]); $i++) {
            $orig_name = $files["name"][$i];
            $tmp_name  = $files["tmp_name"][$i];
            $file_size = $files["size"][$i];
            $file_ext  = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

            if ($file_ext !== 'pdf' || $file_size > 50000000) {
                $errors++;
                $pdf_log[] = "❌ <b>" . htmlspecialchars($orig_name) . "</b> — نوع أو حجم غير صحيح";
                continue;
            }

            $title = str_replace(['_', '-'], ' ', pathinfo($orig_name, PATHINFO_FILENAME));
            $title = trim($title);

            $chk = $pdo->prepare("SELECT id FROM books WHERE title = ? LIMIT 1");
            $chk->execute([$title]);
            if ($chk->fetch()) {
                $pdf_log[] = "⚠️ <b>" . htmlspecialchars($title) . "</b> — موجود مسبقاً";
                $errors++;
                continue;
            }

            $pdf_new_name  = uniqid('pdf_') . '.pdf';
            $pdf_full_path = $dir_pdf . $pdf_new_name;

            if (!move_uploaded_file($tmp_name, $pdf_full_path)) {
                $errors++;
                $pdf_log[] = "❌ <b>" . htmlspecialchars($title) . "</b> — فشل رفع الملف";
                continue;
            }

            $book_data = $processor->getBookDataWithCache($title);

            $author      = 'غير معروف';
            $description = '';
            $cover       = 'default_book.jpg';

            if ($book_data) {
                $author      = $book_data['author']      ?? 'غير معروف';
                $description = $book_data['description'] ?? '';
                $cover       = $processor->saveCover($book_data['cover'] ?? null, $title);
                $pdf_log[]   = "✅ <b>" . htmlspecialchars($title) . "</b> — API (المؤلف: " . htmlspecialchars($author) . ")";
            } else {
                $pdf_log[] = "⚠️ <b>" . htmlspecialchars($title) . "</b> — لا بيانات API";
            }

            
            $cat_id = $processor->smartClassify($title, $description, $pdf_full_path);

            try {
                
                $stmt = $pdo->prepare("INSERT INTO books (title,author,description,category_id,pdf_file,cover_image,created_at,downloads,views) VALUES (?,?,?,?,?,?,NOW(),0,0)");
                $stmt->execute([
                    $title,
                    $author,
                    $description,
                    $cat_id,
                    $pdf_new_name,
                    $cover,
                ]);
                $count++;
            } catch (Exception $e) {
                error_log('[bulk_upload PDF] ' . $e->getMessage());   // #3
                $pdf_log[] = "❌ <b>" . htmlspecialchars($title) . "</b> — خطأ أثناء الحفظ في قاعدة البيانات";
                $errors++;
            }
        }

        $pdf_message = "<div class='alert alert-success'>✅ تم إضافة <b>$count</b> كتاب"
                     . ($errors > 0 ? " — فشل <b>$errors</b>" : "")
                     . " — <a href='classification_stats.php'>عرض الإحصائيات</a></div>";
    }
}


// ══ Textarea Queue Mode ═══════════════════════════════════════════════════════

$textarea_message = "";

if (isset($_POST["import_textarea"])) {
    verifyCsrf();   // #1

    $content = trim($_POST["textarea_input"] ?? '');
    if (empty($content)) {
        $textarea_message = "<div class='alert alert-danger'>❌ الحقل فارغ!</div>";
    } else {
        $lines  = explode("\n", $content);
        $added  = 0;
        $errors = 0;
        try {
            $stmt = $pdo->prepare("INSERT INTO import_queue (title,pdf_url,import_type,status) VALUES (?,?,'textarea','pending')");
            foreach ($lines as $line) {
                $line  = trim($line);
                if (empty($line)) continue;
                $parts = explode('|', $line);
                if (count($parts) < 2) { $errors++; continue; }
                $title   = trim($parts[0]);
                $pdf_url = trim($parts[1]);
                if (empty($title) || empty($pdf_url)) { $errors++; continue; }

                $chk = $pdo->prepare("SELECT id FROM books WHERE title = ? LIMIT 1");
                $chk->execute([$title]);
                if ($chk->fetch()) { $errors++; continue; }

                $stmt->execute([$title, $pdf_url]);
                $added++;
            }
            if ($added > 0) {
                $worker_path = __DIR__ . '/worker.php';
                $php_exe     = PHP_BINARY ?: 'php';

               
                @pclose(popen(
                    "start /B \"\" " . escapeshellarg($php_exe) . " " . escapeshellarg($worker_path) . " > nul 2>&1",
                    "r"
                ));

                $textarea_message = "<div class='alert alert-info'>✅ تمت إضافة <b>$added</b> كتاب إلى الطابور!</div>";
            } else {
                $textarea_message = "<div class='alert alert-warning'>⚠️ لم يُضَف أي كتاب" . ($errors > 0 ? " ($errors تخطي)" : "") . "</div>";
            }
        } catch (Exception $e) {
            error_log('[bulk_upload textarea] ' . $e->getMessage());   // #3
            $textarea_message = "<div class='alert alert-danger'>❌ عذرًا، حدث خطأ أثناء إضافة الطابور. يرجى المحاولة مجدداً.</div>";
        }
    }
}

// حالة الطابور
try {
    $queue_status = $pdo->query("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status='pending'    THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status='processing' THEN 1 ELSE 0 END) as processing,
            SUM(CASE WHEN status='done'       THEN 1 ELSE 0 END) as done,
            SUM(CASE WHEN status='failed'     THEN 1 ELSE 0 END) as failed
        FROM import_queue
        WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
    ")->fetch();
} catch (Exception $e) {
    $queue_status = ['total'=>0,'pending'=>0,'processing'=>0,'done'=>0,'failed'=>0];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الرفع الجماعي الذكي — مكتبة الثقافة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <style>
        body          { background:#f4f7f6; font-family:'Segoe UI',sans-serif; }
        .sidebar      { background:linear-gradient(135deg,#5d78ff,#4c5ce8); min-height:100vh; padding:20px;
                        color:#fff; position:fixed; right:0; top:0; width:220px; z-index:1000; }
        .sidebar a    { color:#fff; display:block; padding:12px; text-decoration:none;
                        border-radius:5px; margin-bottom:5px; transition:.3s; }
        .sidebar a:hover { background:rgba(255,255,255,.2); }
        .main-content { margin-right:220px; padding:40px; }
        .card         { border:none; border-radius:12px; transition:.3s; }
        .card:hover   { box-shadow:0 8px 25px rgba(0,0,0,.1); }
        .upload-zone  { border:2px dashed #5d78ff; border-radius:15px; background:#f8f9ff;
                        padding:40px; text-align:center; cursor:pointer; transition:.3s; }
        .upload-zone:hover { background:#eef0ff; }
        .log-box      { background:#1e1e2e; color:#cdd6f4; border-radius:10px; padding:20px;
                        max-height:300px; overflow-y:auto; font-family:monospace; font-size:.88em; }
        .section-title{ border-right:4px solid #5d78ff; padding-right:10px; font-weight:700; }
        .queue-badge  { display:inline-block; padding:6px 14px; margin:4px;
                        border-radius:20px; font-weight:700; font-size:.85em; }
        .btn-active   { background:#ff4b5c; color:#fff; border:none; font-weight:700; }
        .btn-active:hover { background:#e63946; color:#fff; }
        .badge-pdf    { background:#17a2b8; color:#fff; font-size:.75em;
                        padding:2px 6px; border-radius:4px; }
        .tip-box      { background:#e8f4fd; border-right:3px solid #3498db;
                        border-radius:6px; padding:10px 14px; font-size:.82rem; color:#2c3e50; }
    </style>
</head>
<body>

<div class="sidebar shadow">
    <h4 class="mb-4 text-center fw-bold">📚 مكتبة الثقافة</h4>
    <a href="dashboard.php"><i class="fas fa-home me-2"></i> لوحة التحكم</a>
    <a href="add_book.php"><i class="fas fa-plus me-2"></i> إضافة كتاب</a>
    <a href="bulk_upload.php" class="btn-active"><i class="fas fa-bolt me-2"></i> الرفع الذكي</a>
    <a href="classification_stats.php"><i class="fas fa-chart-bar me-2"></i> إحصائيات</a>
    <hr style="border-color:rgba(255,255,255,.15)">
    <a href="logout.php" class="text-warning"><i class="fas fa-sign-out-alt me-2"></i> خروج</a>
</div>

<div class="main-content">
<div class="container-fluid">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-0">🚀 الرفع الجماعي الذكي</h2>
            <small class="text-muted">
                Google Books + Open Library + تصنيف ذكي
                <span class="badge-pdf ms-1">★ العنوان يُستخدم دائماً في التصنيف</span>
            </small>
        </div>
        <span class="badge bg-primary p-2 fs-6">3 أوضاع</span>
    </div>

    <!-- نصيحة مهمة -->
    <div class="tip-box mb-4">
        <strong>💡 نصائح للتصنيف الأدق مع 100+ كتاب:</strong><br>
        • استخدم عناوين <b>إنجليزية</b> — Google Books يُرجع وصفاً أغنى بالإنجليزي<br>
        • إذا كان الكتاب عربياً اكتب عنوانه <b>بالعربي والإنجليزي معاً</b> في CSV: <code>Crime and Punishment,url</code><br>
        • الكتب التي تجد لها وصفاً ستُصنَّف بدقة أعلى من تلك التي لا وصف لها<br>
    </div>

    <!-- حالة الطابور -->
    <div class="card shadow-sm mb-4 p-3">
        <h6 class="mb-3">📊 حالة الطابور (آخر 7 أيام)</h6>
        <div>
            <span class="queue-badge" style="background:#3498db;color:#fff">إجمالي: <b><?= (int)($queue_status['total'] ?? 0) ?></b></span>
            <span class="queue-badge" style="background:#f39c12;color:#fff">معلق: <b><?= (int)($queue_status['pending'] ?? 0) ?></b></span>
            <span class="queue-badge" style="background:#e67e22;color:#fff">جاري: <b><?= (int)($queue_status['processing'] ?? 0) ?></b></span>
            <span class="queue-badge" style="background:#27ae60;color:#fff">مكتمل: <b><?= (int)($queue_status['done'] ?? 0) ?></b></span>
            <span class="queue-badge" style="background:#c0392b;color:#fff">فشل: <b><?= (int)($queue_status['failed'] ?? 0) ?></b></span>
        </div>
    </div>

    <!-- ══ CSV ══ -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light py-3">
            <h5 class="mb-0 section-title">
                <i class="fas fa-file-csv text-success me-2"></i>
                القسم الأول — رفع CSV (عنوان + رابط PDF)
            </h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info mb-3">
                <b>الصيغة:</b> ملف CSV بعمودين — <code>title</code> و <code>pdf_url</code><br>
                <small class="text-muted">✨ يجلب: مؤلف + وصف + غلاف + تصنيف تلقائي</small>
            </div>
            <?= $csv_message ?>
            <?php if (!empty($csv_log)): ?>
                <div class="log-box mb-3">
                    <?php foreach ($csv_log as $l): ?><div class="mb-1"><?= $l ?></div><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <div class="upload-zone mb-3">
                    <i class="fas fa-file-csv fa-3x text-success mb-3"></i>
                    <h5>اختر ملف CSV</h5>
                    <p class="text-muted small">العمود الأول: العنوان | العمود الثاني: رابط PDF</p>
                    <input type="file" name="csv_file" accept=".csv" class="form-control mt-3" required>
                </div>
                <button type="submit" name="import_csv" class="btn btn-success btn-lg w-100">
                    <i class="fas fa-magic me-2"></i> ابدأ المعالجة
                </button>
            </form>
        </div>
    </div>

    <!-- ══ PDF Batch ══ -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light py-3">
            <h5 class="mb-0 section-title">
                <i class="fas fa-file-pdf text-danger me-2"></i>
                القسم الثاني — رفع ملفات PDF من جهازك
                <span class="badge-pdf ms-2">★ تصنيف من نص PDF</span>
            </h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info mb-3">
                <b>كيفية الاستخدام:</b> اختر عدة ملفات PDF — اسم الملف = اسم الكتاب<br>
                <small class="text-muted">
                    ✨ النظام يستخرج نص الكتاب من PDF لتصنيف أدق — إذا فشل يستخدم وصف API ثم العنوان
                </small>
            </div>
            <?= $pdf_message ?>
            <?php if (!empty($pdf_log)): ?>
                <div class="log-box mb-3">
                    <?php foreach ($pdf_log as $l): ?><div class="mb-1"><?= $l ?></div><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <div class="upload-zone mb-3">
                    <i class="fas fa-file-pdf fa-3x text-danger mb-3"></i>
                    <h5>اختر ملفات PDF</h5>
                    <p class="text-muted small">حد أقصى 50MB لكل ملف — يمكن اختيار عدة ملفات</p>
                    <input type="file" name="pdf_files[]" accept=".pdf"
                           class="form-control mt-3" multiple required>
                </div>
                <button type="submit" name="import_pdfs" class="btn btn-danger btn-lg w-100">
                    <i class="fas fa-cloud-upload-alt me-2"></i> رفع ومعالجة
                </button>
            </form>
        </div>
    </div>

    <!-- ══ Textarea ══ -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light py-3">
            <h5 class="mb-0 section-title">
                <i class="fas fa-paste text-info me-2"></i>
                القسم الثالث — إدخال نصي سريع (طابور خلفي)
            </h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info mb-3">
                <b>الصيغة:</b> <code>عنوان الكتاب | رابط PDF</code> — سطر لكل كتاب<br>
                <b>مثال:</b><br>
                <code>Crime and Punishment | https://example.com/book.pdf</code><br>
                <code>Think and Grow Rich | https://example.com/book2.pdf</code><br>
                <small class="text-muted"> يُضاف إلى طابور ويُعالج في الخلفية بدون انتظار</small>
            </div>
            <?= $textarea_message ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <textarea name="textarea_input" class="form-control mb-3" rows="8"
                    placeholder="Crime and Punishment | https://...&#10;Think and Grow Rich | https://...&#10;1984 | https://..."></textarea>
                <button type="submit" name="import_textarea" class="btn btn-info btn-lg w-100 text-white">
                    <i class="fas fa-bolt me-2"></i> أضف إلى الطابور
                </button>
            </form>
        </div>
    </div>

</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>