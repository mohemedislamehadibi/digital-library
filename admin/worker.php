
<?php
// ============================================
// Worker - معالج الطابور الخلفي
// ============================================

require_once '../includes/db.php';

set_time_limit(300);

class ImportWorker {
    private $pdo;
    private $batch_size = 5;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    // ============================================
    // معالجة الوظائف المعلقة
    // ============================================
    public function processPendingJobs() {
        try {
            $batch_size = intval($this->batch_size);
            
            $stmt = $this->pdo->query("
                SELECT id, title, pdf_url, pdf_path, import_type 
                FROM import_queue 
                WHERE status = 'pending' 
                ORDER BY created_at ASC 
                LIMIT $batch_size
            ");
            
            $jobs = $stmt->fetchAll();

            if (empty($jobs)) {
                $this->log_worker("✓ لا توجد وظائف معلقة");
                return 0;
            }

            $processed = 0;
            foreach ($jobs as $job) {
                $this->processJob($job);
                $processed++;
            }

            $this->log_worker("✓ تمت معالجة {$processed} وظيفة");
            return $processed;

        } catch (Exception $e) {
            $this->log_worker("✗ خطأ: " . $e->getMessage());
            return 0;
        }
    }

    // ============================================
    // معالجة وظيفة واحدة
    // ============================================
    private function processJob($job) {
        $queue_id = $job['id'];
        $title = $job['title'];

        try {
            // 1️⃣ تحديث الحالة إلى "processing"
            $stmt = $this->pdo->prepare("
                UPDATE import_queue 
                SET status = 'processing' 
                WHERE id = ?
            ");
            $stmt->execute([$queue_id]);

            // 2️⃣ جلب البيانات من Google Books
            $book_data = $this->getBookData($title);

            $author = 'غير معروف';
            $description = '';
            $cover = 'default_book.jpg';

            if ($book_data) {
                $author = htmlspecialchars($book_data['author'], ENT_QUOTES, 'UTF-8');
                $description = htmlspecialchars($book_data['description'], ENT_QUOTES, 'UTF-8');
                $cover = $this->saveCoverFromUrl($book_data['cover'], $title);
                $cat_text = ($book_data['category'] ?? '') . ' ' . $description;
                $this->log_worker("✅ تم جلب البيانات: $title");
            } else {
                $cat_text = $title;
                $this->log_worker("⚠️ لم يُعثر على بيانات: $title");
            }

            // 3️⃣ التصنيف الآلي
            $cat_id = $this->autoClassify($cat_text);

            // 4️⃣ حفظ الكتاب في قاعدة البيانات
            $stmt = $this->pdo->prepare("
                INSERT INTO books (
                    title, author, description, category_id, 
                    pdf_file, cover_image, created_at, downloads, views
                ) 
                VALUES (?, ?, ?, ?, ?, ?, NOW(), 0, 0)
            ");

            $pdf_file = $job['pdf_path'] ?? $job['pdf_url'];
            $stmt->execute([
                htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                $author,
                $description,
                $cat_id,
                htmlspecialchars($pdf_file, ENT_QUOTES, 'UTF-8'),
                $cover
            ]);

            $book_id = $this->pdo->lastInsertId();

            // 5️⃣ تحديث الطابور بـ status = done
            $stmt = $this->pdo->prepare("
                UPDATE import_queue 
                SET status = 'done', book_id = ?, processed_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$book_id, $queue_id]);

            $this->log_worker("✓ تمت معالجة: $title (ID: $book_id)");

        } catch (Exception $e) {
            $stmt = $this->pdo->prepare("
                UPDATE import_queue 
                SET status = 'failed', error_message = ?, processed_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$e->getMessage(), $queue_id]);
            
            $this->log_worker("✗ فشلت معالجة: $title - " . $e->getMessage());
        }
    }

    // ============================================
    // البحث في Google Books
    // ============================================
    private function searchGoogleBooks($title) {
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
    private function searchOpenLibrary($title) {
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
    // جلب بيانات الكتاب (يجرب مصدرين)
    // ============================================
    private function getBookData($title) {
        $result = $this->searchGoogleBooks($title);
        if ($result) return $result;
        
        $result = $this->searchOpenLibrary($title);
        if ($result) return $result;
        
        return null;
    }

    // ============================================
    // التصنيف الآلي
    // ============================================
    private function autoClassify($text) {
        $text = mb_strtolower($text, 'UTF-8');
        $scores = [
            1 => ['software', 'programming', 'java', 'sql', 'code', 'python', 'برمجة', 'كود', 'حاسوب'],
            2 => ['history', 'war', 'ancient', 'تاريخ', 'حرب', 'حضارة'],
            3 => ['math', 'physics', 'science', 'علوم', 'فيزياء', 'رياضيات'],
            4 => ['novel', 'story', 'literature', 'رواية', 'قصة', 'أدب', 'شعر'],
            5 => ['general', 'عام'],
            6 => ['fantasy', 'magic', 'فانتازيا', 'سحر'],
            7 => ['horror', 'scary', 'رعب', 'مخيف'],
            8 => ['mystery', 'detective', 'crime', 'غموض', 'محقق', 'جريمة'],
            9 => ['science fiction', 'space', 'robot', 'خيال علمي'],
            10 => ['autobiography', 'biography', 'memoir', 'سيرة', 'مذكرات'],
            11 => ['self help', 'motivation', 'success', 'تطوير', 'نجاح'],
            12 => ['islam', 'quran', 'hadith', 'إسلام', 'قرآن', 'حديث'],
            13 => ['politics', 'economy', 'government', 'سياسة', 'اقتصاد'],
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
    // حفظ الغلاف من URL
    // ============================================
    private function saveCoverFromUrl($url, $title) {
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
    // تسجيل السجلات
    // ============================================
    private function log_worker($message) {
        $log_file = __DIR__ . '/worker_log.txt';
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[$timestamp] $message\n";
        file_put_contents($log_file, $log_message, FILE_APPEND);
        echo $log_message;
    }
}

// ============================================
// تشغيل من سطر الأوامر
// ============================================
if (php_sapi_name() === 'cli') {
    try {
        $worker = new ImportWorker($pdo);
        
        $option = $argv[1] ?? 'process';

        switch ($option) {
            case 'process':
                $worker->processPendingJobs();
                break;
            default:
                echo "الخيارات:\n";
                echo "  php worker.php process   — معالجة الوظائف المعلقة\n";
        }
    } catch (Exception $e) {
        echo "❌ خطأ: " . $e->getMessage() . "\n";
    }
}
?>