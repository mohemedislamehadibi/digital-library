<?php
/**
 * MLClassifier.php — نسخة مُصحَّحة
 * ضعه في: C:\xampp\htdocs\library\MLClassifier.php
 */
class MLClassifier {

    private string $ml_dir;
    private string $python_exe;
    private bool   $available;

    public function __construct() {
       $this->ml_dir = realpath(__DIR__ . '/../ml_classifier') ?: '';
        $this->python_exe = $this->detectPython();
        $this->available  = $this->checkAvailable();
    }

    // ============================================================
    // التنبؤ بالفئة — الدالة الرئيسية
    // ============================================================
    public function predict(string $title, string $description = ''): array {
        if (!$this->available) {
            return $this->fallbackKeywords($title . ' ' . $description);
        }

        $title       = $this->sanitize($title);
        $description = $this->sanitize($description);

        $script = $this->ml_dir . DIRECTORY_SEPARATOR . 'predict.py';
        $null   = PHP_OS_FAMILY === 'Windows' ? 'nul' : '/dev/null';

        // ★ مسار Python بين علامتي تنصيص لأن المسار فيه مسافات
        $cmd = sprintf(
            '"%s" "%s" %s %s 2>%s',
            $this->python_exe,
            $script,
            escapeshellarg($title),
            escapeshellarg($description),
            $null
        );

        $output = @shell_exec($cmd);

        if (!$output || !($result = json_decode(trim($output), true))) {
            error_log("MLClassifier: فشل — CMD: $cmd | Output: " . ($output ?? 'null'));
            return $this->fallbackKeywords($title . ' ' . $description);
        }

        if (($result['confidence'] ?? 0) < 0.15) {
            $kw_result = $this->fallbackKeywords($title . ' ' . $description);
            if (($kw_result['score'] ?? 0) > 0) {
                return array_merge($kw_result, ['source' => 'keywords_low_confidence']);
            }
        }

        $result['source'] = 'ml_model';
        return $result;
    }

