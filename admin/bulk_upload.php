<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

require_once '../includes/db.php';

// ============================================
// البحث في Google Books
// ============================================
function searchGoogleBooks($title) {
    $query = urlencode($title);
    $url = "https://www.googleapis.com/books/v1/volumes?q={$query}&maxResults=1";
    
    $context = stream_context_create(['http' => [
        'timeout' => 8,
        'header' => 'User-Agent: Mozilla/5.0'
    ]]);
    $response = @file_get_contents($url, false, $context);
    if (!$response) return null;
    
    $data = json_decode($response, true);
    if (empty($data['items'])) return null;
    
    $book = $data['items'][0]['volumeInfo'];
    return [
        'author'      => isset($book['authors']) ? implode(', ', $book['authors']) : 'غير معروف',
        'description' => isset($book['description']) ? substr($book['description'], 0, 500) : '',
        'cover'       => isset($book['imageLinks']['thumbnail']) 
                         ? str_replace('http://', 'https://', $book['imageLinks']['thumbnail']) 
                         : null,
        'category'    => isset($book['categories']) ? $book['categories'][0] : '',
    ];
}

// ============================================
// البحث في Open Library
// ============================================
function searchOpenLibrary($title) {
    $query = urlencode($title);
    $url = "https://openlibrary.org/search.json?title={$query}&limit=1";
    
    $context = stream_context_create(['http' => [
        'timeout' => 8,
        'header' => 'User-Agent: Mozilla/5.0'
    ]]);
    $response = @file_get_contents($url, false, $context);
    if (!$response) return null;
    
    $data = json_decode($response, true);
    if (empty($data['docs'])) return null;
    
    $book = $data['docs'][0];
    
    $cover = null;
    if (isset($book['cover_i'])) {
        $cover = "https://covers.openlibrary.org/b/id/{$book['cover_i']}-L.jpg";
    }
    
    return [
        'author'      => isset($book['author_name']) ? implode(', ', $book['author_name']) : 'غير معروف',
        'description' => isset($book['first_sentence'][0]) ? $book['first_sentence'][0] : '',
        'cover'       => $cover,
        'category'    => isset($book['subject'][0]) ? $book['subject'][0] : '',
    ];
}

// ============================================
// دالة جلب بيانات الكتاب (يجرب مصدرين)
// ============================================
function getBookData($title) {
    $result = searchGoogleBooks($title);
    if ($result) return $result;
    
    $result = searchOpenLibrary($title);
    if ($result) return $result;
    
    return null;
}

