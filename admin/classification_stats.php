<?php

session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php"); exit();
}
require_once '../includes/db.php';

function findPython(): string {
    
    $explicit = 'C:\\Users\\ALEM\\AppData\\Local\\Programs\\Python\\Python313\\python.exe';
    if (file_exists($explicit)) return $explicit;

    
    $win_paths = [
        'C:\\Python313\\python.exe',
        'C:\\Python312\\python.exe',
        'C:\\Python311\\python.exe',
        'C:\\Python310\\python.exe',
        'C:\\Program Files\\Python313\\python.exe',
        'C:\\Program Files\\Python312\\python.exe',
    ];
    foreach ($win_paths as $p) {
        if (file_exists($p)) return $p;
    }
    return 'python';
}

$python_raw  = findPython();  
$ml_dir      = realpath(__DIR__ . '/../ml_classifier');
$script_path = $ml_dir ? ($ml_dir . DIRECTORY_SEPARATOR . 'generate_scatter.py') : '';

$db_host = '127.0.0.1';
$db_port = '3307';
$db_name = 'library_db';
$db_user = 'root';
$db_pass = '';

$json_data = null;
$ml_error  = null;
$debug_cmd = '';

if ($script_path && file_exists($script_path)) {

    
    $cmd = escapeshellarg($python_raw)
         . ' ' . escapeshellarg($script_path)
         . ' --host ' . escapeshellarg($db_host)
         . ' --port ' . escapeshellarg($db_port)
         . ' --db '   . escapeshellarg($db_name)
         . ' --user ' . escapeshellarg($db_user)
         . ' --pw '   . escapeshellarg($db_pass)
         . ' 2>nul';

    $debug_cmd = $cmd;
    $output    = @shell_exec($cmd);

    if ($output && strlen(trim($output)) > 0) {
    
        $clean = trim($output);
        $clean = preg_replace('/^\xEF\xBB\xBF/', '', $clean); // BOM
       
        foreach (explode("\n", $clean) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '{')) {
                $parsed = json_decode($line, true);
                if (is_array($parsed) && !isset($parsed['error'])) {
                    $json_data = $parsed;
                } else {
                    $ml_error = $parsed['error'] ?? 'JSON غير صالح: ' . substr($line, 0, 200);
                }
                break;
            }
        }
        if (!$json_data && !$ml_error) {
            $ml_error = 'لم يُعثر على JSON في المخرج: ' . substr($clean, 0, 300);
        }
    } else {
       
        $cmd2   = escapeshellarg($python_raw)
                . ' ' . escapeshellarg($script_path)
                . ' --host ' . escapeshellarg($db_host)
                . ' --port ' . escapeshellarg($db_port)
                . ' --db '   . escapeshellarg($db_name)
                . ' --user ' . escapeshellarg($db_user)
                . ' --pw '   . escapeshellarg($db_pass)
                . ' 2>&1';
        $out2   = @shell_exec($cmd2);
        $ml_error = 'Python لم يُرجع مخرجاً. تشخيص: ' . substr($out2 ?? 'فارغ', 0, 400);
    }
} else {
    $ml_error = 'generate_scatter.py غير موجود في: ' . ($ml_dir ?: 'مسار غير صالح');
}


