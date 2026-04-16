<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

require_once '../includes/db.php';
require_once '../includes/BookProcessor.php';

$processor = new BookProcessor($pdo);

// ============================================
// القسم الأول — CSV
// ============================================
$csv_message = "";
$csv_log     = [];

if (isset($_POST["import_csv"])) {
    $ext = strtolower(pathinfo($_FILES["csv_file"]["name"], PATHINFO_EXTENSION));

    if ($ext !== 'csv') {
        $csv_message = "<div class='alert alert-danger'>يجب أن يكون الملف بصيغة CSV فقط!</div>";
    } elseif ($_FILES["csv_file"]["size"] <= 0) {
        $csv_message = "<div class='alert alert-danger'>الملف فارغ!</div>";
    } else {
        $file   = fopen($_FILES["csv_file"]["tmp_name"], "r");
        fgetcsv($file);
        $count  = 0;
        $errors = 0;

        try {
            while (($column = fgetcsv($file, 2000, ",")) !== FALSE) {
                if (count($column) < 2) { $errors++; continue; }

                $title   = trim($column[0]);
                $pdf_url = trim($column[1]);

                if (empty($title) || empty($pdf_url)) { $errors++; continue; }

                $result = $processor->processBook($title, $pdf_url);
                $csv_log[] = $result['msg'];

                if ($result['status'] === 'success') $count++;
                else $errors++;
            }

            $csv_message = "<div class='alert alert-success'>✅ تمت العملية! تم إضافة <b>$count</b> كتاب" .
                           ($errors > 0 ? " — تم تخطي <b>$errors</b>" : "") . "</div>";
        } catch (Exception $e) {
            error_log($e->getMessage());
            $csv_message = "<div class='alert alert-danger'>❌ حدث خطأ أثناء المعالجة.</div>";
        } finally {
            fclose($file);
        }
    }
}

// ============================================
// القسم الثاني — PDF جماعي
// ============================================
$pdf_message = "";
$pdf_log     = [];

if (isset($_POST["import_pdfs"])) {
    $files  = $_FILES["pdf_files"];
    $count  = 0;
    $errors = 0;

    if (empty($files["name"][0])) {
        $pdf_message = "<div class='alert alert-danger'>يرجى اختيار ملفات PDF!</div>";
    } else {
        $uploads_dir = dirname(__DIR__) . '/assets/uploads/pdfs/';
        if (!is_dir($uploads_dir)) mkdir($uploads_dir, 0755, true);

        for ($i = 0; $i < count($files["name"]); $i++) {
            $original = $files["name"][$i];
            $tmp      = $files["tmp_name"][$i];
            $size     = $files["size"][$i];
            $ext      = strtolower(pathinfo($original, PATHINFO_EXTENSION));

            if ($ext !== 'pdf' || $size > 50000000) {
                $errors++;
                $pdf_log[] = "❌ <b>" . htmlspecialchars($original) . "</b> — نوع أو حجم غير صحيح";
                continue;
            }

            $title        = trim(str_replace(['_', '-'], ' ', pathinfo($original, PATHINFO_FILENAME)));
            $pdf_new_name = uniqid('pdf_') . '.pdf';

            if (!move_uploaded_file($tmp, $uploads_dir . $pdf_new_name)) {
                $errors++;
                $pdf_log[] = "❌ <b>" . htmlspecialchars($title) . "</b> — فشل رفع الملف";
                continue;
            }

            $result    = $processor->processBook($title, $pdf_new_name);
            $pdf_log[] = $result['msg'];

            if ($result['status'] === 'success') $count++;
            else $errors++;
        }

        $pdf_message = "<div class='alert alert-success'>✅ تمت العملية! تم إضافة <b>$count</b> كتاب" .
                       ($errors > 0 ? " — فشل <b>$errors</b> ملف" : "") . "</div>";
    }
}

// ============================================
// القسم الثالث — Textarea (طابور)
// ============================================
$textarea_message = "";

