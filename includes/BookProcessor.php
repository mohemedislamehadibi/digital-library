<?php

// استدعاء اجباري لملف تصنيف الكتب
require_once __DIR__ . '/MLClassifier.php';

class BookProcessor
{
    private $pdo;
    private $queue_id;
    private $ml;

    public function __construct($pdo, $queue_id = null)
    {
        $this->pdo      = $pdo;
        $this->queue_id = $queue_id;
        $this->ml       = new MLClassifier();

        if ($this->ml->isAvailable()) {
            $this->log("✅ نموذج ML متاح", 'info');
        } else {
            $this->log("⚠️ نموذج ML غير متاح — سيُستخدم Keyword Scoring", 'warning');
        }
    }

    
    public function smartClassify(string $title, string $description = '', ?string $pdf_path = null): int
    {
        // الطبقة الأولى: نص PDF
        if ($pdf_path) {
            $pdf_text = $this->extractTextFromPdf($pdf_path);
            if (!empty(trim($pdf_text))) {
                $cat_id = $this->classifyText($title, $pdf_text);
                $this->log("📄 تصنيف من نص PDF → تصنيف $cat_id", 'success');
                return $cat_id;
            }
        }

        // الطبقة الثانية: ML
        if ($this->ml->isAvailable()) {
            $result = $this->ml->predict($title, $description);
            $conf   = $result['confidence'] ?? 0;

            if ($conf >= 0.30) {
                $this->log(
                    "🤖 ML: {$result['category_name']} (ثقة: " . round($conf * 100) . "%)",
                    'success'
                );
                return $result['category_id'];
            }

            if ($conf >= 0.15) {
                $kw_cat = $this->autoClassify($title . ' ' . $description);
                if ($result['category_id'] === $kw_cat) {
                    $this->log(
                        "🤖+🔑 ML وKeywords متفقان: {$result['category_name']}",
                        'success'
                    );
                    return $result['category_id'];
                }
                $this->log(
                    "🤖 ML (اختلف مع Keywords): {$result['category_name']} vs تصنيف $kw_cat",
                    'info'
                );
                return $result['category_id'];
            }

            $this->log("⚠️ ML غير واثق ({$conf}) → Keyword Scoring", 'warning');
        }

        // الطبقة الثالثة: Keyword Scoring (autoClassify)
        $cat_id = $this->autoClassify($title . ' ' . $description);
        $this->log("🔑 Keyword Scoring → تصنيف $cat_id", 'info');
        return $cat_id;
    }

    
    private function classifyText(string $title, string $text): int
    {
        if ($this->ml->isAvailable()) {
            $snippet = mb_substr($text, 0, 500, 'UTF-8');
            $result  = $this->ml->predict($title, $snippet);
            if (($result['confidence'] ?? 0) >= 0.25) {
                return $result['category_id'];
            }
        }
        return $this->autoClassify($text);
    }

    
    public function autoClassify($text): int
    {
        $text = mb_strtolower($text, 'UTF-8');
        $scores = [
            1  => ['software', 'programming', 'java', 'sql', 'code', 'python', 'database', 'javascript', 'algorithms', 'computer', 'coding', 'برمجة', 'كود', 'حاسوب', 'تقنية', 'بيانات', 'شبكات', 'ذكاء اصطناعي'],
            2  => ['history', 'war', 'ancient', 'century', 'battles', 'civilization', 'empire', 'historical', 'medieval', 'ottoman', 'roman', 'تاريخ', 'حرب', 'حضارة', 'قرن', 'معركة', 'دولة', 'خلافة', 'عثماني'],
            3  => ['math', 'physics', 'calculus', 'science', 'mathematics', 'algebra', 'chemistry', 'biology', 'astronomy', 'universe', 'quantum', 'علوم', 'فيزياء', 'رياضيات', 'كيمياء', 'أحياء', 'طب', 'هندسة', 'فلك'],
            4  => ['novel', 'story', 'drama', 'classic', 'literature', 'poetry', 'prose', 'narrative', 'tale', 'رواية', 'قصة', 'أدب', 'شعر', 'ديوان', 'مسرحية', 'حكاية', 'سيرة', 'نثر'],
            5  => ['general', 'عام'],
            6  => ['fantasy', 'magic', 'dragon', 'wizard', 'witch', 'spell', 'mythical', 'فانتازيا', 'سحر', 'تنين', 'ساحر', 'خيال', 'أسطورة', 'مملكة'],
            7  => ['horror', 'scary', 'ghost', 'haunted', 'terror', 'nightmare', 'demon', 'vampire', 'zombie', 'evil', 'darkness', 'رعب', 'مخيف', 'شبح', 'ظلام', 'خوف', 'وحش', 'مسكون'],
            8  => ['mystery', 'thriller', 'detective', 'crime', 'murder', 'suspense', 'investigation', 'sherlock', 'spy', 'secret', 'غموض', 'تشويق', 'محقق', 'جريمة', 'قتل', 'سر', 'تحقيق', 'جاسوس'],
            9  => ['science fiction', 'sci-fi', 'space', 'robot', 'alien', 'dystopia', 'dystopian', 'future', 'galaxy', 'spacecraft', 'خيال علمي', 'فضاء', 'روبوت', 'مستقبل', 'مجرة', 'ديستوبيا'],
            10 => ['autobiography', 'biography', 'memoir', 'life story', 'سيرة', 'ذاتية', 'مذكرات', 'حياة'],
            11 => ['self help', 'motivation', 'success', 'leadership', 'productivity', 'mindset', 'habits', 'personal development', 'تطوير', 'نجاح', 'قيادة', 'إنتاجية', 'عادات', 'أهداف', 'تحفيز'],
            12 => ['islam', 'quran', 'hadith', 'prophet', 'religious', 'faith', 'إسلام', 'قرآن', 'حديث', 'نبي', 'دين', 'فقه', 'عقيدة', 'إيمان'],
            13 => ['politics', 'economy', 'government', 'democracy', 'economics', 'capitalism', 'socialism', 'policy', 'سياسة', 'اقتصاد', 'حكومة', 'ديمقراطية', 'رأسمالية', 'نظام'],
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
        return $results[$best] > 0 ? (int)$best : 5;
    }

//   الدالة الرايسية للمعالجة
    public function processBook($title, $pdf_url = null, $pdf_path = null, $isbn = null)
    {
        try {
            if (empty($title)) {
                $this->log("العنوان فارغ", 'error');
                return null;
            }

            $stmt = $this->pdo->prepare("SELECT id FROM books WHERE title = ? LIMIT 1");
            $stmt->execute([$title]);
            if ($stmt->fetch()) {
                $this->log("الكتاب موجود مسبقاً: $title", 'warning');
                return null;
            }

            $book_data = $this->getBookDataWithCache($title, $isbn);

            $author      = 'غير معروف';
            $description = '';
            $cover       = 'default_book.jpg';

            if ($book_data) {
               
                $author      = $book_data['author']      ?? 'غير معروف';
                $description = $book_data['description'] ?? '';
                $cover       = $this->saveCover($book_data['cover'] ?? null, $title);
            }

            $local_pdf = $pdf_path && file_exists($pdf_path) ? $pdf_path : null;
            $cat_id    = $this->smartClassify($title, $description, $local_pdf);

            $stmt = $this->pdo->prepare("
                INSERT INTO books
                (title, author, description, category_id,
                 pdf_file, cover_image, created_at, downloads, views)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), 0, 0)
            ");
            $stmt->execute([
                $title,
                $author,
                $description,
                $cat_id,
                $pdf_url ?? $pdf_path ?? '',
                $cover,
            ]);

            $book_id = $this->pdo->lastInsertId();
            $this->log("✅ تمت إضافة: $title (ID: $book_id)", 'success');
            return $book_id;
        } catch (Exception $e) {
            error_log('[BookProcessor::processBook] ' . $e->getMessage());   // #3
            $this->log("خطأ أثناء معالجة الكتاب", 'error');
            return null;
        }
    }

    
    // getBookDataWithCache — جلب بيانات الكتاب من الكاش أو API
    