function kwClassify(string $text): array {
    $text = mb_strtolower($text, 'UTF-8');
    $cats = [
        1=>'برمجة', 2=>'تاريخ', 3=>'علوم', 4=>'أدب', 5=>'عام',
        6=>'فانتازيا', 7=>'رعب', 8=>'غموض وتشويق', 9=>'خيال علمي',
        10=>'سيرة ذاتية', 11=>'تطوير الذات',
        12=>'ديني وإسلامي', 13=>'سياسة واقتصاد',
    ];
    $kws = [
        1  => ['software','programming','python','java','sql','code','database','javascript','برمجة','كود','حاسوب'],
        2  => ['history','war','ancient','civilization','empire','century','تاريخ','حرب','حضارة','قرن','معركة'],
        3  => ['science','math','physics','biology','chemistry','astronomy','علوم','فيزياء','رياضيات','كيمياء'],
        4  => ['novel','story','literature','poetry','prose','dostoevsky','tolstoy','رواية','قصة','أدب','شعر'],
        5  => ['general','عام'],
        6  => ['fantasy','magic','dragon','wizard','witch','فانتازيا','سحر','تنين','ساحر'],
        7  => ['horror','ghost','terror','evil','vampire','zombie','رعب','مخيف','شبح','ظلام'],
        8  => ['mystery','thriller','detective','crime','murder','suspense','غموض','تشويق','محقق','جريمة'],
        9  => ['science fiction','sci-fi','space','robot','future','dystopia','خيال علمي','فضاء','روبوت'],
        10 => ['autobiography','biography','memoir','life story','سيرة','ذاتية','مذكرات'],
        11 => ['self help','success','motivation','habits','rich','wealth','leadership','تطوير','نجاح','ثروة','تحفيز'],
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
    $best    = key($scores);
    $best_id = $scores[$best] > 0 ? $best : 5;
    return ['category_id' => $best_id, 'category_name' => $cats[$best_id], 'score' => $scores[$best]];
}


$books = $pdo->query("
    SELECT b.id, b.title, b.author, b.description, b.category_id,
           c.name as cat_name
    FROM books b
    LEFT JOIN categories c ON b.category_id = c.id
    ORDER BY b.id ASC
")->fetchAll();

$total      = count($books);
$kw_correct = 0;
$cat_counts = [];
$table_rows = [];

foreach ($books as $book) {
    $text   = ($book['description'] ?? '') . ' ' . ($book['title'] ?? '');
    $db_cat = (int)($book['category_id'] ?? 5);
    $kw     = kwClassify($text);
    if ($kw['category_id'] == $db_cat) $kw_correct++;

    $cn = $book['cat_name'] ?? 'عام';
    $cat_counts[$cn] = ($cat_counts[$cn] ?? 0) + 1;

    $ml_data = null;
    if ($json_data) {
        foreach ($json_data['points'] as $p) {
            if ((int)$p['id'] === (int)$book['id']) { $ml_data = $p; break; }
        }
    }

    $table_rows[] = [
        'id'       => $book['id'],
        'title'    => $book['title'],
        'author'   => $book['author'],
        'db_cat'   => $cn,
        'kw_cat'   => $kw['category_name'],
        'kw_score' => $kw['score'],
        'kw_match' => ($kw['category_id'] == $db_cat),
        'ml_cat'   => $ml_data['ml_cat']   ?? '—',
        'ml_conf'  => $ml_data['ml_conf']  ?? 0,
        'ml_match' => $ml_data['ml_match'] ?? null,
    ];
}

$kw_acc     = $total > 0 ? round($kw_correct / $total * 100, 1) : 0;
$ml_acc     = $json_data['stats']['ml_acc']     ?? null;
$improvement= ($ml_acc !== null) ? round($ml_acc - $kw_acc, 1) : null;
$pca_points = $json_data['points'] ?? [];

$bar_labels_js = json_encode(array_keys($cat_counts),   JSON_UNESCAPED_UNICODE);
$bar_values_js = json_encode(array_values($cat_counts));
$pca_json      = json_encode($pca_points,               JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>إحصائيات التصنيف — مكتبة الثقافة</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
<style>
body { font-family:'Cairo',sans-serif; background:#f0f2f8; }
.navbar { background:linear-gradient(135deg,#667eea,#764ba2); }
.card   { border:none; border-radius:14px; box-shadow:0 2px 14px rgba(0,0,0,.07); }
.stat-card { color:#fff; border-radius:14px; padding:20px; text-align:center; }
.stat-card .num { font-size:2rem; font-weight:700; }
.stat-card .lbl { font-size:.8rem; opacity:.9; }
.section-title { border-right:5px solid #667eea; padding-right:10px; font-weight:700; margin-bottom:6px; }
.chart-sub { font-size:.78rem; color:#888; margin-bottom:14px; }
.match-y  { color:#28a745; font-weight:700; }
.match-n  { color:#dc3545; font-weight:700; }
.match-na { color:#aaa; }
.bdb { background:#6c757d;color:#fff;padding:2px 8px;border-radius:4px;font-size:.75em;display:inline-block; }
.bkw { background:#ffc107;color:#333;padding:2px 8px;border-radius:4px;font-size:.75em;display:inline-block; }
.bml { background:#667eea;color:#fff;padding:2px 8px;border-radius:4px;font-size:.75em;display:inline-block; }
.bok { background:#28a745;color:#fff;padding:2px 8px;border-radius:4px;font-size:.75em;display:inline-block; }
.bbd { background:#dc3545;color:#fff;padding:2px 8px;border-radius:4px;font-size:.75em;display:inline-block; }
.bar-compare { background:#fff;border-radius:14px;padding:18px 24px;box-shadow:0 2px 14px rgba(0,0,0,.07);margin-bottom:24px; }
.bar-row  { display:flex;align-items:center;gap:12px;margin-bottom:8px; }
.bar-lbl  { width:210px;font-size:.82rem;color:#555;flex-shrink:0; }
.bar-track{ flex:1;background:#f0f0f0;border-radius:20px;height:22px;overflow:hidden; }
.bar-fill { height:100%;border-radius:20px;transition:width 1.2s ease;display:flex;align-items:center;
            padding-right:10px;font-size:.75rem;font-weight:700;color:#fff; }
.bar-pct  { width:52px;font-weight:700;font-size:.88rem;text-align:right;flex-shrink:0; }
.legend   { display:flex;flex-wrap:wrap;gap:8px;margin-top:10px; }
.legend-item { display:flex;align-items:center;gap:5px;font-size:.75rem;color:#555; }
.legend-dot  { width:12px;height:12px;border-radius:50%;flex-shrink:0; }
.debug-box { background:#fff3cd;border:1px solid #ffc107;border-radius:8px;
             padding:12px 16px;font-size:.8rem;font-family:monospace;word-break:break-all; }
</style>
</head>
<body>

<nav class="navbar navbar-dark px-4 py-2 d-flex justify-content-between align-items-center">
    <span class="fw-bold fs-5">📊 إحصائيات التصنيف</span>
    <div class="d-flex gap-2">
        <a href="bulk_upload.php" class="btn btn-outline-light btn-sm">رفع جماعي</a>
        <a href="dashboard.php"   class="btn btn-outline-light btn-sm">لوحة التحكم</a>
        <a href="logout.php"      class="btn btn-danger btn-sm">خروج</a>
    </div>
</nav>

<div class="container-fluid px-4 py-4" style="max-width:1300px">

<?php if ($ml_error): ?>
<div class="debug-box mb-3">
    <strong>⚠️ تشخيص:</strong> <?= htmlspecialchars($ml_error) ?><br>
    <strong>Python:</strong> <?= htmlspecialchars($python_raw) ?><br>
    <strong>موجود:</strong> <?= file_exists($python_raw) ? '✅ نعم' : '❌ لا' ?><br>
    <strong>السكريبت:</strong> <?= htmlspecialchars($script_path) ?><br>
    <strong>موجود:</strong> <?= file_exists($script_path) ? '✅ نعم' : '❌ لا' ?>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">تحليل التصنيف الآلي للكتب</h4>
        <small class="text-muted">
            الكتب في المكتبة: <strong><?= $total ?></strong> —
            <?= $json_data
                ? '<span class="text-success fw-bold">✅ ML Model نشط</span>'
                : '<span class="text-warning fw-bold">⚠️ Keyword فقط</span>' ?>
        </small>
    </div>
    <button class="btn btn-sm btn-outline-primary" onclick="location.reload()">🔄 تحديث</button>
</div>

<!-- بطاقات -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#667eea,#764ba2)">
            <div class="num"><?= $total ?></div>
            <div class="lbl">كتاب في المكتبة</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#ffc107,#fd7e14)">
            <div class="num"><?= $kw_acc ?>%</div>
            <div class="lbl">دقة Keyword Scoring</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <?php if ($ml_acc !== null): ?>
        <div class="stat-card" style="background:linear-gradient(135deg,#28a745,#20c997)">
            <div class="num"><?= $ml_acc ?>%</div>
            <div class="lbl">دقة ML Model</div>
        </div>
        <?php else: ?>
        <div class="stat-card" style="background:#dee2e6">
            <div class="num" style="color:#888">—</div>
            <div class="lbl" style="color:#888">ML غير متاح</div>
        </div>
        <?php endif; ?>
    </div>
    <div class="col-6 col-md-3">
        <?php if ($improvement !== null): ?>
        <div class="stat-card" style="background:linear-gradient(135deg,#e74c3c,#c0392b)">
            <div class="num"><?= ($improvement >= 0 ? '+' : '') . $improvement ?>%</div>
            <div class="lbl">فرق ML عن Keywords</div>
        </div>
        <?php else: ?>
        <div class="stat-card" style="background:linear-gradient(135deg,#95a5a6,#7f8c8d)">
            <div class="num"><?= count($cat_counts) ?></div>
            <div class="lbl">فئات مستخدمة</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- شريط مقارنة -->
<?php if ($ml_acc !== null): ?>
<div class="bar-compare">
    <h6 class="fw-bold mb-3">⚖️ مقارنة طرق التصنيف على <?= $total ?> كتاب</h6>
    <div class="bar-row">
        <div class="bar-lbl">🔑 Keyword Scoring</div>
        <div class="bar-track">
            <div class="bar-fill" style="width:0%;background:linear-gradient(90deg,#ffc107,#fd7e14)"
                 data-val="<?= $kw_acc ?>"></div>
        </div>
        <div class="bar-pct" style="color:#fd7e14"><?= $kw_acc ?>%</div>
    </div>
    <div class="bar-row">
        <div class="bar-lbl">🤖 ML Model (Logistic Regression)</div>
        <div class="bar-track">
            <div class="bar-fill" style="width:0%;background:linear-gradient(90deg,#667eea,#764ba2)"
                 data-val="<?= $ml_acc ?>"></div>
        </div>
        <div class="bar-pct" style="color:#667eea"><?= $ml_acc ?>%</div>
    </div>
   
    </div>
</div>
<?php endif; ?>

<!-- المخططات -->
<div class="row g-3 mb-4">

    <div class="col-lg-5">
        <div class="card p-3">
            <div class="section-title">توزيع كتبك على الفئات</div>
            <div class="chart-sub">عدد الكتب الحقيقية في كل تصنيف</div>
            <canvas id="barChart" height="230"></canvas>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card p-3">
            <div class="section-title">نسب الفئات (Pie Chart)</div>
            <div class="chart-sub">النسبة المئوية لكل فئة من مجموع كتبك</div>
            <canvas id="pieChart" height="230"></canvas>
        </div>
    </div>

    <div class="col-12">
        <div class="card p-3">
            <div class="section-title">★ Scatter Plot — تجمعات كتبك (PCA)</div>
            <div class="chart-sub">
                كل نقطة = كتاب | اللون = فئته الحقيقية | مرّر الماوس للتفاصيل<br>
                <strong>PC1 (أفقي):</strong> يفصل حسب نوع المحتوى |
                <strong>PC2 (رأسي):</strong> يفصل حسب أسلوب الكتابة
            </div>
            <?php if (count($pca_points) >= 3): ?>
                <canvas id="scatterPCA" style="max-height:480px"></canvas>
                <div class="legend" id="pca-legend"></div>
            <?php elseif ($total < 3): ?>
                <div class="alert alert-info text-center py-4">
                    <strong>⚠️ تحتاج 3 كتب على الأقل</strong> — عندك <?= $total ?> الآن
                </div>
            <?php else: ?>
                <div class="alert alert-warning py-3">
                    <strong>⚠️ ML غير متاح</strong> — تحقق من صندوق التشخيص أعلاه
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- جدول -->
<div class="card p-3 mb-4">
    <div class="section-title">نتائج تفصيلية — كتبك الحقيقية</div>
    <small class="text-muted d-block mb-3">يتحدث تلقائياً عند إضافة كتب — اضغط تحديث</small>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle" style="font-size:.83rem">
            <thead class="table-dark">
                <tr>
                    <th>#</th><th>الكتاب</th><th>المؤلف</th>
                    <th>فئة DB</th><th>Keywords</th><th>Score</th><th>✓KW</th>
                    <?php if ($json_data): ?>
                    <th>ML Model</th><th>Conf%</th><th>✓ML</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($table_rows as $i => $r): ?>
            <tr>
                <td><?= $i+1 ?></td>
                <td><strong><?= htmlspecialchars($r['title'] ?? '') ?></strong></td>
                <td class="text-muted small"><?= htmlspecialchars($r['author'] ?? '') ?></td>
                <td><span class="bdb"><?= htmlspecialchars($r['db_cat']) ?></span></td>
                <td><span class="bkw"><?= htmlspecialchars($r['kw_cat']) ?></span></td>
                <td><span class="<?= $r['kw_score']>0?'bok':'bbd' ?>"><?= $r['kw_score'] ?></span></td>
                <td><?= $r['kw_match'] ? '<span class="match-y">✅</span>' : '<span class="match-n">❌</span>' ?></td>
                <?php if ($json_data): ?>
                <td><span class="bml"><?= htmlspecialchars($r['ml_cat']) ?></span></td>
                <td>
                    <?php $c = (float)$r['ml_conf']; ?>
                    <div class="progress" style="height:16px;min-width:70px">
                        <div class="progress-bar <?= $c>=50?'bg-success':($c>=25?'bg-warning':'bg-danger') ?>"
                             style="width:<?= min(100,$c) ?>%"><?= $c ?>%</div>
                    </div>
                </td>
                <td>
                    <?php if ($r['ml_match']===true)  echo '<span class="match-y">✅</span>';
                    elseif($r['ml_match']===false) echo '<span class="match-n">❌</span>';
                    else echo '<span class="match-na">—</span>'; ?>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const BAR_LABELS = <?= $bar_labels_js ?>;
const BAR_VALUES = <?= $bar_values_js ?>;
const PCA_DATA   = <?= $pca_json ?>;
const CAT_COLORS = {
    1:"#e74c3c",2:"#e67e22",3:"#27ae60",4:"#8e44ad",5:"#95a5a6",
    6:"#3498db",7:"#1abc9c",8:"#f39c12",9:"#2980b9",
    10:"#d35400",11:"#c0392b",12:"#16a085",13:"#2c3e50"
};
const PALETTE = Object.values(CAT_COLORS);


setTimeout(() => {
    document.querySelectorAll('.bar-fill').forEach(el => {
        const v = el.dataset.val;
        if (v) { el.style.width = v + '%'; el.textContent = v + '%'; }
    });
}, 400);

// Bar Chart
new Chart(document.getElementById('barChart'), {
    type:'bar',
    data:{ labels:BAR_LABELS, datasets:[{label:'كتاب',data:BAR_VALUES,
           backgroundColor:PALETTE,borderRadius:6}] },
    options:{ responsive:true,
        plugins:{ legend:{display:false},
            tooltip:{callbacks:{label:c=>` ${c.parsed.y} كتاب`}} },
        scales:{ y:{beginAtZero:true,ticks:{stepSize:1}},
                 x:{ticks:{font:{family:'Cairo',size:11}}} }
    }
});

// Pie Chart
new Chart(document.getElementById('pieChart'), {
    type:'pie',
    data:{ labels:BAR_LABELS, datasets:[{data:BAR_VALUES,backgroundColor:PALETTE}] },
    options:{ responsive:true,
        plugins:{ legend:{position:'right',labels:{font:{family:'Cairo',size:11},boxWidth:12}},
            tooltip:{callbacks:{label:c=>` ${c.label}: ${c.parsed} كتاب`}} }
    }
});

// Scatter PCA
if (PCA_DATA.length >= 3) {
    const catDS = {};
    PCA_DATA.forEach(p => {
        if (!catDS[p.cat_id]) catDS[p.cat_id] = {
            label: p.cat_name, data:[], tips:[],
            backgroundColor:(CAT_COLORS[p.cat_id]||'#888')+'CC',
            borderColor: CAT_COLORS[p.cat_id]||'#888',
            pointRadius:11, pointHoverRadius:15, borderWidth:1.5
        };
        catDS[p.cat_id].data.push({x:p.x, y:p.y});
        catDS[p.cat_id].tips.push(
            p.title + ' | ML:' + p.ml_cat + '(' + p.ml_conf + '%) | KW:' + p.kw_cat
        );
    });
    const datasets = Object.values(catDS);
    new Chart(document.getElementById('scatterPCA'), {
        type:'scatter', data:{datasets},
        options:{ responsive:true,
            plugins:{ legend:{display:false},
                tooltip:{callbacks:{label:ctx=>
                    ` ${datasets[ctx.datasetIndex].tips[ctx.dataIndex]}`
                }}
            },
            scales:{
                x:{title:{display:true,text:'PC1 — يفصل حسب نوع المحتوى',
                   font:{family:'Cairo',size:12}},grid:{color:'#f0f0f0'}},
                y:{title:{display:true,text:'PC2 — يفصل حسب أسلوب الكتابة',
                   font:{family:'Cairo',size:12}},grid:{color:'#f0f0f0'}}
            }
        }
    });
    const lg = document.getElementById('pca-legend');
    if (lg) datasets.forEach(ds => {
        lg.innerHTML += `<div class="legend-item">
            <div class="legend-dot" style="background:${ds.borderColor}"></div>
            <span>${ds.label}</span></div>`;
    });
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>