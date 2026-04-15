
<?php
class BookProcessor {
    private $pdo;
    private $queue_id;

    public function __construct($pdo, $queue_id = null) {
        $this->pdo = $pdo;
        $this->queue_id = $queue_id;
    }

    // ============================================
    // البحث مع الكاش
    // ============================================
    public function getBookDataWithCache($title, $isbn = null) {
        $search_key = $isbn ?: $title;
        $search_type = $isbn ? 'isbn' : 'title';

        // تحقق من الكاش أولاً
        $stmt = $this->pdo->prepare("
            SELECT data FROM books_cache 
            WHERE search_key = ? AND search_type = ? AND expires_at > NOW()
        ");
        $stmt->execute([$search_key, $search_type]);
        $cache = $stmt->fetch();

        if ($cache) {
            $this->log("تم استخدام الكاش", 'success');
            return json_decode($cache['data'], true);
        }

        // جلب من APIs
        $data = null;
        
        if ($isbn) {
            $data = $this->searchGoogleBooksByISBN($isbn);
        } else {
            $data = $this->searchGoogleBooks($title);
            if (!$data) $data = $this->searchOpenLibrary($title);
        }

        // احفظ في الكاش
        if ($data) {
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
        }

        return $data;
    }

    // ============================================
    // البحث عن طريق ISBN
    // ============================================
    private function searchGoogleBooksByISBN($isbn) {
        $url = "https://www.googleapis.com/books/v1/volumes?q=isbn:{$isbn}";
        $data = $this->fetchAPI($url);

        if (!$data || empty($data['items'])) return null;

        $book = $data['items'][0]['volumeInfo'];
        return [
            'author' => isset($book['authors']) ? implode(', ', $book['authors']) : 'غير معروف',
            'description' => isset($book['description']) ? substr(strip_tags($book['description']), 0, 800) : '',
            'cover' => isset($book['imageLinks']['thumbnail']) 
                      ? str_replace('http://', 'https://', $book['imageLinks']['thumbnail']) 
                      : null,
            'category' => isset($book['categories']) ? $book['categories'][0] : '',
            'api_source' => 'google_books'
        ];
    }

    // ============================================
    // البحث في Google Books
    // ============================================
    private function searchGoogleBooks($title) {
        $query = urlencode($title);
        $url = "https://www.googleapis.com/books/v1/volumes?q={$query}&maxResults=1";
        $data = $this->fetchAPI($url);

        if (!$data || empty($data['items'])) return null;

        $book = $data['items'][0]['volumeInfo'];
        return [
            'author' => isset($book['authors']) ? implode(', ', $book['authors']) : 'غير معروف',
            'description' => isset($book['description']) ? substr(strip_tags($book['description']), 0, 800) : '',
            'cover' => isset($book['imageLinks']['thumbnail']) 
                      ? str_replace('http://', 'https://', $book['imageLinks']['thumbnail']) 
                      : null,
            'category' => isset($book['categories']) ? $book['categories'][0] : '',
            'api_source' => 'google_books'
        ];
    }

    // ============================================
    // البحث في Open Library
    // ============================================
    private function searchOpenLibrary($title) {
        $query = urlencode($title);
        $url = "https://openlibrary.org/search.json?title={$query}&limit=1&fields=title,author_name,first_sentence,subject,cover_i";
        $data = $this->fetchAPI($url);

        if (!$data || empty($data['docs'])) return null;

        $book = $data['docs'][0];
        $description = '';
        if (isset($book['first_sentence'])) {
            $description = is_array($book['first_sentence']) 
                          ? implode(' ', array_slice($book['first_sentence'], 0, 3))
                          : $book['first_sentence'];
            $description = substr($description, 0, 800);
        }

        return [
            'author' => isset($book['author_name']) ? implode(', ', $book['author_name']) : 'غير معروف',
            'description' => $description,
            'cover' => isset($book['cover_i']) ? "https://covers.openlibrary.org/b/id/{$book['cover_i']}-L.jpg" : null,
            'category' => isset($book['subject']) ? implode(' ', array_slice($book['subject'], 0, 5)) : '',
            'api_source' => 'openlibrary'
        ];
    }

    // ============================================
    // جلب من API
    // ============================================
    private function fetchAPI($url) {
        $context = stream_context_create(['http' => [
            'timeout' => 8,
            'header' => 'User-Agent: Mozilla/5.0',
            'follow_location' => 1
        ]]);
        
        $response = @file_get_contents($url, false, $context);
        if (!$response) {
            $this->log("فشل الوصول إلى الـ API: {$url}", 'error');
            return null;
        }

        return json_decode($response, true);
    }

    // ============================================
    // التصنيف الآلي
    // ============================================
    public function autoClassify($text) {
        $text = mb_strtolower($text, 'UTF-8');
        $scores = [
            1 => ['software', 'programming', 'java', 'sql', 'برمجة', 'كود', 'حاسوب'],
            2 => ['history', 'war', 'ancient', 'تاريخ', 'حرب', 'حضارة'],
            3 => ['math', 'physics', 'science', 'علوم', 'فيزياء', 'رياضيات'],
            4 => ['novel', 'story', 'literature', 'رواية', 'قصة', 'أدب'],
            6 => ['fantasy', 'magic', 'فانتازيا', 'سحر'],
            7 => ['horror', 'scary', 'رعب', 'مخيف'],
            8 => ['mystery', 'detective', 'غموض', 'محقق'],
            9 => ['science fiction', 'space', 'خيال علمي'],
            10 => ['autobiography', 'memoir', 'سيرة', 'ذاتية'],
            11 => ['self help', 'motivation', 'تطوير', 'نجاح'],
            12 => ['islam', 'quran', 'إسلام', 'قرآن'],
            13 => ['politics', 'economy', 'سياسة', 'اقتصاد'],
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
    // حفظ الغلاف
    // ============================================
    public function saveCover($url, $title) {
        if (!$url) {
            $this->log("لا يوجد رابط غلاف متاح", 'warning');
            return 'default_book.jpg';
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->log("رابط الغلاف غير صحيح", 'warning');
            return 'default_book.jpg';
        }

        $context = stream_context_create(['http' => [
            'timeout' => 10,
            'header' => 'User-Agent: Mozilla/5.0',
            'follow_location' => 1
        ]]);

        $image_data = @file_get_contents($url, false, $context);
        if (!$image_data) {
            $this->log("فشل تحميل الغلاف", 'warning');
            return 'default_book.jpg';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_buffer($finfo, $image_data);
        finfo_close($finfo);

        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
            $this->log("نوع صورة غير مدعوم", 'warning');
            return 'default_book.jpg';
        }

        $filename = 'cover_' . uniqid() . '.jpg';
        $path = '../assets/uploads/covers/' . $filename;

        if (@file_put_contents($path, $image_data)) {
            $this->log("تم حفظ الغلاف", 'success');
            return $filename;
        }

        return 'default_book.jpg';
    }

    // ============================================
    // معالجة كتاب واحد
    // ============================================
    public function processBook($title, $pdf_url = null, $pdf_path = null, $isbn = null) {
        try {
            $title = trim($title);
            if (empty($title)) {
                $this->log("العنوان فارغ", 'error');
                return null;
            }

            // تحقق من وجود الكتاب
            $stmt = $this->pdo->prepare("SELECT id FROM books WHERE title = ? LIMIT 1");
            $stmt->execute([$title]);
            if ($stmt->fetch()) {
                $this->log("الكتاب موجود مسبقاً", 'warning');
                return null;
            }

            // جلب البيانات
            $book_data = $this->getBookDataWithCache($title, $isbn);

            $author = 'غير معروف';
            $description = '';
            $cover = 'default_book.jpg';
            $cat_text = $title;

            if ($book_data) {
                $author = htmlspecialchars($book_data['author'], ENT_QUOTES, 'UTF-8');
                $description = htmlspecialchars($book_data['description'], ENT_QUOTES, 'UTF-8');
                $cover = $this->saveCover($book_data['cover'], $title);
                $cat_text = ($book_data['category'] ?? '') . ' ' . $description;
                $this->log("✅ تم جلب البيانات", 'success');
            } else {
                $this->log("⚠️ لم يُعثر على بيانات", 'warning');
            }

            // التصنيف
            $cat_id = $this->autoClassify($cat_text);

            // حفظ الكتاب
            $stmt = $this->pdo->prepare("
                INSERT INTO books (title, author, description, category_id, pdf_file, cover_image, created_at, downloads, views) 
                VALUES (?, ?, ?, ?, ?, ?, NOW(), 0, 0)
            ");
            
            $pdf_file = $pdf_path ?? $pdf_url;
            $stmt->execute([
                htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                $author,
                $description,
                $cat_id,
                $pdf_file,
                $cover
            ]);

            $book_id = $this->pdo->lastInsertId();
            $this->log("✅ تم إضافة الكتاب", 'success');
            
            return $book_id;

        } catch (Exception $e) {
            $this->log("خطأ: " . $e->getMessage(), 'error');
            return null;
        }
    }

    // ============================================
    // التسجيل
    // ============================================
    private function log($message, $type = 'info') {
        if ($this->queue_id) {
            $stmt = $this->pdo->prepare("
                INSERT INTO import_logs (queue_id, log_type, message) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$this->queue_id, $type, $message]);
        }
    }

    // ============================================
    // جلب السجلات
    // ============================================
    public function getQueueLogs($queue_id) {
        $stmt = $this->pdo->prepare("
            SELECT log_type, message, created_at FROM import_logs 
            WHERE queue_id = ? ORDER BY created_at ASC
        ");
        $stmt->execute([$queue_id]);
        return $stmt->fetchAll();
    }
}
?>