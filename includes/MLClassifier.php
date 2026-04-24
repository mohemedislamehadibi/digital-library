<?php
/**
 * MLClassifier.php
 * ==================
 * يستدعي نموذج Python ML بدل keyword scoring.
 * يُستخدم داخل BookProcessor.php كـ upgrade لدالة autoClassify().
 *
 * الاستخدام:
 *   $ml  = new MLClassifier();
 *   $cat = $ml->predict("Think and Grow Rich", "motivational success wealth");
 *   // → ["category_id" => 11, "category_name" => "تطوير الذات", "confidence" => 0.87]
 */
class MLClassifier {

    // مسار مجلد نماذج Python — عدّله حسب موقعك
    private string $ml_dir;
    private string $python_exe;
    private bool   $available;

    public function __construct() {
        // مسار الـ ml_classifier مقارنةً بـ includes/
        $this->ml_dir     = realpath(__DIR__ . '/../ml_classifier') ?: '';
        $this->python_exe = $this->detectPython();
        $this->available  = $this->checkAvailable();
    }

    // ============================================================
    // التنبؤ بالفئة — الدالة الرئيسية
    // ============================================================
    public function predict(string $title, string $description = ''): array {
        // إذا النموذج غير متاح → fallback لـ keywords
        if (!$this->available) {
            return $this->fallbackKeywords($title . ' ' . $description);
        }

        // تنظيف المدخلات
        $title       = $this->sanitize($title);
        $description = $this->sanitize($description);

        // استدعاء predict.py
        $script = $this->ml_dir . DIRECTORY_SEPARATOR . 'predict.py';
        $cmd    = sprintf(
            '%s %s %s %s 2>%s',
            $this->python_exe,
            escapeshellarg($script),
            escapeshellarg($title),
            escapeshellarg($description),
            PHP_OS_FAMILY === 'Windows' ? 'nul' : '/dev/null'
        );

        $output = @shell_exec($cmd);

        if (!$output || !($result = json_decode($output, true))) {
            error_log("MLClassifier: فشل استدعاء Python — " . ($output ?? 'null'));
            return $this->fallbackKeywords($title . ' ' . $description);
        }

        // إذا confidence منخفضة جداً → استخدم keywords كـ check
        if (($result['confidence'] ?? 0) < 0.15) {
            $kw_result = $this->fallbackKeywords($title . ' ' . $description);
            if ($kw_result['score'] > 0) {
                return array_merge($kw_result, ['source' => 'keywords_low_confidence']);
            }
        }

        $result['source'] = 'ml_model';
        return $result;
    }

    // ============================================================
    // Fallback — Keyword Scoring الأصلي
    // ============================================================
    private function fallbackKeywords(string $text): array {
        $text   = mb_strtolower($text, 'UTF-8');
        $cats   = [
            1=>'برمجة', 2=>'تاريخ', 3=>'علوم', 4=>'أدب', 5=>'عام',
            6=>'فانتازيا', 7=>'رعب', 8=>'غموض وتشويق', 9=>'خيال علمي',
            10=>'سيرة ذاتية', 11=>'تطوير الذات', 12=>'ديني وإسلامي',
            13=>'سياسة واقتصاد',
        ];
        $kws = [
            1  => ['software','programming','java','sql','code','python','database',
                   'javascript','algorithms','computer','برمجة','كود','حاسوب'],
            2  => ['history','war','ancient','century','civilization','empire',
                   'تاريخ','حرب','حضارة','معركة'],
            3  => ['math','physics','science','mathematics','biology','chemistry',
                   'علوم','فيزياء','رياضيات','كيمياء'],
            4  => ['novel','story','literature','poetry','prose',
                   'رواية','قصة','أدب','شعر','نثر'],
            5  => ['general','عام'],
            6  => ['fantasy','magic','dragon','wizard','witch','mythical',
                   'فانتازيا','سحر','تنين','ساحر'],
            7  => ['horror','scary','ghost','terror','nightmare','vampire',
                   'رعب','مخيف','شبح','ظلام'],
            8  => ['mystery','thriller','detective','crime','murder','suspense',
                   'غموض','تشويق','محقق','جريمة'],
            9  => ['science fiction','sci-fi','space','robot','dystopia','future',
                   'خيال علمي','فضاء','روبوت','مستقبل'],
            10 => ['autobiography','biography','memoir','life story',
                   'سيرة','ذاتية','مذكرات'],
            11 => ['self help','motivation','success','leadership','habits',
                   'productivity','rich','wealth','mindset','personal development',
                   'تطوير','نجاح','قيادة','عادات','ثروة','تحفيز'],
            12 => ['islam','quran','hadith','prophet','religious',
                   'إسلام','قرآن','حديث','نبي','دين'],
            13 => ['politics','economy','government','democracy','economics',
                   'سياسة','اقتصاد','حكومة','ديمقراطية'],
        ];

        $scores = [];
        foreach ($kws as $id => $words) {
            $scores[$id] = 0;
            foreach ($words as $w) {
                if (mb_strpos($text, $w) !== false) $scores[$id]++;
            }
        }
        arsort($scores);
        $best  = key($scores);
        $score = $scores[$best];
        if ($score === 0) { $best = 5; }

        return [
            'category_id'   => $best,
            'category_name' => $cats[$best],
            'confidence'    => 0.0,
            'score'         => $score,
            'source'        => 'keywords',
        ];
    }

    // ============================================================
    // دوال مساعدة
    // ============================================================
    private function detectPython(): string {
        foreach (['python3', 'python', 'py'] as $cmd) {
            $v = @shell_exec("$cmd --version 2>&1");
            if ($v && str_contains($v, 'Python 3')) return $cmd;
        }
        return 'python3'; // افتراضي
    }

    private function checkAvailable(): bool {
        if (empty($this->ml_dir)) return false;
        $model = $this->ml_dir . DIRECTORY_SEPARATOR . 'model.pkl';
        $vec   = $this->ml_dir . DIRECTORY_SEPARATOR . 'vectorizer.pkl';
        $pred  = $this->ml_dir . DIRECTORY_SEPARATOR . 'predict.py';
        return file_exists($model) && file_exists($vec) && file_exists($pred);
    }

    private function sanitize(string $s): string {
        // نزيل الرموز الخطرة فقط ونبقي العربية
        return trim(str_replace(['"', "'", '\\', "\n", "\r"], ' ', $s));
    }

    public function isAvailable(): bool { return $this->available; }
    public function getPythonPath(): string { return $this->python_exe; }
}