    // ============================================================
    // Fallback — Keyword Scoring
    // ============================================================
    private function fallbackKeywords(string $text): array {
        $text = mb_strtolower($text, 'UTF-8');
        $cats = [
            1=>'برمجة', 2=>'تاريخ', 3=>'علوم', 4=>'أدب', 5=>'عام',
            6=>'فانتازيا', 7=>'رعب', 8=>'غموض وتشويق', 9=>'خيال علمي',
            10=>'سيرة ذاتية', 11=>'تطوير الذات', 12=>'ديني وإسلامي',
            13=>'سياسة واقتصاد',
        ];
        $kws = [
            1  => ['software','programming','java','sql','code','python','database','javascript','algorithms','computer','برمجة','كود','حاسوب'],
            2  => ['history','war','ancient','century','civilization','empire','تاريخ','حرب','حضارة','معركة'],
            3  => ['math','physics','science','mathematics','biology','chemistry','علوم','فيزياء','رياضيات','كيمياء'],
            4  => ['novel','story','literature','poetry','prose','رواية','قصة','أدب','شعر','نثر'],
            5  => ['general','عام'],
            6  => ['fantasy','magic','dragon','wizard','witch','mythical','فانتازيا','سحر','تنين','ساحر'],
            7  => ['horror','scary','ghost','terror','nightmare','vampire','رعب','مخيف','شبح','ظلام'],
            8  => ['mystery','thriller','detective','crime','murder','suspense','غموض','تشويق','محقق','جريمة'],
            9  => ['science fiction','sci-fi','space','robot','dystopia','future','خيال علمي','فضاء','روبوت','مستقبل'],
            10 => ['autobiography','biography','memoir','life story','سيرة','ذاتية','مذكرات'],
            11 => ['self help','motivation','success','leadership','habits','productivity','rich','wealth','mindset','تطوير','نجاح','قيادة','عادات','ثروة','تحفيز'],
            12 => ['islam','quran','hadith','prophet','religious','إسلام','قرآن','حديث','نبي','دين'],
            13 => ['politics','economy','government','democracy','economics','سياسة','اقتصاد','حكومة','ديمقراطية'],
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
        if ($score === 0) $best = 5;

        return [
            'category_id'   => $best,
            'category_name' => $cats[$best],
            'confidence'    => 0.0,
            'score'         => $score,
            'source'        => 'keywords',
        ];
    }

    // ============================================================
    // ★ detectPython — يبحث عن Python تلقائياً + المسار الكامل
    // ============================================================
    private function detectPython(): string {
        // ★ المسارات الشائعة على Windows — يفحصها بالترتيب
        $windows_paths = [
            'C:\Users\ALEM\AppData\Local\Programs\Python\Python313\python.exe',
            'C:\Users\ALEM\AppData\Local\Programs\Python\Python312\python.exe',
            'C:\Users\ALEM\AppData\Local\Programs\Python\Python311\python.exe',
            'C:\Python313\python.exe',
            'C:\Python312\python.exe',
            'C:\Python311\python.exe',
            'C:\Python310\python.exe',
        ];

        // فحص المسارات المباشرة أولاً
        foreach ($windows_paths as $path) {
            if (file_exists($path)) {
                error_log("MLClassifier: وجد Python في: $path");
                return $path;
            }
        }

        // محاولة الأوامر العامة
        foreach (['python', 'python3', 'py'] as $cmd) {
            $v = @shell_exec("$cmd --version 2>&1");
            if ($v && str_contains($v, 'Python 3')) {
                error_log("MLClassifier: Python عبر الأمر: $cmd");
                return $cmd;
            }
        }

        // محاولة py launcher (خاص بـ Windows)
        $py_check = @shell_exec('py -3 --version 2>&1');
        if ($py_check && str_contains($py_check, 'Python 3')) {
            return 'py -3';
        }

        error_log("MLClassifier: لم يُعثر على Python!");
        return 'python';
    }

    private function checkAvailable(): bool {
        if (empty($this->ml_dir)) {
            error_log("MLClassifier: ml_dir فارغ — تحقق من مسار ml_classifier");
            return false;
        }
        $model = $this->ml_dir . DIRECTORY_SEPARATOR . 'model.pkl';
        $vec   = $this->ml_dir . DIRECTORY_SEPARATOR . 'vectorizer.pkl';
        $pred  = $this->ml_dir . DIRECTORY_SEPARATOR . 'predict.py';

        $ok = file_exists($model) && file_exists($vec) && file_exists($pred);
        if (!$ok) {
            error_log("MLClassifier: ملفات مفقودة في: {$this->ml_dir}");
        }
        return $ok;
    }

    private function sanitize(string $s): string {
        return trim(str_replace(['"', "'", '\\', "\n", "\r"], ' ', $s));
    }

    public function isAvailable(): bool    { return $this->available; }
    public function getPythonPath(): string { return $this->python_exe; }
    public function getMlDir(): string     { return $this->ml_dir; }

    // ============================================================
    // ★ دالة التشخيص — تساعدك تعرف وش المشكلة
    // ============================================================
    public function diagnose(): array {
        $python_version = @shell_exec('"' . $this->python_exe . '" --version 2>&1');
        $script         = $this->ml_dir . DIRECTORY_SEPARATOR . 'predict.py';
        $test_cmd       = sprintf('"%s" "%s" "test book" "test description" 2>&1',
                            $this->python_exe, $script);
        $test_output    = @shell_exec($test_cmd);

        return [
            'python_exe'      => $this->python_exe,
            'python_version'  => trim($python_version ?? 'غير موجود'),
            'ml_dir'          => $this->ml_dir,
            'model_exists'    => file_exists($this->ml_dir . '/model.pkl'),
            'vec_exists'      => file_exists($this->ml_dir . '/vectorizer.pkl'),
            'predict_exists'  => file_exists($this->ml_dir . '/predict.py'),
            'available'       => $this->available,
            'test_output'     => trim($test_output ?? 'لا يوجد مخرج'),
        ];
    }
}