<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

require_once '../includes/db.php';
require_once '../includes/BookProcessor.php';

// ============================================================
// دوال مساعدة مستقلة (للـ CSV mode الذي لا يحتاج BookProcessor)
// ============================================================
function searchGoogleBooks($title) {
    $query   = urlencode($title);
    $url     = "https://www.googleapis.com/books/v1/volumes?q={$query}&maxResults=1";
    $context = stream_context_create(['http' => ['timeout' => 8, 'header' => 'User-Agent: Mozilla/5.0']]);
    $response = @file_get_contents($url, false, $context);
    if (!$response) return null;
    $data = json_decode($response, true);
    if (empty($data['items'])) return null;
    $book = $data['items'][0]['volumeInfo'];
    return [
        'author'      => isset($book['authors']) ? implode(', ', $book['authors']) : 'غير معروف',
        'description' => isset($book['description']) ? substr(strip_tags($book['description']), 0, 800) : '',
        'cover'       => isset($book['imageLinks']['thumbnail'])
                         ? str_replace('http://', 'https://', $book['imageLinks']['thumbnail']) : null,
        'category'    => isset($book['categories']) ? $book['categories'][0] : '',
    ];
}

function searchOpenLibrary($title) {
    $query   = urlencode($title);
    $url     = "https://openlibrary.org/search.json?title={$query}&limit=1&fields=title,author_name,first_sentence,subject,cover_i";
    $context = stream_context_create(['http' => ['timeout' => 8, 'header' => 'User-Agent: Mozilla/5.0']]);
    $response = @file_get_contents($url, false, $context);
    if (!$response) return null;
    $data = json_decode($response, true);
    if (empty($data['docs'])) return null;
    $book = $data['docs'][0];
    $desc = '';
    if (isset($book['first_sentence'])) {
        $desc = is_array($book['first_sentence'])
            ? implode(' ', array_slice($book['first_sentence'], 0, 3))
            : $book['first_sentence'];
        $desc = substr($desc, 0, 800);
    }
    $cat = '';
    if (isset($book['subject']) && is_array($book['subject'])) {
        $cat = implode(' ', array_slice($book['subject'], 0, 5));
    }
    return [
        'author'      => isset($book['author_name']) ? implode(', ', $book['author_name']) : 'غير معروف',
        'description' => $desc,
        'cover'       => isset($book['cover_i'])
                         ? "https://covers.openlibrary.org/b/id/{$book['cover_i']}-L.jpg" : null,
        'category'    => $cat,
    ];
}

function getBookData($title) {
    $r = searchGoogleBooks($title);
    if ($r) return $r;
    $r = searchOpenLibrary($title);
    if ($r) return $r;
    return null;
}