    public function getBookDataWithCache($title, $isbn = null)
    {
        $search_key  = $isbn ?: $title;
        $search_type = $isbn ? 'isbn' : 'title';

        try {
            $stmt = $this->pdo->prepare("
                SELECT data FROM books_cache
                WHERE search_key = ? AND search_type = ? AND expires_at > NOW()
            ");
            $stmt->execute([$search_key, $search_type]);
            $cache = $stmt->fetch();
            if ($cache) {
                $this->log("كاش موجود لـ: $title", 'success');
                return json_decode($cache['data'], true);
            }
        } catch (Exception $e) {
            error_log('[BookProcessor::getBookDataWithCache cache-read] ' . $e->getMessage());
            $this->log("خطأ قراءة الكاش", 'error');
        }

        $data = null;
        if ($isbn) {
            $data = $this->searchGoogleBooksByISBN($isbn);
        } else {
            $data = $this->searchGoogleBooks($title);
            if (!$data) $data = $this->searchOpenLibrary($title);
        }

        if ($data) {
            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO books_cache (search_key, search_type, api_source, data)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE data = VALUES(data), created_at = NOW()
                ");
                $stmt->execute([
                    $search_key,
                    $search_type,
                    $data['api_source'] ?? 'unknown',
                    json_encode($data)
                ]);
            } catch (Exception $e) {
                error_log('[BookProcessor::getBookDataWithCache cache-write] ' . $e->getMessage());
                $this->log("خطأ حفظ الكاش", 'warning');
            }
        }

        return $data;
    }

    
    // دوال البحث في APIs
    
