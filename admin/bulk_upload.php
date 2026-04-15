
<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

require_once '../includes/db.php';
require_once '../includes/BookProcessor.php';

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

    $description = '';
    if (isset($book['description'])) {
        $description = substr(strip_tags($book['description']), 0, 800);
    }

    return [
        'author'      => isset($book['authors']) ? implode(', ', $book['authors']) : 'غير معروف',
        'description' => $description,
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
    $url = "https://openlibrary.org/search.json?title={$query}&limit=1&fields=title,author_name,first_sentence,subject,cover_i";
    
    $context = stream_context_create(['http' => [
        'timeout' => 8,
        'header' => 'User-Agent: Mozilla/5.0'
    ]]);
    $response = @file_get_contents($url, false, $context);
    if (!$response) return null;
    
    $data = json_decode($response, true);
    if (empty($data['docs'])) return null;
    
    $book = $data['docs'][0];
    
    $description = '';
    if (isset($book['first_sentence'])) {
        if (is_array($book['first_sentence'])) {
            $description = implode(' ', array_slice($book['first_sentence'], 0, 3));
        } else {
            $description = $book['first_sentence'];
        }
        $description = substr($description, 0, 800);
    }

    $cover = null;
    if (isset($book['cover_i'])) {
        $cover = "https://covers.openlibrary.org/b/id/{$book['cover_i']}-L.jpg";
    }
    
    $category = '';
    if (isset($book['subject']) && is_array($book['subject'])) {
        $category = implode(' ', array_slice($book['subject'], 0, 5));
    }

    return [
        'author'      => isset($book['author_name']) ? implode(', ', $book['author_name']) : 'غير معروف',
        'description' => $description,
        'cover'       => $cover,
        'category'    => $category,
    ];
}

// ============================================
// دالة جلب بيانات الكتاب — تجرب مصدرين
// ============================================
function getBookData($title) {
    $result = searchGoogleBooks($title);
    if ($result) return $result;
    
    $result = searchOpenLibrary($title);
    if ($result) return $result;
    
    return null;
}