function autoClassify($text) {
    $text = mb_strtolower($text, 'UTF-8');
    $scores = [
        1  => ['software','programming','java','sql','code','python','database','javascript','algorithms','computer','coding','برمجة','كود','حاسوب','تقنية','بيانات','شبكات','ذكاء اصطناعي'],
        2  => ['history','war','ancient','century','battles','civilization','empire','historical','medieval','ottoman','roman','تاريخ','حرب','حضارة','قرن','معركة','دولة','خلافة','عثماني'],
        3  => ['math','physics','calculus','science','mathematics','algebra','chemistry','biology','astronomy','universe','quantum','علوم','فيزياء','رياضيات','كيمياء','أحياء','طب','هندسة','فلك'],
        4  => ['novel','story','drama','classic','literature','poetry','prose','narrative','tale','رواية','قصة','أدب','شعر','ديوان','مسرحية','حكاية','سيرة','نثر'],
        5  => ['general','عام'],
        6  => ['fantasy','magic','dragon','wizard','witch','spell','mythical','فانتازيا','سحر','تنين','ساحر','خيال','أسطورة','مملكة'],
        7  => ['horror','scary','ghost','haunted','terror','nightmare','demon','vampire','zombie','evil','darkness','رعب','مخيف','شبح','ظلام','خوف','وحش','مسكون'],
        8  => ['mystery','thriller','detective','crime','murder','suspense','investigation','sherlock','spy','secret','غموض','تشويق','محقق','جريمة','قتل','سر','تحقيق','جاسوس'],
        9  => ['science fiction','sci-fi','space','robot','alien','dystopia','dystopian','future','galaxy','spacecraft','خيال علمي','فضاء','روبوت','مستقبل','مجرة','ديستوبيا'],
        10 => ['autobiography','biography','memoir','life story','سيرة','ذاتية','مذكرات','حياة'],
        11 => ['self help','motivation','success','leadership','productivity','mindset','habits','personal development','تطوير','نجاح','قيادة','إنتاجية','عادات','أهداف','تحفيز'],
        12 => ['islam','quran','hadith','prophet','religious','faith','إسلام','قرآن','حديث','نبي','دين','فقه','عقيدة','إيمان'],
        13 => ['politics','economy','government','democracy','economics','capitalism','socialism','policy','سياسة','اقتصاد','حكومة','ديمقراطية','رأسمالية','نظام'],
    ];
    $results = [];
    foreach ($scores as $id => $keywords) {
        $results[$id] = 0;
        foreach ($keywords as $word) {
            if (strpos($text, $word) !== false) $results[$id]++;
        }
    }
    arsort($results);
    $best = key($results);
    return $results[$best] > 0 ? $best : 5;
}

function saveCoverFromUrl($url, $title) {
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) return 'default_book.jpg';
    $context    = stream_context_create(['http' => ['timeout' => 10, 'header' => 'User-Agent: Mozilla/5.0', 'follow_location' => 1]]);
    $image_data = @file_get_contents($url, false, $context);
    if (!$image_data || strlen($image_data) === 0) return 'default_book.jpg';
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_buffer($finfo, $image_data);
    finfo_close($finfo);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) return 'default_book.jpg';
    $filename = 'cover_' . uniqid() . '.jpg';
    $path     = '../assets/uploads/covers/' . $filename;
    if (@file_put_contents($path, $image_data)) return $filename;
    return 'default_book.jpg';
}

// ============================================================
// معالجة CSV
// ============================================================
$csv_message = "";
$csv_log     = [];