    private function searchGoogleBooksByISBN($isbn)
    {
        $url  = "https://www.googleapis.com/books/v1/volumes?q=isbn:{$isbn}";
        $data = $this->fetchAPI($url);
        if (!$data || empty($data['items'])) return null;
        $book = $data['items'][0]['volumeInfo'];
        return [
            'author'      => isset($book['authors']) ? implode(', ', $book['authors']) : 'غير معروف',
            'description' => isset($book['description']) ? substr(strip_tags($book['description']), 0, 800) : '',
            'cover'       => isset($book['imageLinks']['thumbnail'])
                ? str_replace('http://', 'https://', $book['imageLinks']['thumbnail']) : null,
            'category'    => isset($book['categories']) ? $book['categories'][0] : '',
            'api_source'  => 'google_books_isbn',
        ];
    }

    private function searchGoogleBooks($title)
    {
        $query = urlencode($title);
        $url   = "https://www.googleapis.com/books/v1/volumes?q={$query}&maxResults=1";
        $data  = $this->fetchAPI($url);
        if (!$data || empty($data['items'])) return null;
        $book = $data['items'][0]['volumeInfo'];
        return [
            'author'      => isset($book['authors']) ? implode(', ', $book['authors']) : 'غير معروف',
            'description' => isset($book['description']) ? substr(strip_tags($book['description']), 0, 800) : '',
            'cover'       => isset($book['imageLinks']['thumbnail'])
                ? str_replace('http://', 'https://', $book['imageLinks']['thumbnail']) : null,
            'category'    => isset($book['categories']) ? $book['categories'][0] : '',
            'api_source'  => 'google_books',
        ];
    }

    private function searchOpenLibrary($title)
    {
        $query = urlencode($title);
        $url   = "https://openlibrary.org/search.json?title={$query}&limit=1&fields=title,author_name,first_sentence,subject,cover_i";
        $data  = $this->fetchAPI($url);
        if (!$data || empty($data['docs'])) return null;
        $book = $data['docs'][0];
        $description = '';
        if (isset($book['first_sentence'])) {
            $description = is_array($book['first_sentence'])
                ? implode(' ', array_slice($book['first_sentence'], 0, 3))
                : $book['first_sentence'];
            $description = substr($description, 0, 800);
        }
        $category = '';
        if (isset($book['subject']) && is_array($book['subject'])) {
            $category = implode(' ', array_slice($book['subject'], 0, 5));
        }
        return [
            'author'      => isset($book['author_name']) ? implode(', ', $book['author_name']) : 'غير معروف',
            'description' => $description,
            'cover'       => isset($book['cover_i'])
                ? "https://covers.openlibrary.org/b/id/{$book['cover_i']}-L.jpg" : null,
            'category'    => $category,
            'api_source'  => 'openlibrary',
        ];
    }

    private function fetchAPI($url)
    {
        $context  = stream_context_create(['http' => [
            'timeout'         => 8,
            'header'          => 'User-Agent: Mozilla/5.0',
            'follow_location' => 1,
        ]]);
        $response = @file_get_contents($url, false, $context);
        if (!$response) {
            $this->log("فشل الوصول إلى API", 'error');
            return null;
        }
        return json_decode($response, true);
    }

   
    // extractTextFromPdf — استخراج نص PDF عبر pdftotext
   