// ============================================
// دالة التصنيف الآلي — عربي وإنجليزي 
// ============================================
function autoClassify($text) {
    $text = mb_strtolower($text, 'UTF-8');
    $scores = [
        1 => [
            'software', 'programming', 'java', 'sql', 'code', 'python',
            'database', 'javascript', 'algorithms', 'computer', 'coding',
            'برمجة', 'كود', 'حاسوب', 'تقنية', 'بيانات', 'شبكات', 'ذكاء اصطناعي'
        ],
        2 => [
            'history', 'war', 'ancient', 'century', 'battles', 'civilization',
            'empire', 'historical', 'medieval', 'ottoman', 'roman',
            'تاريخ', 'حرب', 'حضارة', 'قرن', 'معركة', 'دولة', 'خلافة', 'عثماني'
        ],
        3 => [
            'math', 'physics', 'calculus', 'science', 'mathematics', 'algebra',
            'chemistry', 'biology', 'astronomy', 'universe', 'quantum',
            'علوم', 'فيزياء', 'رياضيات', 'كيمياء', 'أحياء', 'طب', 'هندسة', 'فلك'
        ],
        4 => [
            'novel', 'story', 'drama', 'classic', 'literature', 'poetry',
            'prose', 'narrative', 'tale', 'short story',
            'رواية', 'قصة', 'أدب', 'شعر', 'ديوان', 'مسرحية', 'حكاية', 'سيرة', 'نثر'
        ],
        6 => [
            'fantasy', 'magic', 'dragon', 'wizard', 'witch', 'spell',
            'mythical', 'enchanted', 'fairy', 'elf', 'hobbit',
            'فانتازيا', 'سحر', 'تنين', 'ساحر', 'خيال', 'أسطورة', 'مملكة'
        ],
        7 => [
            'horror', 'scary', 'ghost', 'haunted', 'terror', 'nightmare',
            'demon', 'vampire', 'zombie', 'evil', 'darkness',
            'رعب', 'مخيف', 'شبح', 'ظلام', 'خوف', 'وحش', 'مسكون'
        ],
        8 => [
            'mystery', 'thriller', 'detective', 'crime', 'murder', 'suspense',
            'investigation', 'clue', 'sherlock', 'spy', 'secret',
            'غموض', 'تشويق', 'محقق', 'جريمة', 'قتل', 'سر', 'تحقيق', 'جاسوس'
        ],
        9 => [
            'science fiction', 'sci-fi', 'space', 'robot', 'alien', 'dystopia',
            'dystopian', 'future', 'galaxy', 'spacecraft',
            'خيال علمي', 'فضاء', 'روبوت', 'مستقبل', 'مجرة', 'ديستوبيا'
        ],
        10 => [
            'autobiography', 'biography', 'memoir', 'life story',
            'سيرة', 'ذاتية', 'مذكرات', 'حياة'
        ],
        11 => [
            'self help', 'motivation', 'success', 'leadership', 'productivity',
            'mindset', 'habits', 'personal development',
            'تطوير', 'نجاح', 'قيادة', 'إنتاجية', 'عادات', 'أهداف', 'تحفيز'
        ],
        12 => [
            'islam', 'quran', 'hadith', 'prophet', 'religious', 'faith',
            'إسلام', 'قرآن', 'حديث', 'نبي', 'دين', 'فقه', 'عقيدة', 'إيمان'
        ],
        13 => [
            'politics', 'economy', 'government', 'democracy', 'economics',
            'capitalism', 'socialism', 'policy',
            'سياسة', 'اقتصاد', 'حكومة', 'ديمقراطية', 'رأسمالية', 'نظام'
        ],
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
    
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return 'default_book.jpg';
    }
    
    $context = stream_context_create(['http' => [
        'timeout' => 10,
        'header' => 'User-Agent: Mozilla/5.0',
        'follow_location' => 1
    ]]);
    $image_data = @file_get_contents($url, false, $context);
    
    if (!$image_data || strlen($image_data) === 0) {
        return 'default_book.jpg';
    }
    
    // تحقق من نوع الصورة
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_buffer($finfo, $image_data);
    finfo_close($finfo);

    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
        return 'default_book.jpg';
    }
    
    $filename = 'cover_' . uniqid() . '.jpg';
    $path = '../assets/uploads/covers/' . $filename;
    
    if (@file_put_contents($path, $image_data)) {
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
        fgetcsv($file); // تخطي السطر الأول (headers)
        $count = 0;
        $errors = 0;

        try {
            while (($column = fgetcsv($file, 1000, ",")) !== FALSE) {
                if (count($column) < 2) { $errors++; continue; }

                $title   = trim($column[0]);
                $pdf_url = trim($column[1]);

                if (empty($title) || empty($pdf_url)) { $errors++; continue; }

                // تحقق من عدم وجود الكتاب مسبقاً
                $check_stmt = $pdo->prepare("SELECT id FROM books WHERE title = ? LIMIT 1");
                $check_stmt->execute([$title]);
                if ($check_stmt->fetch()) {
                    $csv_log[] = "⚠️ <b>$title</b> — الكتاب موجود مسبقاً";
                    $errors++;
                    continue;
                }

                $book_data = getBookData($title);

                if ($book_data) {
                    $author      = htmlspecialchars($book_data['author'], ENT_QUOTES, 'UTF-8');
                    $description = htmlspecialchars($book_data['description'], ENT_QUOTES, 'UTF-8');
                    $cover       = saveCoverFromUrl($book_data['cover'], $title);
                    $cat_text    = ($book_data['category'] ?? '') . ' ' . $description;
                    $csv_log[]   = "✅ <b>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</b> — تم جلب البيانات (المؤلف: $author)";
                } else {
                    $author      = 'غير معروف';
                    $description = '';
                    $cover       = 'default_book.jpg';
                    $cat_text    = $title;
                    $csv_log[]   = "⚠️ <b>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</b> — لم يُعثر على بيانات";
                }

                $cat_id = autoClassify($cat_text);

                $stmt = $pdo->prepare("INSERT INTO books (title, author, description, category_id, pdf_file, cover_image, created_at, downloads, views) 
                                       VALUES (?, ?, ?, ?, ?, ?, NOW(), 0, 0)");
                $stmt->execute([
                    htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                    $author,
                    $description,
                    $cat_id,
                    htmlspecialchars($pdf_url, ENT_QUOTES, 'UTF-8'),
                    $cover
                ]);
                $count++;
            }

            $csv_message = "<div class='alert alert-success'>✅ تمت العملية! تم إضافة <b>$count</b> كتاب" . 
                          ($errors > 0 ? " — تم تخطي <b>$errors</b> سطر" : "") . "</div>";

        } catch (Exception $e) {
            error_log($e->getMessage());
            $csv_message = "<div class='alert alert-danger'>❌ حدث خطأ أثناء المعالجة: " . htmlspecialchars($e->getMessage()) . "</div>";
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

        // تحقق من وجود المجلدات
        if (!is_dir($uploads_dir_pdf)) mkdir($uploads_dir_pdf, 0755, true);
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

            // تحقق من عدم وجود الكتاب مسبقاً
            $check_stmt = $pdo->prepare("SELECT id FROM books WHERE title = ? LIMIT 1");
            $check_stmt->execute([$title]);
            if ($check_stmt->fetch()) {
                $pdf_log[] = "⚠️ <b>" . htmlspecialchars($title) . "</b> — الكتاب موجود مسبقاً";
                $errors++;
                continue;
            }

            $pdf_new_name = uniqid('pdf_') . '.pdf';
            if (!move_uploaded_file($tmp_name, $uploads_dir_pdf . $pdf_new_name)) {
                $errors++;
                $pdf_log[] = "❌ <b>" . htmlspecialchars($title) . "</b> — فشل رفع الملف";
                continue;
            }

            $book_data = getBookData($title);

            if ($book_data) {
                $author      = htmlspecialchars($book_data['author'], ENT_QUOTES, 'UTF-8');
                $description = htmlspecialchars($book_data['description'], ENT_QUOTES, 'UTF-8');
                $cover       = saveCoverFromUrl($book_data['cover'], $title);
                $cat_text    = ($book_data['category'] ?? '') . ' ' . $description;
                $pdf_log[]   = "✅ <b>" . htmlspecialchars($title) . "</b> — تم جلب البيانات (المؤلف: $author)";
            } else {
                $author      = 'غير معروف';
                $description = '';
                $cover       = 'default_book.jpg';
                $cat_text    = $title;
                $pdf_log[]   = "⚠️ <b>" . htmlspecialchars($title) . "</b> — لم يُعثر على بيانات";
            }

            $cat_id = autoClassify($cat_text);

            try {
                $stmt = $pdo->prepare("INSERT INTO books (title, author, description, category_id, pdf_file, cover_image, created_at, downloads, views) 
                                       VALUES (?, ?, ?, ?, ?, ?, NOW(), 0, 0)");
                $stmt->execute([
                    htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                    $author,
                    $description,
                    $cat_id,
                    $pdf_new_name,
                    $cover
                ]);
                $count++;
            } catch (Exception $e) {
                $pdf_log[] = "❌ خطأ قاعدة البيانات: " . htmlspecialchars($e->getMessage());
                $errors++;
            }
        }

        $pdf_message = "<div class='alert alert-success'>✅ تمت العملية! تم إضافة <b>$count</b> كتاب" . 
                      ($errors > 0 ? " — فشل <b>$errors</b> ملف" : "") . "</div>";
    }
}

// ============================================
// معالجة Textarea
// ============================================
$textarea_message = "";
if (isset($_POST["import_textarea"])) {
    $textarea_content = trim($_POST["textarea_input"] ?? '');
    
    if (empty($textarea_content)) {
        $textarea_message = "<div class='alert alert-danger'>❌ الحقل فارغ!</div>";
    } else {
        $lines = explode("\n", $textarea_content);
        $added = 0;
        $errors = 0;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO import_queue (title, pdf_url, import_type, status) 
                VALUES (?, ?, 'textarea', 'pending')
            ");

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                $parts = explode('|', $line);
                if (count($parts) < 2) {
                    $errors++;
                    continue;
                }

                $title = trim($parts[0]);
                $pdf_url = trim($parts[1]);

                if (empty($title) || empty($pdf_url)) {
                    $errors++;
                    continue;
                }

                // تحقق من عدم وجود الكتاب مسبقاً
                $check_stmt = $pdo->prepare("SELECT id FROM books WHERE title = ? LIMIT 1");
                $check_stmt->execute([$title]);
                if ($check_stmt->fetch()) {
                    $errors++;
                    continue;
                }

                $stmt->execute([$title, $pdf_url]);
                $added++;
            }

            if ($added > 0) {
                $textarea_message = "<div class='alert alert-info'>✅ تمت إضافة <b>$added</b> كتاب إلى الطابور!<br><small>سيتم معالجتها في الخلفية تلقائياً</small></div>";
            } else {
                $textarea_message = "<div class='alert alert-warning'>⚠️ لم يتم إضافة أي كتاب" . ($errors > 0 ? " (تم تخطي $errors سطر)" : "") . "</div>";
            }

        } catch (Exception $e) {
            $textarea_message = "<div class='alert alert-danger'>❌ خطأ: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// ============================================
// عرض حالة الطابور
// ============================================
try {
    $queue_status = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
            SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as done,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
        FROM import_queue 
        WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
    ")->fetch();
} catch (Exception $e) {
    $queue_status = [
        'total' => 0,
        'pending' => 0,
        'processing' => 0,
        'done' => 0,
        'failed' => 0
    ];
}








// تشغيل Worker في الخلفية

if (isset($_POST["import_textarea"]) || isset($_POST["import_csv"]) || isset($_POST["import_pdfs"])) {
    // بعد إضافة الكتب مباشرة، شغّل Worker
    $command = 'start /b C:\xampp\php\php.exe C:\xampp\htdocs\library\admin\worker.php process > nul 2>&1';
    shell_exec($command);
}




?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الرفع الجماعي الذكي - لوحة الإدارة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <style>
        body { 
            background-color: #f4f7f6; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
        }
        .sidebar { 
            background: linear-gradient(135deg, #5d78ff 0%, #4c5ce8 100%);
            min-height: 100vh; 
            padding: 20px; 
            color: white; 
            position: fixed; 
            right: 0; 
            top: 0; 
            width: 220px; 
            z-index: 1000; 
            box-shadow: -5px 0 15px rgba(0,0,0,0.1);
        }
        .sidebar h4 {
            font-weight: bold;
            letter-spacing: 1px;
        }
        .sidebar a { 
            color: white; 
            display: block; 
            padding: 12px; 
            text-decoration: none; 
            margin-bottom: 5px; 
            border-radius: 5px; 
            transition: 0.3s;
        }
        .sidebar a:hover { 
            background-color: rgba(255,255,255,0.2); 
            transform: translateX(-5px);
        }
        .btn-bulk { 
            background-color: #ff4b5c; 
            color: white; 
            border: none; 
            font-weight: bold;
        }
        .btn-bulk:hover {
            background-color: #e63946;
            color: white;
        }
        .main-content { 
            margin-right: 220px; 
            padding: 40px; 
        }
        .card { 
            border: none; 
            border-radius: 12px;
            transition: 0.3s;
        }
        .card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        .upload-zone { 
            border: 2px dashed #5d78ff; 
            border-radius: 15px; 
            background: #f8f9ff; 
            padding: 40px; 
            text-align: center; 
            transition: 0.3s; 
            cursor: pointer;
        }
        .upload-zone:hover { 
            background: #eef0ff;
            border-color: #4c5ce8;
        }
        .log-box { 
            background: #1e1e2e; 
            color: #cdd6f4; 
            border-radius: 10px; 
            padding: 20px; 
            max-height: 300px; 
            overflow-y: auto; 
            font-family: monospace; 
            font-size: 0.9em; 
            border-left: 4px solid #5d78ff;
        }
        .section-title { 
            border-right: 4px solid #5d78ff; 
            padding-right: 10px; 
            margin-bottom: 20px;
            font-weight: 600;
        }
        .queue-badge {
            display: inline-block;
            padding: 8px 15px;
            margin: 5px 5px 5px 0;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.9em;
        }
        .alert-box {
            border-radius: 10px;
            border-left: 4px solid;
        }
    </style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
    <h4 class="mb-5 text-center">📚 مكتبة الثقافة</h4>
    <a href="dashboard.php"><i class="fas fa-home me-2"></i> لوحة التحكم</a>
    <a href="add_book.php"><i class="fas fa-plus me-2"></i> إضافة كتاب</a>
    <a href="bulk_upload.php" class="btn-bulk mt-2"><i class="fas fa-bolt me-2"></i> الرفع الذكي</a>
    <hr style="border-color: rgba(255,255,255,0.1); margin: 20px 0;">
    <a href="logout.php" class="text-warning"><i class="fas fa-sign-out-alt me-2"></i> خروج</a>
</div>

<!-- Main Content -->
<div class="main-content">
    <div class="container-fluid">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-5">
            <div>
                <h2>🚀 الرفع الجماعي الذكي</h2>
                <small class="text-muted">نظام متقدم للرفع والمعالجة التلقائية</small>
            </div>
            <div class="badge bg-primary p-3 fs-6">
                <i class="fas fa-cloud-upload-alt me-2"></i>
                يعمل بـ Google Books + Open Library
            </div>
        </div>

        <!-- Queue Status -->
        <div class="card shadow-sm mb-5 alert-box" style="border-left-color: #0d6efd;">
            <div class="card-body">
                <h6 class="mb-3">📊 حالة الطابور</h6>
                <div>
                    <span class="queue-badge" style="background: #3498db; color: white;">
                        <i class="fas fa-box me-1"></i> إجمالي: <b><?= $queue_status['total'] ?? 0 ?></b>
                    </span>
                    <span class="queue-badge" style="background: #f39c12; color: white;">
                        <i class="fas fa-hourglass-half me-1"></i> معلق: <b><?= $queue_status['pending'] ?? 0 ?></b>
                    </span>
                    <span class="queue-badge" style="background: #e74c3c; color: white;">
                        <i class="fas fa-spinner me-1"></i> قيد: <b><?= $queue_status['processing'] ?? 0 ?></b>
                    </span>
                    <span class="queue-badge" style="background: #27ae60; color: white;">
                        <i class="fas fa-check me-1"></i> مكتمل: <b><?= $queue_status['done'] ?? 0 ?></b>
                    </span>
                    <span class="queue-badge" style="background: #c0392b; color: white;">
                        <i class="fas fa-times me-1"></i> فشل: <b><?= $queue_status['failed'] ?? 0 ?></b>
                    </span>
                </div>
            </div>
        </div>

        <!-- Section 1: CSV -->
        <div class="card shadow-sm mb-5">
            <div class="card-header py-3 bg-light">
                <h5 class="mb-0 section-title">
                    <i class="fas fa-file-csv text-success me-2"></i>
                    القسم الأول — رفع CSV (عنوان + رابط PDF)
                </h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info alert-box">
                    <b>📋 كيفية الاستخدام:</b><br>
                    1. افتح Notepad أو Excel وأنشئ عمودين: <b>title</b> و <b>pdf_url</b><br>
                    2. ضع عنوان كل كتاب في العمود الأول ورابط PDF في الثاني<br>
                    3. احفظ الملف بصيغة CSV وارفعه هنا<br>
                    <small class="text-muted">✨ النظام سيجلب تلقائياً: المؤلف + الوصف + الغلاف + التصنيف</small>
                </div>

                <?php echo $csv_message; ?>
                <?php if (!empty($csv_log)): ?>
                    <div class="log-box mb-3">
                        <?php foreach ($csv_log as $log): ?>
                            <div class="mb-2"><?php echo $log; ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form action="" method="post" enctype="multipart/form-data">
                    <div class="upload-zone mb-3">
                        <i class="fas fa-file-csv fa-3x text-success mb-3"></i>
                        <h5>اختر ملف CSV</h5>
                        <p class="text-muted small">العمود الأول: عنوان | العمود الثاني: رابط PDF</p>
                        <input type="file" name="csv_file" accept=".csv" class="form-control mt-3" required>
                    </div>
                    <button type="submit" name="import_csv" class="btn btn-success btn-lg w-100">
                        <i class="fas fa-magic me-2"></i> ابدأ المعالجة الذكية
                    </button>
                </form>
            </div>
        </div>

        <!-- Section 2: PDF Files -->
        <div class="card shadow-sm mb-5">
            <div class="card-header py-3 bg-light">
                <h5 class="mb-0 section-title">
                    <i class="fas fa-file-pdf text-danger me-2"></i>
                    القسم الثاني — رفع ملفات PDF من جهازك
                </h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info alert-box">
                    <b>📁 كيفية الاستخدام:</b><br>
                    1. اختر عدة ملفات PDF من جهازك دفعة واحدة<br>
                    2. تأكد أن اسم كل ملف هو اسم الكتاب (مثال: <b>الجريمة والعقاب.pdf</b>)<br>
                    3. اضغط رفع — النظام سيجلب البيانات تلقائياً<br>
                    <small class="text-muted">⚠️ الحد الأقصى لكل ملف: 50MB</small>
                </div>

                <?php echo $pdf_message; ?>
                <?php if (!empty($pdf_log)): ?>
                    <div class="log-box mb-3">
                        <?php foreach ($pdf_log as $log): ?>
                            <div class="mb-2"><?php echo $log; ?></div>
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

        <!-- Section 3: Textarea -->
        <div class="card shadow-sm mb-5">
            <div class="card-header py-3 bg-light">
                <h5 class="mb-0 section-title">
                    <i class="fas fa-paste text-info me-2"></i>
                    القسم الثالث — الصق البيانات مباشرة (سريع جداً!)
                </h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info alert-box">
                    <b>⚡ الصيغة السريعة:</b> في كل سطر: <code>عنوان الكتاب | رابط PDF</code><br>
                    <b>مثال:</b><br>
                    <code>الجريمة والعقاب | https://example.com/book1.pdf<br>
                    1984 | https://example.com/book2.pdf<br>
                    كتاب جديد | https://example.com/book3.pdf</code><br><br>
                    <small class="text-muted">💡 هذه الطريقة تضيف الكتب إلى طابور المعالجة بدون توقف الواجهة</small>
                </div>

                <?php echo $textarea_message; ?>

                <form action="" method="post">
                    <textarea name="textarea_input" class="form-control mb-3" rows="8" 
                              placeholder="عنوان الكتاب | رابط PDF&#10;الجريمة والعقاب | https://example.com/book1.pdf&#10;1984 | https://example.com/book2.pdf"></textarea>
                    <button type="submit" name="import_textarea" class="btn btn-info btn-lg w-100">
                        <i class="fas fa-bolt me-2"></i> أضف إلى الطابور (معالجة فورية)
                    </button>
                </form>

                <div class="alert alert-warning mt-3 alert-box" style="border-left-color: #ffc107;">
                    <small>
                        <i class="fas fa-info-circle me-2"></i>
                        <b>ملاحظة مهمة:</b> الكتب المضافة هنا ستُعالج تلقائياً بواسطة Worker بدون انتظار. 
                        تحقق من حالة الطابور أعلاه لمتابعة التقدم.
                    </small>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // تحديث حالة الطابور كل 30 ثانية (اختياري)
    // setInterval(() => location.reload(), 30000);
</script>

</body>
</html>