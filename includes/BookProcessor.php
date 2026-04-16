<?php
// filepath: c:\xampp\htdocs\library\includes\BookProcessor.php

class BookProcessor {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    // ============================================
    // جلب بيانات الكتاب — يجرب مصدرين
    // ============================================
    public function getBookData($title) {
        $result = $this->searchGoogleBooks($title);
        if ($result) return $result;
        
        $result = $this->searchOpenLibrary($title);
        if ($result) return $result;
        
        return null;
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
            'author'      => isset($book['authors']) ? implode(', ', $book['authors']) : 'غير معروف',
            'description' => isset($book['description']) ? substr(strip_tags($book['description']), 0, 800) : '',
            'cover'       => isset($book['imageLinks']['thumbnail'])
                             ? str_replace('http://', 'https://', $book['imageLinks']['thumbnail'])
                             : null,
            'category'    => isset($book['categories']) ? $book['categories'][0] : '',
            'source'      => 'google_books'
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
            'author'      => isset($book['author_name']) ? implode(', ', $book['author_name']) : 'غير معروف',
            'description' => $description,
            'cover'       => isset($book['cover_i'])
                             ? "https://covers.openlibrary.org/b/id/{$book['cover_i']}-L.jpg"
                             : null,
            'category'    => isset($book['subject']) ? implode(' ', array_slice($book['subject'], 0, 5)) : '',
            'source'      => 'openlibrary'
        ];
    }

    // ============================================
    // جلب من API
    // ============================================
    private function fetchAPI($url) {
        $context = stream_context_create(['http' => [
            'timeout'         => 8,
            'header'          => 'User-Agent: Mozilla/5.0',
            'follow_location' => 1
        ]]);
        $response = @file_get_contents($url, false, $context);
        if (!$response) return null;
        return json_decode($response, true);
    }

    // ============================================
    // التصنيف الآلي — عربي وإنجليزي
    // ============================================
    public function autoClassify($text) {
        $text = mb_strtolower($text, 'UTF-8');
        $scores = [
            1  => ['software','programming','java','sql','code','python','database','javascript','algorithms','computer','coding','برمجة','كود','حاسوب','تقنية','بيانات','شبكات','ذكاء اصطناعي'],
            2  => ['history','war','ancient','century','battles','civilization','empire','historical','medieval','ottoman','roman','تاريخ','حرب','حضارة','قرن','معركة','دولة','خلافة','عثماني'],
            3  => ['math','physics','calculus','science','mathematics','algebra','chemistry','biology','astronomy','universe','quantum','علوم','فيزياء','رياضيات','كيمياء','أحياء','طب','هندسة','فلك'],
            4  => ['novel','story','drama','classic','literature','poetry','prose','narrative','tale','رواية','قصة','أدب','شعر','ديوان','مسرحية','حكاية','سيرة','نثر'],
            6  => ['fantasy','magic','dragon','wizard','witch','spell','mythical','enchanted','fairy','elf','hobbit','فانتازيا','سحر','تنين','ساحر','خيال','أسطورة','مملكة'],
            7  => ['horror','scary','ghost','haunted','terror','nightmare','demon','vampire','zombie','evil','darkness','رعب','مخيف','شبح','ظلام','خوف','وحش','مسكون'],
            8  => ['mystery','thriller','detective','crime','murder','suspense','investigation','clue','sherlock','spy','secret','غموض','تشويق','محقق','جريمة','قتل','سر','تحقيق','جاسوس'],
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

    // ============================================
    // حفظ الغلاف من URL
    // ============================================
    public function saveCover($url) {
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return 'default_book.jpg';
        }

        $covers_dir = dirname(__DIR__) . '/assets/uploads/covers/';
        if (!is_dir($covers_dir)) {
            @mkdir($covers_dir, 0755, true);
        }

        $context = stream_context_create(['http' => [
            'timeout'         => 10,
            'header'          => 'User-Agent: Mozilla/5.0',
            'follow_location' => 1
        ]]);
        $image_data = @file_get_contents($url, false, $context);

        if (!$image_data || strlen($image_data) === 0) {
            return 'default_book.jpg';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_buffer($finfo, $image_data);
        finfo_close($finfo);

        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
            return 'default_book.jpg';
        }

        $filename = 'cover_' . uniqid() . '.jpg';
        $path     = $covers_dir . $filename;

        if (@file_put_contents($path, $image_data)) {
            return $filename;
        }
        return 'default_book.jpg';
    }

    // ============================================
    // حفظ كتاب في قاعدة البيانات
    // ============================================
    public function saveBook($title, $pdf_file, $author = 'غير معروف', $description = '', $cover = 'default_book.jpg', $cat_id = 5) {
        // تحقق من عدم التكرار
        $check = $this->pdo->prepare("SELECT id FROM books WHERE title = ? LIMIT 1");
        $check->execute([$title]);
        if ($check->fetch()) return null;

        $stmt = $this->pdo->prepare("
            INSERT INTO books (title, author, description, category_id, pdf_file, cover_image, created_at, downloads, views)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), 0, 0)
        ");
        $stmt->execute([
            htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($author, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($description, ENT_QUOTES, 'UTF-8'),
            $cat_id,
            $pdf_file,
            $cover
        ]);
        return $this->pdo->lastInsertId();
    }

    // ============================================
    // معالجة كتاب واحد كاملاً
    // ============================================
    public function processBook($title, $pdf_file) {
        $title = trim($title);
        if (empty($title)) return ['status' => 'error', 'msg' => 'العنوان فارغ', 'book_id' => null];

        $book_data = $this->getBookData($title);

        if ($book_data) {
            $author      = $book_data['author'];
            $description = $book_data['description'];
            $cover       = $this->saveCover($book_data['cover']);
            $cat_text    = ($book_data['category'] ?? '') . ' ' . $description;
            $status_msg  = "✅ <b>$title</b> — تم جلب البيانات (المؤلف: $author)";
        } else {
            $author      = 'غير معروف';
            $description = '';
            $cover       = 'default_book.jpg';
            $cat_text    = $title;
            $status_msg  = "⚠️ <b>$title</b> — لم يُعثر على بيانات";
        }

        $cat_id  = $this->autoClassify($cat_text);
        $book_id = $this->saveBook($title, $pdf_file, $author, $description, $cover, $cat_id);

        if (!$book_id) {
            return ['status' => 'skip', 'msg' => "⚠️ <b>$title</b> — موجود مسبقاً", 'book_id' => null];
        }

        return ['status' => 'success', 'msg' => $status_msg, 'book_id' => $book_id];
    }

    // ============================================
    // إضافة للطابور
    // ============================================
    public function addToQueue($title, $pdf_url) {
        $check = $this->pdo->prepare("SELECT id FROM books WHERE title = ? LIMIT 1");
        $check->execute([$title]);
        if ($check->fetch()) return false;

        $stmt = $this->pdo->prepare("
            INSERT INTO import_queue (title, pdf_url, status) VALUES (?, ?, 'pending')
        ");
        $stmt->execute([trim($title), trim($pdf_url)]);
        return true;
    }

    // ============================================
    // معالجة الطابور
    // ============================================
    public function processQueue($batch_size = 5) {
        $stmt = $this->pdo->prepare("
            SELECT id, title, pdf_url, pdf_path FROM import_queue
            WHERE status = 'pending'
            ORDER BY created_at ASC
            LIMIT :batch_size
        ");
        $stmt->bindValue(':batch_size', $batch_size, PDO::PARAM_INT);
        $stmt->execute();
        $jobs = $stmt->fetchAll();

        if (empty($jobs)) return 0;

        $processed = 0;
        foreach ($jobs as $job) {
            $this->pdo->prepare("UPDATE import_queue SET status = 'processing' WHERE id = ?")
                      ->execute([$job['id']]);
            try {
                // استخدم pdf_path إذا كان موجود، وإلا استخدم pdf_url
                $pdf_file = !empty($job['pdf_path']) ? $job['pdf_path'] : $job['pdf_url'];
                if (empty($pdf_file)) {
                    throw new Exception("لا يوجد مسار PDF أو URL");
                }
                
                $result  = $this->processBook($job['title'], $pdf_file);
                $status  = $result['status'] === 'error' ? 'failed' : 'done';
                $this->pdo->prepare("UPDATE import_queue SET status = ?, book_id = ?, processed_at = NOW() WHERE id = ?")
                          ->execute([$status, $result['book_id'] ?? null, $job['id']]);
                $processed++;
            } catch (Exception $e) {
                $this->pdo->prepare("UPDATE import_queue SET status = 'failed', error_message = ?, processed_at = NOW() WHERE id = ?")
                          ->execute([$e->getMessage(), $job['id']]);
            }
        }
        return $processed;
    }

    // ============================================
    // إحصائيات الطابور
    // ============================================
    public function getQueueStats() {
        try {
            return $this->pdo->query("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending'    THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                    SUM(CASE WHEN status = 'done'       THEN 1 ELSE 0 END) as done,
                    SUM(CASE WHEN status = 'failed'     THEN 1 ELSE 0 END) as failed
                FROM import_queue
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            ")->fetch();
        } catch (Exception $e) {
            return ['total' => 0, 'pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0];
        }
    }
}
?>