    public function extractTextFromPdf($pdf_path): string
    {
        if (!file_exists($pdf_path)) {
            $this->log("الملف غير موجود: $pdf_path", 'warning');
            return '';
        }
        $escaped = escapeshellarg($pdf_path);
        $text    = @shell_exec("pdftotext $escaped - 2>nul");
        if (!$text || strlen(trim($text)) === 0) {
            $this->log("pdftotext لم يُرجع نصاً", 'warning');
            return '';
        }
        return substr($text, 0, 2000);
    }

   
    public function buildClassificationText($book_data, $pdf_path = null): string
    {
        $pdf_text = '';
        if ($pdf_path) $pdf_text = $this->extractTextFromPdf($pdf_path);
        if (!empty($pdf_text)) {
            $this->log("التصنيف بناءً على نص PDF", 'success');
            return $pdf_text;
        }
        $api_text = ($book_data['category'] ?? '') . ' ' . ($book_data['description'] ?? '');
        if (!empty(trim($api_text))) {
            $this->log("التصنيف بناءً على وصف API", 'info');
            return $api_text;
        }
        $this->log("لا نص متاح", 'warning');
        return '';
    }

    
    // saveCover — حفظ صورة الغلاف من رابط خارجي
    
    public function saveCover($url, $title): string
    {
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            $this->log("رابط غلاف غير صالح", 'warning');
            return 'default_book.jpg';
        }
        $context    = stream_context_create(['http' => ['timeout' => 10, 'header' => 'User-Agent: Mozilla/5.0', 'follow_location' => 1]]);
        $image_data = @file_get_contents($url, false, $context);
        if (!$image_data || strlen($image_data) === 0) {
            $this->log("فشل تحميل الغلاف", 'warning');
            return 'default_book.jpg';
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_buffer($finfo, $image_data);
        finfo_close($finfo);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
            $this->log("نوع صورة غير مدعوم: $mime", 'warning');
            return 'default_book.jpg';
        }
        $filename = 'cover_' . uniqid() . '.jpg';
        $path     = '../assets/uploads/covers/' . $filename;
        if (@file_put_contents($path, $image_data)) {
            $this->log("تم حفظ الغلاف: $filename", 'success');
            return $filename;
        }
        return 'default_book.jpg';
    }

   
    // processQueue — معالجة قائمة انتظار الاستيراد
   
    public function processQueue($limit = 5): int
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM import_queue
            WHERE status = 'pending'
            ORDER BY created_at ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        $jobs = $stmt->fetchAll();
        if (empty($jobs)) return 0;
        $count = 0;
        foreach ($jobs as $job) {
            $this->pdo->prepare("UPDATE import_queue SET status = 'processing' WHERE id = ?")->execute([$job['id']]);
            $this->queue_id = $job['id'];
            try {
                $book_id = $this->processBook($job['title'], $job['pdf_url'], null);
                if ($book_id) {
                    $this->pdo->prepare("UPDATE import_queue SET status = 'done', book_id = ? WHERE id = ?")->execute([$book_id, $job['id']]);
                    $count++;
                } else {
                    $this->pdo->prepare("UPDATE import_queue SET status = 'failed' WHERE id = ?")->execute([$job['id']]);
                }
            } catch (Exception $e) {
                error_log('[BookProcessor::processQueue] ' . $e->getMessage());
                $this->log("خطأ في معالجة الطابور", 'error');
                $this->pdo->prepare("UPDATE import_queue SET status = 'failed' WHERE id = ?")->execute([$job['id']]);
            }
        }
        $this->queue_id = null;
        return $count;
    }

   
    // getQueueStats — إحصائيات قائمة الانتظار
    
    public function getQueueStats()
    {
        return $this->pdo->query("
            SELECT COUNT(*) as total,
                SUM(CASE WHEN status='pending'    THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status='processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status='done'       THEN 1 ELSE 0 END) as done,
                SUM(CASE WHEN status='failed'     THEN 1 ELSE 0 END) as failed
            FROM import_queue
        ")->fetch();
    }

   
    // getQueueLogs — سجلات الاستيراد لمهمة محددة
   
    public function getQueueLogs($queue_id)
    {
        $stmt = $this->pdo->prepare("SELECT log_type, message, created_at FROM import_logs WHERE queue_id = ? ORDER BY created_at ASC");
        $stmt->execute([$queue_id]);
        return $stmt->fetchAll();
    }

   
    // log — تسجيل الرسائل في import_logs
   
    private function log($message, $type = 'info'): void
    {
        if ($this->queue_id) {
            try {
                $stmt = $this->pdo->prepare("INSERT INTO import_logs (queue_id, log_type, message) VALUES (?, ?, ?)");
                $stmt->execute([$this->queue_id, $type, $message]);
            } catch (Exception $e) {
               
            }
        }
    }
}