if (isset($_POST["import_textarea"])) {
    $content = trim($_POST["textarea_input"] ?? '');

    if (empty($content)) {
        $textarea_message = "<div class='alert alert-danger'>❌ الحقل فارغ!</div>";
    } else {
        $lines  = explode("\n", $content);
        $added  = 0;
        $errors = 0;

        foreach ($lines as $line) {
            $line  = trim($line);
            if (empty($line)) continue;

            $parts = explode('|', $line);
            if (count($parts) < 2) { $errors++; continue; }

            $title   = trim($parts[0]);
            $pdf_url = trim($parts[1]);

            if (empty($title) || empty($pdf_url)) { $errors++; continue; }

            if ($processor->addToQueue($title, $pdf_url)) $added++;
            else $errors++;
        }

        $textarea_message = "<div class='alert alert-info'>✅ تمت إضافة <b>$added</b> كتاب للطابور" .
                            ($errors > 0 ? " — تم تخطي <b>$errors</b>" : "") . "</div>";
    }
}

// إحصائيات الطابور
$queue_stats = $processor->getQueueStats();
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الرفع الجماعي الذكي</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { background: linear-gradient(135deg, #5d78ff, #4c5ce8); min-height: 100vh; padding: 20px; color: white; position: fixed; right: 0; top: 0; width: 220px; z-index: 1000; }
        .sidebar a { color: white; display: block; padding: 12px; text-decoration: none; margin-bottom: 5px; border-radius: 5px; transition: 0.3s; }
        .sidebar a:hover { background-color: rgba(255,255,255,0.2); }
        .btn-bulk { background-color: #ff4b5c; color: white; border: none; font-weight: bold; }
        .main-content { margin-right: 220px; padding: 40px; }
        .card { border: none; border-radius: 12px; transition: 0.3s; }
        .card:hover { box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .upload-zone { border: 2px dashed #5d78ff; border-radius: 15px; background: #f8f9ff; padding: 40px; text-align: center; transition: 0.3s; }
        .upload-zone:hover { background: #eef0ff; }
        .log-box { background: #1e1e2e; color: #cdd6f4; border-radius: 10px; padding: 20px; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 0.9em; }
        .section-title { border-right: 4px solid #5d78ff; padding-right: 10px; font-weight: 600; }
        .queue-badge { display: inline-block; padding: 8px 15px; margin: 5px 3px; border-radius: 20px; font-weight: bold; font-size: 0.9em; }
    </style>
</head>
<body>

<div class="sidebar shadow">
    <h4 class="mb-5 text-center">📚 مكتبة الثقافة</h4>
    <a href="dashboard.php"><i class="fas fa-home me-2"></i> لوحة التحكم</a>
    <a href="add_book.php"><i class="fas fa-plus me-2"></i> إضافة كتاب</a>
    <a href="bulk_upload.php" class="btn-bulk mt-2"><i class="fas fa-bolt me-2"></i> الرفع الذكي</a>
    <hr style="border-color: rgba(255,255,255,0.1)">
    <a href="logout.php" class="text-warning"><i class="fas fa-sign-out-alt me-2"></i> خروج</a>
</div>

<div class="main-content">
    <div class="container-fluid">

        <div class="d-flex justify-content-between align-items-center mb-5">
            <div>
                <h2>🚀 الرفع الجماعي الذكي</h2>
                <small class="text-muted">Google Books + Open Library</small>
            </div>
            <span class="badge bg-primary p-3 fs-6">نظام متقدم للمعالجة التلقائية</span>
        </div>

        <!-- إحصائيات الطابور -->
        <div class="card shadow-sm mb-5">
            <div class="card-body">
                <h6 class="mb-3 fw-bold">📊 حالة الطابور</h6>
                <span class="queue-badge bg-primary text-white">إجمالي: <b><?= $queue_stats['total'] ?></b></span>
                <span class="queue-badge bg-warning text-dark">معلق: <b><?= $queue_stats['pending'] ?></b></span>
                <span class="queue-badge bg-info text-white">قيد المعالجة: <b><?= $queue_stats['processing'] ?></b></span>
                <span class="queue-badge bg-success text-white">مكتمل: <b><?= $queue_stats['done'] ?></b></span>
                <span class="queue-badge bg-danger text-white">فشل: <b><?= $queue_stats['failed'] ?></b></span>
            </div>
        </div>

        <!-- القسم الأول: CSV -->
        <div class="card shadow-sm mb-5">
            <div class="card-header py-3 bg-light">
                <h5 class="mb-0 section-title"><i class="fas fa-file-csv text-success me-2"></i>القسم الأول — رفع CSV</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <b>كيفية الاستخدام:</b><br>
                    السطر الأول: <code>title,pdf_url</code><br>
                    من السطر الثاني: <code>عنوان الكتاب,رابط PDF</code><br>
                    <small>النظام يجلب تلقائياً: المؤلف + الوصف + الغلاف + التصنيف</small>
                </div>
                <?php echo $csv_message; ?>
                <?php if (!empty($csv_log)): ?>
                    <div class="log-box mb-3">
                        <?php foreach ($csv_log as $log): ?>
                            <div class="mb-1"><?= $log ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data">
                    <div class="upload-zone mb-3">
                        <i class="fas fa-file-csv fa-3x text-success mb-3"></i>
                        <h5>اختر ملف CSV</h5>
                        <input type="file" name="csv_file" accept=".csv" class="form-control mt-3" required>
                    </div>
                    <button type="submit" name="import_csv" class="btn btn-success btn-lg w-100">
                        <i class="fas fa-magic me-2"></i> ابدأ المعالجة الذكية
                    </button>
                </form>
            </div>
        </div>

        <!-- القسم الثاني: PDF -->
        <div class="card shadow-sm mb-5">
            <div class="card-header py-3 bg-light">
                <h5 class="mb-0 section-title"><i class="fas fa-file-pdf text-danger me-2"></i>القسم الثاني — رفع PDF من جهازك</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <b>كيفية الاستخدام:</b><br>
                    اختر عدة ملفات PDF — تأكد أن اسم كل ملف هو اسم الكتاب<br>
                    مثال: <b>الجريمة والعقاب.pdf</b> أو <b>1984.pdf</b><br>
                    <small>الحد الأقصى لكل ملف: 50MB</small>
                </div>
                <?php echo $pdf_message; ?>
                <?php if (!empty($pdf_log)): ?>
                    <div class="log-box mb-3">
                        <?php foreach ($pdf_log as $log): ?>
                            <div class="mb-1"><?= $log ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data">
                    <div class="upload-zone mb-3">
                        <i class="fas fa-file-pdf fa-3x text-danger mb-3"></i>
                        <h5>اختر ملفات PDF</h5>
                        <p class="text-muted small">يمكنك اختيار عدة ملفات دفعة واحدة</p>
                        <input type="file" name="pdf_files[]" accept=".pdf" class="form-control mt-3" multiple required>
                    </div>
                    <button type="submit" name="import_pdfs" class="btn btn-danger btn-lg w-100">
                        <i class="fas fa-cloud-upload-alt me-2"></i> رفع ومعالجة الملفات
                    </button>
                </form>
            </div>
        </div>

        <!-- القسم الثالث: Textarea -->
        <div class="card shadow-sm mb-5">
            <div class="card-header py-3 bg-light">
                <h5 class="mb-0 section-title"><i class="fas fa-paste text-info me-2"></i>القسم الثالث — الصق البيانات (طابور)</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <b>الصيغة:</b> في كل سطر: <code>عنوان الكتاب | رابط PDF</code><br>
                    <b>مثال:</b><br>
                    <code>
                        الجريمة والعقاب | https://example.com/book1.pdf<br>
                        1984 | https://example.com/book2.pdf
                    </code><br>
                    <small>هذا القسم يضيف للطابور — يعالجها الـ Worker تلقائياً</small>
                </div>
                <?php echo $textarea_message; ?>
                <form method="post">
                    <textarea name="textarea_input" class="form-control mb-3" rows="8"
                              placeholder="عنوان الكتاب | رابط PDF"></textarea>
                    <button type="submit" name="import_textarea" class="btn btn-info btn-lg w-100">
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