// ============================================
// دالة التصنيف الآلي (عربي + إنجليزي)
// ============================================
function autoClassify($text) {
    $text = mb_strtolower($text, 'UTF-8');
    $scores = [
        1 => [
            'software', 'programming', 'java', 'sql', 'code', 'python',
            'database', 'javascript', 'algorithms', 'computer',
            'برمجة', 'كود', 'حاسوب', 'تقنية', 'بيانات', 'شبكات'
        ],
        2 => [
            'history', 'war', 'ancient', 'century', 'battles', 'civilization',
            'تاريخ', 'حرب', 'حضارة', 'قرن', 'معركة', 'دولة', 'خلافة', 'إسلام'
        ],
        3 => [
            'math', 'physics', 'calculus', 'science', 'mathematics', 'algebra',
            'علوم', 'فيزياء', 'رياضيات', 'كيمياء', 'أحياء', 'طب', 'هندسة'
        ],
        4 => [
            'novel', 'story', 'drama', 'fiction', 'classic', 'literature',
            'رواية', 'قصة', 'أدب', 'شعر', 'فلسفة', 'وجودية', 'نفس',
            'ديوان', 'مسرحية', 'حكاية', 'سيرة'
        ]
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

// ============================================
// دالة حفظ الغلاف من URL
// ============================================
function saveCoverFromUrl($url, $title) {
    if (!$url) return 'default_book.jpg';
    
    $context = stream_context_create(['http' => [
        'timeout' => 8,
        'header' => 'User-Agent: Mozilla/5.0'
    ]]);
    $image_data = @file_get_contents($url, false, $context);
    
    if (!$image_data) return 'default_book.jpg';
    
    $filename = 'cover_' . uniqid() . '.jpg';
    $path = '../assets/uploads/covers/' . $filename;
    
    if (file_put_contents($path, $image_data)) {
        return $filename;
    }
    return 'default_book.jpg';
}

// ============================================
// معالجة القسم الأول — CSV
// ============================================
$csv_message = "";
$csv_log = [];

if (isset($_POST["import_csv"])) {
    $ext = strtolower(pathinfo($_FILES["csv_file"]["name"], PATHINFO_EXTENSION));
    
    if ($ext !== 'csv') {
        $csv_message = "<div class='alert alert-danger'>يجب أن يكون الملف بصيغة CSV فقط!</div>";
    } elseif ($_FILES["csv_file"]["size"] <= 0) {
        $csv_message = "<div class='alert alert-danger'>الملف فارغ!</div>";
    } else {
        $file = fopen($_FILES["csv_file"]["tmp_name"], "r");
        fgetcsv($file);
        $count = 0;
        $errors = 0;

        try {
            while (($column = fgetcsv($file, 1000, ",")) !== FALSE) {
                if (count($column) < 2) { $errors++; continue; }

                $title   = trim($column[0]);
                $pdf_url = trim($column[1]);

                if (empty($title) || empty($pdf_url)) { $errors++; continue; }

                $book_data = getBookData($title);

                if ($book_data) {
                    $author      = $book_data['author'];
                    $description = $book_data['description'];
                    $cover       = saveCoverFromUrl($book_data['cover'], $title);
                    $cat_text    = $book_data['category'] . ' ' . $description;
                    $csv_log[]   = "✅ <b>$title</b> — تم جلب البيانات (المؤلف: $author)";
                } else {
                    $author      = 'غير معروف';
                    $description = '';
                    $cover       = 'default_book.jpg';
                    $cat_text    = $title;
                    $csv_log[]   = "⚠️ <b>$title</b> — لم يُعثر على بيانات، تم الحفظ بدون معلومات";
                }

                $cat_id = autoClassify($cat_text);

                $stmt = $pdo->prepare("INSERT INTO books (title, author, description, category_id, pdf_file, cover_image, created_at, downloads, views) 
                                       VALUES (?, ?, ?, ?, ?, ?, NOW(), 0, 0)");
                $stmt->execute([$title, $author, $description, $cat_id, $pdf_url, $cover]);
                $count++;
            }

            $csv_message = "<div class='alert alert-success'>تمت العملية! تم إضافة <b>$count</b> كتاب" . 
                          ($errors > 0 ? " — تم تخطي <b>$errors</b> سطر" : "") . "</div>";

        } catch (Exception $e) {
            error_log($e->getMessage());
            $csv_message = "<div class='alert alert-danger'>حدث خطأ أثناء المعالجة.</div>";
        } finally {
            fclose($file);
        }
    }
}

// ============================================
// معالجة القسم الثاني — PDF جماعي
// ============================================
$pdf_message = "";
$pdf_log = [];

if (isset($_POST["import_pdfs"])) {
    $files = $_FILES["pdf_files"];
    $count = 0;
    $errors = 0;

    if (empty($files["name"][0])) {
        $pdf_message = "<div class='alert alert-danger'>يرجى اختيار ملفات PDF!</div>";
    } else {
        $uploads_dir_pdf   = '../assets/uploads/pdfs/';
        $uploads_dir_cover = '../assets/uploads/covers/';

        for ($i = 0; $i < count($files["name"]); $i++) {
            $original_name = $files["name"][$i];
            $tmp_name      = $files["tmp_name"][$i];
            $file_size     = $files["size"][$i];
            $file_ext      = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

            if ($file_ext !== 'pdf' || $file_size > 50000000) {
                $errors++;
                $pdf_log[] = "❌ <b>$original_name</b> — نوع أو حجم غير صحيح";
                continue;
            }

            $title = pathinfo($original_name, PATHINFO_FILENAME);
            $title = str_replace(['_', '-'], ' ', $title);
            $title = trim($title);

            $pdf_new_name = uniqid('pdf_') . '.pdf';
            if (!move_uploaded_file($tmp_name, $uploads_dir_pdf . $pdf_new_name)) {
                $errors++;
                $pdf_log[] = "❌ <b>$title</b> — فشل رفع الملف";
                continue;
            }

            $book_data = getBookData($title);

            if ($book_data) {
                $author      = $book_data['author'];
                $description = $book_data['description'];
                $cover       = saveCoverFromUrl($book_data['cover'], $title);
                $cat_text    = $book_data['category'] . ' ' . $description;
                $pdf_log[]   = "✅ <b>$title</b> — تم جلب البيانات (المؤلف: $author)";
            } else {
                $author      = 'غير معروف';
                $description = '';
                $cover       = 'default_book.jpg';
                $cat_text    = $title;
                $pdf_log[]   = "⚠️ <b>$title</b> — لم يُعثر على بيانات، تم الحفظ باسم الملف";
            }

            $cat_id = autoClassify($cat_text);

            $stmt = $pdo->prepare("INSERT INTO books (title, author, description, category_id, pdf_file, cover_image, created_at, downloads, views) 
                                   VALUES (?, ?, ?, ?, ?, ?, NOW(), 0, 0)");
            $stmt->execute([$title, $author, $description, $cat_id, $pdf_new_name, $cover]);
            $count++;
        }

        $pdf_message = "<div class='alert alert-success'>تمت العملية! تم إضافة <b>$count</b> كتاب" . 
                      ($errors > 0 ? " — فشل <b>$errors</b> ملف" : "") . "</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>الرفع الجماعي الذكي - لوحة الإدارة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { background-color: #5d78ff; min-height: 100vh; padding: 20px; color: white; position: fixed; right: 0; top: 0; width: 220px; z-index: 1000; }
        .sidebar a { color: white; display: block; padding: 12px; text-decoration: none; margin-bottom: 5px; border-radius: 5px; transition: 0.3s; }
        .sidebar a:hover { background-color: rgba(255,255,255,0.1); }
        .main-content { margin-right: 220px; padding: 40px; }
        .card { border: none; border-radius: 12px; }
        .upload-zone { border: 2px dashed #5d78ff; border-radius: 15px; background: #f8f9ff; padding: 40px; text-align: center; transition: 0.3s; }
        .upload-zone:hover { background: #eef0ff; }
        .btn-bulk { background-color: #ff4b5c; color: white; border: none; font-weight: bold; }
        .log-box { background: #1e1e2e; color: #cdd6f4; border-radius: 10px; padding: 20px; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 0.9em; }
        .section-title { border-right: 4px solid #5d78ff; padding-right: 10px; margin-bottom: 20px; }
    </style>
</head>
<body>

<div class="sidebar shadow">
    <h4 class="mb-5 text-center">مكتبة الثقافة</h4>
    <a href="dashboard.php"><i class="fas fa-home me-2"></i> لوحة التحكم</a>
    <a href="add_book.php"><i class="fas fa-plus me-2"></i> إضافة كتاب</a>
    <a href="bulk_upload.php" class="btn-bulk mt-2"><i class="fas fa-bolt me-2"></i> الرفع الذكي</a>
    <hr style="border-color: rgba(255,255,255,0.1)">
    <a href="logout.php" class="text-warning"><i class="fas fa-sign-out-alt me-2"></i> خروج</a>
</div>

<div class="main-content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-5">
            <h2>الرفع الجماعي الذكي</h2>
            <div class="badge bg-primary p-2 fs-6">يعمل بـ Google Books + Open Library</div>
        </div>

        <!-- ===== القسم الأول: CSV ===== -->
        <div class="card shadow-sm mb-5">
            <div class="card-header py-3">
                <h5 class="mb-0 section-title">
                    <i class="fas fa-file-csv text-success me-2"></i>
                    القسم الأول — رفع CSV (عنوان + رابط PDF)
                </h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <b>كيف تستخدم هذا القسم؟</b><br>
                    1. افتح Notepad أو Excel وأنشئ عمودين: <b>title</b> و <b>pdf_url</b><br>
                    2. ضع عنوان كل كتاب في العمود الأول ورابط PDF في الثاني<br>
                    3. احفظ الملف بصيغة CSV وارفعه هنا<br>
                    <small class="text-muted">✨ النظام سيجلب تلقائياً: المؤلف + الوصف + الغلاف + التصنيف</small>
                </div>

                <?php echo $csv_message; ?>

                <?php if (!empty($csv_log)): ?>
                    <div class="log-box mb-3">
                        <?php foreach ($csv_log as $log): ?>
                            <div class="mb-1"><?php echo $log; ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form action="" method="post" enctype="multipart/form-data">
                    <div class="upload-zone mb-3">
                        <i class="fas fa-file-csv fa-3x text-success mb-3"></i>
                        <h5>اختر ملف CSV</h5>
                        <p class="text-muted small">العمود الأول: عنوان الكتاب | العمود الثاني: رابط PDF</p>
                        <input type="file" name="csv_file" accept=".csv" class="form-control mt-3" required>
                    </div>
                    <button type="submit" name="import_csv" class="btn btn-success btn-lg w-100">
                        <i class="fas fa-magic me-2"></i> ابدأ المعالجة الذكية
                    </button>
                </form>
            </div>
        </div>

        <!-- ===== القسم الثاني: PDF جماعي ===== -->
        <div class="card shadow-sm">
            <div class="card-header py-3">
                <h5 class="mb-0 section-title">
                    <i class="fas fa-file-pdf text-danger me-2"></i>
                    القسم الثاني — رفع ملفات PDF من جهازك
                </h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <b>كيف تستخدم هذا القسم؟</b><br>
                    1. اختر عدة ملفات PDF من جهازك دفعة واحدة<br>
                    2. تأكد أن اسم كل ملف هو اسم الكتاب<br>
                    &nbsp;&nbsp;&nbsp;مثال: <b>الجريمة والعقاب.pdf</b> أو <b>1984.pdf</b><br>
                    3. اضغط رفع — النظام سيجلب البيانات تلقائياً<br>
                    <small class="text-muted">الحد الأقصى لكل ملف: 50MB</small>
                </div>

                <?php echo $pdf_message; ?>

                <?php if (!empty($pdf_log)): ?>
                    <div class="log-box mb-3">
                        <?php foreach ($pdf_log as $log): ?>
                            <div class="mb-1"><?php echo $log; ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form action="" method="post" enctype="multipart/form-data">
                    <div class="upload-zone mb-3">
                        <i class="fas fa-file-pdf fa-3x text-danger mb-3"></i>
                        <h5>اختر ملفات PDF</h5>
                        <p class="text-muted small">يمكنك اختيار عدة ملفات دفعة واحدة</p>
                        <input type="file" name="pdf_files[]" accept=".pdf" 
                               class="form-control mt-3" multiple required>
                    </div>
                    <button type="submit" name="import_pdfs" class="btn btn-danger btn-lg w-100">
                        <i class="fas fa-cloud-upload-alt me-2"></i> رفع ومعالجة الملفات
                    </button>
                </form>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>