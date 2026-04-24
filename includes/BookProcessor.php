<?php
/**
 * BookProcessor.php
 * =================
 * Service class: معالجة الكتب، جلب البيانات، التصنيف الذكي.
 *
 * التصنيف يعمل بطبقتين:
 *   1. ML Model (Logistic Regression 94.1%) عبر MLClassifier.php
 *   2. Keyword Scoring (fallback تلقائي إذا Python غير متاح)
 */

require_once __DIR__ . '/MLClassifier.php';

class BookProcessor {

    private $pdo;
    private $queue_id;
    private MLClassifier $ml;

    public function __construct($pdo, $queue_id = null) {
        $this->pdo      = $pdo;
        $this->queue_id = $queue_id;
        $this->ml       = new MLClassifier();
    }

    // ============================================================
    // البحث مع الكاش
    // ============================================================
    public function getBookDataWithCache($title, $isbn = null) {
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
            $this->log("خطأ الكاش: " . $e->getMessage(), 'error');
        }

        $data = null;
        if ($isbn) {
            $data = $this->searchGoogleBooksByISBN($isbn);
        } else {
            $data = $this->searchGoogleBooks($title);
            if (!$data) {
                $data = $this->searchOpenLibrary($title);
            }
        }

        if ($data) {
            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO books_cache (search_key, search_type, api_source, data)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE data=VALUES(data), created_at=NOW()
                ");
                $stmt->execute([
                    $search_key, $search_type,
                    $data['api_source'] ?? 'unknown',
                    json_encode($data)
                ]);
            } catch (Exception $e) {
                $this->log("خطأ حفظ الكاش: " . $e->getMessage(), 'warning');
            }
        }

        return $data;
    }

    // ============================================================
    // البحث عبر ISBN
    // ============================================================
    private function searchGoogleBooksByISBN($isbn) {
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

    // ============================================================
    // البحث في Google Books
    // ============================================================
    private function searchGoogleBooks($title) {
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

    // ============================================================
    // البحث في Open Library
    // ============================================================
    private function searchOpenLibrary($title) {
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

    // ============================================================
    // جلب من API
    // ============================================================
    private function fetchAPI($url) {
        $context  = stream_context_create(['http' => [
            'timeout' => 8, 'header' => 'User-Agent: Mozilla/5.0', 'follow_location' => 1,
        ]]);
        $response = @file_get_contents($url, false, $context);
        if (!$response) {
            $this->log("فشل الوصول إلى: $url", 'error');
            return null;
        }
        return json_decode($response, true);
    }

    // ============================================================
    // ★ autoClassify — ML أولاً ثم Keyword Scoring كـ fallback
    //    يُرجع: int (category_id 1-13)
    // ============================================================
    public function autoClassify($text) {
        $ml_result = $this->ml->predict('', $text);

        if (($ml_result['confidence'] ?? 0) >= 0.15) {
            $this->log(
                "ML ✓ [{$ml_result['category_name']}] " .
                round($ml_result['confidence'] * 100) . "%",
                'success'
            );
            return (int)$ml_result['category_id'];
        }

        $this->log("ML confidence منخفضة — Keyword Scoring", 'warning');
        return $this->keywordClassify($text);
    }

    // ============================================================
    // ★ autoClassifyFull — يُرجع كامل البيانات للإحصائيات
    //    يُستدعى من classification_stats.php
    // ============================================================
    public function autoClassifyFull($text, $title = '') {
        $categories = [
            1=>'برمجة وتقنية',  2=>'تاريخ وحضارات', 3=>'علوم وطبيعة',
            4=>'أدب وروايات',   5=>'عام',            6=>'فانتازيا',
            7=>'رعب',           8=>'غموض وتشويق',    9=>'خيال علمي',
            10=>'سيرة ذاتية',  11=>'تطوير الذات',   12=>'ديني وإسلامي',
            13=>'سياسة واقتصاد',
        ];

        $ml_result = $this->ml->predict($title, $text);

        if (($ml_result['confidence'] ?? 0) >= 0.15) {
            return [
                'category_id'   => (int)$ml_result['category_id'],
                'category_name' => $ml_result['category_name'],
                'score'         => round($ml_result['confidence'] * 100),
                'confidence'    => $ml_result['confidence'],
                'source'        => 'ml_model',
            ];
        }

        $kw_id = $this->keywordClassify($text);
        return [
            'category_id'   => $kw_id,
            'category_name' => $categories[$kw_id] ?? 'عام',
            'score'         => 0,
            'confidence'    => 0.0,
            'source'        => 'keywords',
        ];
    }

    // ============================================================
    // Keyword Scoring (fallback داخلي — لا يُستدعى مباشرة)
    // ============================================================
    private function keywordClassify($text) {
        $text = mb_strtolower($text, 'UTF-8');
        $scores = [
            1  => ['software','programming','java','sql','code','python','database',
                   'javascript','algorithms','computer','coding','developer',
                   'machine learning','data science','neural network',
                   'برمجة','كود','حاسوب','تقنية','بيانات','شبكات','ذكاء اصطناعي'],
            2  => ['history','war','ancient','century','battles','civilization',
                   'empire','historical','medieval','ottoman','roman','napoleon',
                   'revolution','dynasty','crusade',
                   'تاريخ','حرب','حضارة','قرن','معركة','خلافة','عثماني','ثورة'],
            3  => ['math','physics','calculus','science','mathematics','algebra',
                   'chemistry','biology','astronomy','universe','quantum',
                   'genetics','evolution',
                   'علوم','فيزياء','رياضيات','كيمياء','أحياء','طب','هندسة','فلك'],
            4  => ['novel','story','drama','classic','literature','poetry','prose',
                   'narrative','tale','fiction',
                   'dostoevsky','tolstoy','kafka','hugo','dickens','hemingway',
                   'shakespeare','orwell','fitzgerald','chekhov',
                   'crime punishment','brothers karamazov','anna karenina',
                   'les miserables','great gatsby',
                   'نجيب محفوظ','طه حسين','جبران',
                   'رواية','قصة','أدب','شعر','ديوان','مسرحية','نثر'],
            5  => ['general','عام','متنوع'],
            6  => ['fantasy','magic','dragon','wizard','witch','spell','mythical',
                   'tolkien','narnia','hobbit','harry potter','gandalf',
                   'فانتازيا','سحر','تنين','ساحر','أسطورة','مملكة'],
            7  => ['horror','scary','ghost','haunted','terror','nightmare',
                   'demon','vampire','zombie','evil','darkness',
                   'stephen king','lovecraft','dracula','frankenstein',
                   'رعب','مخيف','شبح','ظلام','خوف','وحش','مسكون'],
            8  => ['mystery','thriller','detective','crime','murder','suspense',
                   'investigation','sherlock','spy','agatha christie',
                   'conan doyle','conspiracy',
                   'غموض','تشويق','محقق','جريمة','قتل','سر','تحقيق','جاسوس'],
            9  => ['science fiction','sci-fi','space','robot','alien','dystopia',
                   'dystopian','future','galaxy','spacecraft','cyberpunk',
                   'asimov','dune','matrix',
                   'خيال علمي','فضاء','روبوت','مستقبل','مجرة','ديستوبيا'],
            10 => ['autobiography','biography','memoir','life story','diary',
                   'سيرة ذاتية','مذكرات','يوميات','حياة'],
            11 => ['self help','motivation','success','leadership','productivity',
                   'mindset','habits','personal development',
                   'dale carnegie','napoleon hill','tony robbins',
                   'think grow rich','rich dad','7 habits',
                   'تطوير ذات','نجاح','قيادة','إنتاجية','عادات','تحفيز',
                   'ثقة بالنفس','إدارة وقت'],
            12 => ['islam','quran','hadith','prophet','religious','faith',
                   'muhammad','muslim','prayer','fasting','hajj','fiqh',
                   'إسلام','قرآن','حديث','نبي','دين','فقه','عقيدة','إيمان',
                   'صلاة','زكاة','حج','تفسير','شريعة'],
            13 => ['politics','economy','government','democracy','economics',
                   'capitalism','socialism','communism','geopolitics',
                   'karl marx','machiavelli','globalization',
                   'سياسة','اقتصاد','حكومة','ديمقراطية','رأسمالية',
                   'انتخابات','حزب','برلمان','دستور'],
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
        return ($results[$best] > 0) ? (int)$best : 5;
    }

    // ============================================================
    // ★ استخراج نص من PDF
    // ============================================================
    public function extractTextFromPdf($pdf_path) {
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

    // ============================================================
    // ★ بناء نص التصنيف — أولوية: PDF > API > عنوان
    // ============================================================
    public function buildClassificationText($book_data, $pdf_path = null) {
        $pdf_text = '';
        if ($pdf_path) {
            $pdf_text = $this->extractTextFromPdf($pdf_path);
        }
        if (!empty($pdf_text)) {
            $this->log("مصدر التصنيف: نص PDF", 'success');
            return $pdf_text;
        }
        $api_text = ($book_data['category'] ?? '') . ' ' . ($book_data['description'] ?? '');
        if (!empty(trim($api_text))) {
            $this->log("مصدر التصنيف: وصف API", 'info');
            return $api_text;
        }
        $this->log("مصدر التصنيف: عنوان فقط", 'warning');
        return '';
    }

    // ============================================================
    // حفظ الغلاف من URL
    // ============================================================
    public function saveCover($url, $title) {
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            $this->log("رابط غلاف غير صالح", 'warning');
            return 'default_book.jpg';
        }
        $context    = stream_context_create(['http' => [
            'timeout' => 10, 'header' => 'User-Agent: Mozilla/5.0', 'follow_location' => 1,
        ]]);
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

    // ============================================================
    // معالجة كتاب كامل
    // ============================================================
    public function processBook($title, $pdf_url = null, $pdf_path = null, $isbn = null) {
        try {
            $title = trim($title);
            if (empty($title)) { $this->log("العنوان فارغ", 'error'); return null; }

            $stmt = $this->pdo->prepare("SELECT id FROM books WHERE title=? LIMIT 1");
            $stmt->execute([$title]);
            if ($stmt->fetch()) {
                $this->log("الكتاب موجود مسبقاً: $title", 'warning');
                return null;
            }

            $book_data   = $this->getBookDataWithCache($title, $isbn);
            $author      = 'غير معروف';
            $description = '';
            $cover       = 'default_book.jpg';

            if ($book_data) {
                $author      = htmlspecialchars($book_data['author'] ?? 'غير معروف', ENT_QUOTES, 'UTF-8');
                $description = htmlspecialchars($book_data['description'] ?? '', ENT_QUOTES, 'UTF-8');
                $cover       = $this->saveCover($book_data['cover'] ?? null, $title);
            }

            $local_pdf_path = ($pdf_path && file_exists($pdf_path)) ? $pdf_path : null;
            $classify_text  = $this->buildClassificationText($book_data ?? [], $local_pdf_path);
            $cat_id         = $this->autoClassify($classify_text ?: $title);

            $stmt = $this->pdo->prepare("
                INSERT INTO books (title,author,description,category_id,pdf_file,cover_image,created_at,downloads,views)
                VALUES (?,?,?,?,?,?,NOW(),0,0)
            ");
            $stmt->execute([
                htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                $author, $description, $cat_id,
                $pdf_url ?? $pdf_path ?? '', $cover,
            ]);

            $book_id = $this->pdo->lastInsertId();
            $this->log("✅ تمت إضافة: $title (cat=$cat_id)", 'success');
            return $book_id;

        } catch (Exception $e) {
            $this->log("خطأ: " . $e->getMessage(), 'error');
            return null;
        }
    }

    // ============================================================
    // التسجيل في import_logs
    // ============================================================
    private function log($message, $type = 'info') {
        if ($this->queue_id) {
            try {
                $this->pdo->prepare(
                    "INSERT INTO import_logs (queue_id,log_type,message) VALUES (?,?,?)"
                )->execute([$this->queue_id, $type, $message]);
            } catch (Exception $e) { /* صامت */ }
        }
    }

    public function getQueueLogs($queue_id) {
        $stmt = $this->pdo->prepare(
            "SELECT log_type,message,created_at FROM import_logs WHERE queue_id=? ORDER BY created_at ASC"
        );
        $stmt->execute([$queue_id]);
        return $stmt->fetchAll();
    }

    // ============================================================
    // معالجة الطابور — worker.php
    // ============================================================
    public function processQueue($limit = 5) {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM import_queue WHERE status='pending' ORDER BY created_at ASC LIMIT ?"
        );
        $stmt->execute([$limit]);
        $jobs = $stmt->fetchAll();
        if (empty($jobs)) return 0;

        $count = 0;
        foreach ($jobs as $job) {
            $this->pdo->prepare("UPDATE import_queue SET status='processing' WHERE id=?")->execute([$job['id']]);
            $this->queue_id = $job['id'];
            try {
                $book_id = $this->processBook($job['title'], $job['pdf_url'], null);
                if ($book_id) {
                    $this->pdo->prepare("UPDATE import_queue SET status='done',book_id=? WHERE id=?")->execute([$book_id, $job['id']]);
                    $count++;
                } else {
                    $this->pdo->prepare("UPDATE import_queue SET status='failed' WHERE id=?")->execute([$job['id']]);
                }
            } catch (Exception $e) {
                $this->log("خطأ: " . $e->getMessage(), 'error');
                $this->pdo->prepare("UPDATE import_queue SET status='failed' WHERE id=?")->execute([$job['id']]);
            }
        }
        $this->queue_id = null;
        return $count;
    }

    // ============================================================
    // إحصائيات الطابور
    // ============================================================
    public function getQueueStats() {
        return $this->pdo->query("
            SELECT COUNT(*) as total,
                SUM(CASE WHEN status='pending'    THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status='processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status='done'       THEN 1 ELSE 0 END) as done,
                SUM(CASE WHEN status='failed'     THEN 1 ELSE 0 END) as failed
            FROM import_queue
        ")->fetch();
    }

    public function isMLAvailable(): bool { return $this->ml->isAvailable(); }
}