if (isset($_POST["import_csv"])) {
    $ext = strtolower(pathinfo($_FILES["csv_file"]["name"], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        $csv_message = "<div class='alert alert-danger'>يجب أن يكون الملف بصيغة CSV!</div>";
    } elseif ($_FILES["csv_file"]["size"] <= 0) {
        $csv_message = "<div class='alert alert-danger'>الملف فارغ!</div>";
    } else {
        $file   = fopen($_FILES["csv_file"]["tmp_name"], "r");
        fgetcsv($file); // تخطي الـ header
        $count  = 0;
        $errors = 0;
        try {
            while (($col = fgetcsv($file, 1000, ",")) !== false) {
                if (count($col) < 2) { $errors++; continue; }
                $title   = trim($col[0]);
                $pdf_url = trim($col[1]);
                if (empty($title) || empty($pdf_url)) { $errors++; continue; }

                // تحقق تكرار
                $chk = $pdo->prepare("SELECT id FROM books WHERE title = ? LIMIT 1");
                $chk->execute([$title]);
                if ($chk->fetch()) {
                    $csv_log[] = "⚠️ <b>" . htmlspecialchars($title) . "</b> — موجود مسبقاً";
                    $errors++;
                    continue;
                }

                $book_data = getBookData($title);
                if ($book_data) {
                    $author      = htmlspecialchars($book_data['author'], ENT_QUOTES, 'UTF-8');
                    $description = htmlspecialchars($book_data['description'], ENT_QUOTES, 'UTF-8');
                    $cover       = saveCoverFromUrl($book_data['cover'], $title);
                    $cat_text    = ($book_data['category'] ?? '') . ' ' . $description;
                    $csv_log[]   = "✅ <b>" . htmlspecialchars($title) . "</b> — تم جلب البيانات (المؤلف: $author)";
                } else {
                    $author      = 'غير معروف';
                    $description = '';
                    $cover       = 'default_book.jpg';
                    $cat_text    = $title;
                    $csv_log[]   = "⚠️ <b>" . htmlspecialchars($title) . "</b> — لم يُعثر على بيانات";
                }

                $cat_id = autoClassify($cat_text);
                $stmt   = $pdo->prepare("INSERT INTO books (title, author, description, category_id, pdf_file, cover_image, created_at, downloads, views) VALUES (?, ?, ?, ?, ?, ?, NOW(), 0, 0)");
                $stmt->execute([
                    htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                    $author, $description, $cat_id,
                    htmlspecialchars($pdf_url, ENT_QUOTES, 'UTF-8'),
                    $cover,
                ]);
                $count++;
            }
            $csv_message = "<div class='alert alert-success'>✅ تمت العملية! تم إضافة <b>$count</b> كتاب"
                         . ($errors > 0 ? " — تم تخطي <b>$errors</b> سطر" : "") . "</div>";
        } catch (Exception $e) {
            error_log($e->getMessage());
            $csv_message = "<div class='alert alert-danger'>❌ حدث خطأ أثناء المعالجة.</div>";
        } finally {
            fclose($file);
        }
    }
}

// ============================================================
// ★ معالجة PDF جماعي — محدّث مع استخراج نص PDF
// ============================================================
$pdf_message = "";
$pdf_log     = [];

if (isset($_POST["import_pdfs"])) {
    $files  = $_FILES["pdf_files"];
    $count  = 0;
    $errors = 0;

    if (empty($files["name"][0])) {
        $pdf_message = "<div class='alert alert-danger'>يرجى اختيار ملفات PDF!</div>";
    } else {
        $uploads_dir_pdf   = '../assets/uploads/pdfs/';
        $uploads_dir_cover = '../assets/uploads/covers/';
        if (!is_dir($uploads_dir_pdf))   mkdir($uploads_dir_pdf,   0755, true);
        if (!is_dir($uploads_dir_cover)) mkdir($uploads_dir_cover, 0755, true);

        for ($i = 0; $i < count($files["name"]); $i++) {
            $original_name = $files["name"][$i];
            $tmp_name      = $files["tmp_name"][$i];
            $file_size     = $files["size"][$i];
            $file_ext      = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

            if ($file_ext !== 'pdf' || $file_size > 50000000) {
                $errors++;
                $pdf_log[] = "❌ <b>" . htmlspecialchars($original_name) . "</b> — نوع أو حجم غير صحيح";
                continue;
            }

            $title = pathinfo($original_name, PATHINFO_FILENAME);
            $title = str_replace(['_', '-'], ' ', $title);
            $title = trim($title);

            // تحقق تكرار
            $chk = $pdo->prepare("SELECT id FROM books WHERE title = ? LIMIT 1");
            $chk->execute([$title]);
            if ($chk->fetch()) {
                $pdf_log[] = "⚠️ <b>" . htmlspecialchars($title) . "</b> — الكتاب موجود مسبقاً";
                $errors++;
                continue;
            }

            // رفع الملف أولاً
            $pdf_new_name = uniqid('pdf_') . '.pdf';
            $pdf_full_path = $uploads_dir_pdf . $pdf_new_name;

            if (!move_uploaded_file($tmp_name, $pdf_full_path)) {
                $errors++;
                $pdf_log[] = "❌ <b>" . htmlspecialchars($title) . "</b> — فشل رفع الملف";
                continue;
            }

            // ★ استخدام BookProcessor مع دعم استخراج نص PDF
            $processor = new BookProcessor($pdo);
            $book_data = $processor->getBookDataWithCache($title);

            $author      = 'غير معروف';
            $description = '';
            $cover       = 'default_book.jpg';

            if ($book_data) {
                $author      = htmlspecialchars($book_data['author'] ?? 'غير معروف', ENT_QUOTES, 'UTF-8');
                $description = htmlspecialchars($book_data['description'] ?? '', ENT_QUOTES, 'UTF-8');
                $cover       = $processor->saveCover($book_data['cover'] ?? null, $title);
                $pdf_log[]   = "✅ <b>" . htmlspecialchars($title) . "</b> — بيانات API (المؤلف: $author)";
            } else {
                $pdf_log[] = "⚠️ <b>" . htmlspecialchars($title) . "</b> — لم تُعثر على بيانات API";
            }

            // ★ بناء نص التصنيف: يجرب PDF أولاً ثم API
            $classify_text = $processor->buildClassificationText(
                $book_data ?? [],
                $pdf_full_path   // ← يمرر مسار الملف لاستخراج النص
            );

            // إذا نجح استخراج PDF سيظهر في اللوج
            $pdf_text = $processor->extractTextFromPdf($pdf_full_path);
            if (!empty($pdf_text)) {
                $pdf_log[] = "📄 <b>" . htmlspecialchars($title) . "</b> — تم التصنيف من نص PDF مباشرة";
            }

            $cat_id = $processor->autoClassify($classify_text ?: $title);

            try {
                $stmt = $pdo->prepare("INSERT INTO books (title, author, description, category_id, pdf_file, cover_image, created_at, downloads, views) VALUES (?, ?, ?, ?, ?, ?, NOW(), 0, 0)");
                $stmt->execute([
                    htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                    $author, $description, $cat_id,
                    $pdf_new_name, $cover,
                ]);
                $count++;
            } catch (Exception $e) {
                $pdf_log[] = "❌ خطأ DB: " . htmlspecialchars($e->getMessage());
                $errors++;
            }
        }

        $pdf_message = "<div class='alert alert-success'>✅ تمت العملية! تم إضافة <b>$count</b> كتاب"
                     . ($errors > 0 ? " — فشل <b>$errors</b> ملف" : "") . "</div>";
    }
}

// ============================================================
// معالجة Textarea → Queue
// ============================================================
$textarea_message = "";

if (isset($_POST["import_textarea"])) {
    $textarea_content = trim($_POST["textarea_input"] ?? '');
    if (empty($textarea_content)) {
        $textarea_message = "<div class='alert alert-danger'>❌ الحقل فارغ!</div>";
    } else {
        $lines  = explode("\n", $textarea_content);
        $added  = 0;
        $errors = 0;
        try {
            $stmt = $pdo->prepare("INSERT INTO import_queue (title, pdf_url, import_type, status) VALUES (?, ?, 'textarea', 'pending')");
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
                // تشغيل Worker في الخلفية
                $worker_path = __DIR__ . '/worker.php';
                $php_exe     = PHP_BINARY ?: 'php';
                @shell_exec("\"$php_exe\" \"$worker_path\" process > nul 2>&1 &");
                $textarea_message = "<div class='alert alert-info'>✅ تمت إضافة <b>$added</b> كتاب إلى الطابور!</div>";
            } else {
                $textarea_message = "<div class='alert alert-warning'>⚠️ لم يتم إضافة أي كتاب" . ($errors > 0 ? " ($errors تخطي)" : "") . "</div>";
            }
        } catch (Exception $e) {
            $textarea_message = "<div class='alert alert-danger'>❌ خطأ: " . htmlspecialchars($e->getMessage()) . "</div>";
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
        body          { background: #f4f7f6; font-family: 'Segoe UI', sans-serif; }
        .sidebar      { background: linear-gradient(135deg,#5d78ff,#4c5ce8); min-height: 100vh; padding: 20px; color: #fff; position: fixed; right: 0; top: 0; width: 220px; z-index: 1000; }
        .sidebar a    { color: #fff; display: block; padding: 12px; text-decoration: none; border-radius: 5px; margin-bottom: 5px; transition: .3s; }
        .sidebar a:hover { background: rgba(255,255,255,.2); }
        .main-content { margin-right: 220px; padding: 40px; }
        .card         { border: none; border-radius: 12px; transition: .3s; }
        .card:hover   { box-shadow: 0 8px 25px rgba(0,0,0,.1); }
        .upload-zone  { border: 2px dashed #5d78ff; border-radius: 15px; background: #f8f9ff; padding: 40px; text-align: center; cursor: pointer; transition: .3s; }
        .upload-zone:hover { background: #eef0ff; }
        .log-box      { background: #1e1e2e; color: #cdd6f4; border-radius: 10px; padding: 20px; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: .88em; }
        .section-title { border-right: 4px solid #5d78ff; padding-right: 10px; font-weight: 700; }
        .queue-badge  { display: inline-block; padding: 6px 14px; margin: 4px; border-radius: 20px; font-weight: 700; font-size: .85em; }
        .btn-bulk     { background: #ff4b5c; color: #fff; border: none; font-weight: 700; }
        .btn-bulk:hover { background: #e63946; color: #fff; }
        /* ★ بادج جديد للـ PDF text */
        .badge-pdf { background: #17a2b8; color: #fff; font-size: .75em; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>

<div class="sidebar shadow">
    <h4 class="mb-4 text-center fw-bold">📚 مكتبة الثقافة</h4>
    <a href="dashboard.php"><i class="fas fa-home me-2"></i> لوحة التحكم</a>
    <a href="add_book.php"><i class="fas fa-plus me-2"></i> إضافة كتاب</a>
    <a href="bulk_upload.php" class="btn-bulk"><i class="fas fa-bolt me-2"></i> الرفع الذكي</a>
    <a href="classification_stats.php"><i class="fas fa-chart-bar me-2"></i> إحصائيات التصنيف</a>
    <hr style="border-color:rgba(255,255,255,.15)">
    <a href="logout.php" class="text-warning"><i class="fas fa-sign-out-alt me-2"></i> خروج</a>
</div>

<div class="main-content">
<div class="container-fluid">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-0">🚀 الرفع الجماعي الذكي</h2>
            <small class="text-muted">يعمل بـ Google Books + Open Library + ★ تصنيف من نص PDF</small>
        </div>
        <span class="badge bg-primary p-2 fs-6">
            <i class="fas fa-cloud-upload-alt me-1"></i> 3 أوضاع
        </span>
    </div>

    <!-- حالة الطابور -->
    <div class="card shadow-sm mb-4 p-3">
        <h6 class="mb-3">📊 حالة الطابور (آخر 7 أيام)</h6>
        <div>
            <span class="queue-badge" style="background:#3498db;color:#fff">إجمالي: <b><?= (int)($queue_status['total'] ?? 0) ?></b></span>
            <span class="queue-badge" style="background:#f39c12;color:#fff">معلق: <b><?= (int)($queue_status['pending'] ?? 0) ?></b></span>
            <span class="queue-badge" style="background:#e67e22;color:#fff">قيد المعالجة: <b><?= (int)($queue_status['processing'] ?? 0) ?></b></span>
            <span class="queue-badge" style="background:#27ae60;color:#fff">مكتمل: <b><?= (int)($queue_status['done'] ?? 0) ?></b></span>
            <span class="queue-badge" style="background:#c0392b;color:#fff">فشل: <b><?= (int)($queue_status['failed'] ?? 0) ?></b></span>
        </div>
    </div>

    <!-- ══ القسم الأول: CSV ══ -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light py-3">
            <h5 class="mb-0 section-title">
                <i class="fas fa-file-csv text-success me-2"></i>
                القسم الأول — رفع CSV (عنوان + رابط PDF)
            </h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <b>كيفية الاستخدام:</b><br>
                أنشئ ملف CSV بعمودين: <b>title</b> و <b>pdf_url</b><br>
                <small class="text-muted">✨ يجلب تلقائياً: مؤلف + وصف + غلاف + تصنيف</small>
            </div>
            <?php echo $csv_message; ?>
            <?php if (!empty($csv_log)): ?>
                <div class="log-box mb-3">
                    <?php foreach ($csv_log as $l): ?><div class="mb-1"><?= $l ?></div><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data">
                <div class="upload-zone mb-3">
                    <i class="fas fa-file-csv fa-3x text-success mb-3"></i>
                    <h5>اختر ملف CSV</h5>
                    <p class="text-muted small">العمود الأول: العنوان | العمود الثاني: رابط PDF</p>
                    <input type="file" name="csv_file" accept=".csv" class="form-control mt-3" required>
                </div>
                <button type="submit" name="import_csv" class="btn btn-success btn-lg w-100">
                    <i class="fas fa-magic me-2"></i> ابدأ المعالجة الذكية
                </button>
            </form>
        </div>
    </div>

    <!-- ══ القسم الثاني: PDF جماعي ══ -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light py-3">
            <h5 class="mb-0 section-title">
                <i class="fas fa-file-pdf text-danger me-2"></i>
                القسم الثاني — رفع ملفات PDF من جهازك
                <span class="badge-pdf ms-2">★ تصنيف من نص PDF</span>
            </h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <b>كيفية الاستخدام:</b><br>
                اختر عدة ملفات PDF — اسم الملف = اسم الكتاب<br>
                مثال: <b>الجريمة والعقاب.pdf</b> أو <b>1984.pdf</b><br>
                <small class="text-muted">
                    ✨ جديد: النظام الآن يستخرج نص الكتاب من PDF مباشرة لتصنيف أدق —
                    إذا فشل يستخدم وصف API كـ fallback
                </small>
            </div>
            <?php echo $pdf_message; ?>
            <?php if (!empty($pdf_log)): ?>
                <div class="log-box mb-3">
                    <?php foreach ($pdf_log as $l): ?><div class="mb-1"><?= $l ?></div><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data">
                <div class="upload-zone mb-3">
                    <i class="fas fa-file-pdf fa-3x text-danger mb-3"></i>
                    <h5>اختر ملفات PDF</h5>
                    <p class="text-muted small">يمكنك اختيار عدة ملفات دفعة واحدة — حد أقصى 50MB لكل ملف</p>
                    <input type="file" name="pdf_files[]" accept=".pdf"
                           class="form-control mt-3" multiple required>
                </div>
                <button type="submit" name="import_pdfs" class="btn btn-danger btn-lg w-100">
                    <i class="fas fa-cloud-upload-alt me-2"></i> رفع ومعالجة الملفات
                </button>
            </form>
        </div>
    </div>

    <!-- ══ القسم الثالث: Textarea ══ -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light py-3">
            <h5 class="mb-0 section-title">
                <i class="fas fa-paste text-info me-2"></i>
                القسم الثالث — إدخال نصي سريع (طابور)
            </h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <b>الصيغة:</b> في كل سطر: <code>عنوان الكتاب | رابط PDF</code><br>
                <b>مثال:</b><br>
                <code>
                    الجريمة والعقاب | https://example.com/book1.pdf<br>
                    1984 | https://example.com/book2.pdf
                </code><br>
                <small class="text-muted">💡 يُضاف إلى طابور المعالجة ويُعالج في الخلفية</small>
            </div>
            <?php echo $textarea_message; ?>
            <form method="post">
                <textarea name="textarea_input" class="form-control mb-3" rows="7"
                    placeholder="عنوان الكتاب | رابط PDF&#10;الجريمة والعقاب | https://...&#10;1984 | https://..."></textarea>
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
