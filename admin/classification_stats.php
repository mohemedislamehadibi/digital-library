<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

require_once '../includes/db.php';

// ============================================================
// دالة autoClassify مع إرجاع جميع النتائج (scores كاملة)
// ============================================================
function autoClassifyFull($text) {
    $text = mb_strtolower($text, 'UTF-8');

    $categories = [
        1  => 'برمجة وتقنية',
        2  => 'تاريخ وحضارات',
        3  => 'علوم وطبيعة',
        4  => 'أدب وروايات',
        5  => 'عام',
        6  => 'فانتازيا',
        7  => 'رعب',
        8  => 'غموض وتشويق',
        9  => 'خيال علمي',
        10 => 'سيرة ذاتية',
        11 => 'تطوير الذات',
        12 => 'ديني وإسلامي',
        13 => 'سياسة واقتصاد',
    ];

    $keywords = [
        1  => ['software','programming','java','sql','code','python','database',
               'javascript','algorithms','computer','coding',
               'برمجة','كود','حاسوب','تقنية','بيانات','شبكات','ذكاء اصطناعي'],
        2  => ['history','war','ancient','century','battles','civilization',
               'empire','historical','medieval','ottoman','roman',
               'تاريخ','حرب','حضارة','قرن','معركة','دولة','خلافة','عثماني'],
        3  => ['math','physics','calculus','science','mathematics','algebra',
               'chemistry','biology','astronomy','universe','quantum',
               'علوم','فيزياء','رياضيات','كيمياء','أحياء','طب','هندسة','فلك'],
        4  => ['novel','story','drama','classic','literature','poetry',
               'prose','narrative','tale',
               'رواية','قصة','أدب','شعر','ديوان','مسرحية','حكاية','سيرة','نثر'],
        5  => ['general','عام'],
        6  => ['fantasy','magic','dragon','wizard','witch','spell','mythical',
               'فانتازيا','سحر','تنين','ساحر','خيال','أسطورة','مملكة'],
        7  => ['horror','scary','ghost','haunted','terror','nightmare',
               'demon','vampire','zombie','evil','darkness',
               'رعب','مخيف','شبح','ظلام','خوف','وحش','مسكون'],
        8  => ['mystery','thriller','detective','crime','murder','suspense',
               'investigation','sherlock','spy','secret',
               'غموض','تشويق','محقق','جريمة','قتل','سر','تحقيق','جاسوس'],
        9  => ['science fiction','sci-fi','space','robot','alien','dystopia',
               'dystopian','future','galaxy','spacecraft',
               'خيال علمي','فضاء','روبوت','مستقبل','مجرة','ديستوبيا'],
        10 => ['autobiography','biography','memoir','life story',
               'سيرة','ذاتية','مذكرات','حياة'],
        11 => ['self help','motivation','success','leadership','productivity',
               'mindset','habits','personal development',
               'تطوير','نجاح','قيادة','إنتاجية','عادات','أهداف','تحفيز'],
        12 => ['islam','quran','hadith','prophet','religious','faith',
               'إسلام','قرآن','حديث','نبي','دين','فقه','عقيدة','إيمان'],
        13 => ['politics','economy','government','democracy','economics',
               'capitalism','socialism','policy',
               'سياسة','اقتصاد','حكومة','ديمقراطية','رأسمالية','نظام'],
    ];

    $scores = [];
    foreach ($keywords as $id => $kwords) {
        $scores[$id] = 0;
        foreach ($kwords as $word) {
            if (strpos($text, $word) !== false) $scores[$id]++;
        }
    }

    $best_id    = 5;
    $best_score = 0;
    foreach ($scores as $id => $score) {
        if ($score > $best_score) {
            $best_score = $score;
            $best_id    = $id;
        }
    }

    return [
        'category_id'   => $best_id,
        'category_name' => $categories[$best_id],
        'score'         => $best_score,
        'all_scores'    => $scores,
        'categories'    => $categories,
    ];
}

// ============================================================
// جلب الكتب من DB وتشغيل autoClassify على كل كتاب
// ============================================================
$books_raw = $pdo->query("
    SELECT b.id, b.title, b.author, b.description, b.category_id,
           c.name as category_name
    FROM books b
    LEFT JOIN categories c ON b.category_id = c.id
    ORDER BY b.title ASC
")->fetchAll();

$classification_results = [];
$category_counts        = [];   // عدد الكتب في كل تصنيف
$scatter_data           = [];   // بيانات Scatter Plot
$correct_count          = 0;
$total_count            = count($books_raw);

foreach ($books_raw as $book) {
    $text   = ($book['description'] ?? '') . ' ' . ($book['title'] ?? '');
    $result = autoClassifyFull($text);

    // هل التصنيف المحسوب يطابق المخزون في DB؟
    $match = ($result['category_id'] == $book['category_id']);
    if ($match) $correct_count++;

    // تجميع عدد الكتب لكل تصنيف (من DB)
    $cat_name = $book['category_name'] ?? 'عام';
    if (!isset($category_counts[$cat_name])) {
        $category_counts[$cat_name] = 0;
    }
    $category_counts[$cat_name]++;

    // بيانات Scatter: x = category_id, y = score
    $scatter_data[] = [
        'x'     => $result['category_id'],
        'y'     => $result['score'],
        'title' => $book['title'],
        'cat'   => $result['category_name'],
    ];

    $classification_results[] = [
        'id'            => $book['id'],
        'title'         => $book['title'],
        'author'        => $book['author'],
        'db_category'   => $book['category_name'] ?? 'عام',
        'calc_category' => $result['category_name'],
        'score'         => $result['score'],
        'match'         => $match,
    ];
}

$accuracy = $total_count > 0
    ? round(($correct_count / $total_count) * 100, 1)
    : 0;

// ============================================================
// تحضير البيانات لـ Chart.js (JSON)
// ============================================================
$bar_labels  = array_keys($category_counts);
$bar_values  = array_values($category_counts);

$scatter_js = json_encode(array_map(function($p) {
    return ['x' => $p['x'], 'y' => $p['y']];
}, $scatter_data));

$scatter_tooltips = json_encode(array_map(function($p) {
    return $p['title'] . ' (' . $p['cat'] . ')';
}, $scatter_data));

$pie_labels = json_encode($bar_labels);
$pie_values = json_encode($bar_values);
$bar_labels_js = json_encode($bar_labels);
$bar_values_js = json_encode($bar_values);

// ألوان للمخططات
$colors = [
    '#667eea','#764ba2','#f093fb','#f5576c','#4facfe',
    '#00f2fe','#43e97b','#38f9d7','#fa709a','#fee140',
    '#a18cd1','#fbc2eb','#a1c4fd',
];
$colors_js = json_encode($colors);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إحصائيات التصنيف — مكتبة الثقافة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body        { font-family: 'Cairo', sans-serif; background: #f4f6fb; }
        .navbar     { background: #667eea; }
        .card       { border: none; border-radius: 14px; }
        .stat-num   { font-size: 2rem; font-weight: 700; }
        .match-yes  { color: #28a745; font-weight: 700; }
        .match-no   { color: #dc3545; font-weight: 700; }
        canvas      { max-height: 380px; }
        .section-title {
            border-right: 5px solid #667eea;
            padding-right: 12px;
            margin-bottom: 1rem;
            font-weight: 700;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="dashboard.php">مكتبة الثقافة</a>
        <div>
            <a href="dashboard.php" class="btn btn-outline-light btn-sm me-2">لوحة التحكم</a>
            <a href="logout.php" class="btn btn-danger btn-sm">خروج</a>
        </div>
    </div>
</nav>

<div class="container my-5">

    <h2 class="mb-1">📊 إحصائيات التصنيف التلقائي</h2>
   

    <!-- ── بطاقات ملخص ── -->
    <div class="row g-3 mb-5">
        <div class="col-md-3">
            <div class="card shadow-sm p-3 text-center" style="background:#667eea;color:white;">
                <div class="stat-num"><?php echo $total_count; ?></div>
                <div>إجمالي الكتب المحللة</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm p-3 text-center" style="background:#28a745;color:white;">
                <div class="stat-num"><?php echo $correct_count; ?></div>
                <div>تصنيف صحيح</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm p-3 text-center" style="background:#dc3545;color:white;">
                <div class="stat-num"><?php echo $total_count - $correct_count; ?></div>
                <div>تصنيف مختلف</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm p-3 text-center" style="background:#764ba2;color:white;">
                <div class="stat-num"><?php echo $accuracy; ?>%</div>
                <div>نسبة التطابق</div>
            </div>
        </div>
    </div>

    <!-- ── المخططات ── -->
    <div class="row g-4 mb-5">

        <!-- Bar Chart -->
        <div class="col-lg-6">
            <div class="card shadow-sm p-4">
                <h5 class="section-title">توزيع الكتب على التصنيفات</h5>
                <canvas id="barChart"></canvas>
            </div>
        </div>

        <!-- Pie Chart -->
        <div class="col-lg-6">
            <div class="card shadow-sm p-4">
                <h5 class="section-title">نسب التصنيفات (Pie Chart)</h5>
                <canvas id="pieChart"></canvas>
            </div>
        </div>

        <!-- Scatter Plot -->
        <div class="col-12">
            <div class="card shadow-sm p-4">
                <h5 class="section-title">Scatter Plot — توزيع نقاط الكتب (Category ID × Score)</h5>
                <p class="text-muted small mb-3">
                    المحور الأفقي = رقم التصنيف (1-13) | المحور الرأسي = عدد الكلمات المفتاحية المطابقة (score)
                    | كل نقطة = كتاب واحد
                </p>
                <canvas id="scatterChart" style="max-height:420px;"></canvas>
            </div>
        </div>

    </div>

    <!-- ── جدول تفصيلي ── -->
    <div class="card shadow-sm p-4">
        <h5 class="section-title">نتائج التصنيف التفصيلية لكل كتاب</h5>
        <div class="table-responsive">
            <table class="table table-hover table-striped align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>عنوان الكتاب</th>
                        <th>المؤلف</th>
                        <th>تصنيف DB</th>
                        <th>تصنيف الخوارزمية</th>
                        <th>Score</th>
                        <th>تطابق</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classification_results as $i => $r): ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td><strong><?php echo htmlspecialchars($r['title']); ?></strong></td>
                        <td><?php echo htmlspecialchars($r['author']); ?></td>
                        <td>
                            <span class="badge bg-secondary">
                                <?php echo htmlspecialchars($r['db_category']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-primary">
                                <?php echo htmlspecialchars($r['calc_category']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?php echo $r['score'] > 0 ? 'bg-success' : 'bg-warning text-dark'; ?>">
                                <?php echo $r['score']; ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($r['match']): ?>
                                <span class="match-yes">✅ متطابق</span>
                            <?php else: ?>
                                <span class="match-no">❌ مختلف</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /container -->

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const COLORS  = <?php echo $colors_js; ?>;
const barLbls = <?php echo $bar_labels_js; ?>;
const barVals = <?php echo $bar_values_js; ?>;
const pieLbls = <?php echo $pie_labels; ?>;
const pieVals = <?php echo $pie_values; ?>;
const scatterPts  = <?php echo $scatter_js; ?>;
const scatterTips = <?php echo $scatter_tooltips; ?>;

// ── Bar Chart ──
new Chart(document.getElementById('barChart'), {
    type: 'bar',
    data: {
        labels: barLbls,
        datasets: [{
            label: 'عدد الكتب',
            data: barVals,
            backgroundColor: COLORS,
            borderRadius: 6,
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: ctx => ` ${ctx.parsed.y} كتاب`
                }
            }
        },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 } },
            x: { ticks: { font: { family: 'Cairo' } } }
        }
    }
});

// ── Pie Chart ──
new Chart(document.getElementById('pieChart'), {
    type: 'pie',
    data: {
        labels: pieLbls,
        datasets: [{
            data: pieVals,
            backgroundColor: COLORS,
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                position: 'right',
                labels: { font: { family: 'Cairo', size: 12 }, boxWidth: 14 }
            },
            tooltip: {
                callbacks: {
                    label: ctx => ` ${ctx.label}: ${ctx.parsed} كتاب`
                }
            }
        }
    }
});

// ── Scatter Plot ──
new Chart(document.getElementById('scatterChart'), {
    type: 'scatter',
    data: {
        datasets: [{
            label: 'كتاب',
            data: scatterPts,
            backgroundColor: scatterPts.map((_, i) =>
                COLORS[i % COLORS.length] + 'CC'
            ),
            pointRadius: 8,
            pointHoverRadius: 11,
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: ctx => {
                        const tip = scatterTips[ctx.dataIndex] || '';
                        return ` ${tip} — Score: ${ctx.parsed.y}`;
                    }
                }
            }
        },
        scales: {
            x: {
                title: {
                    display: true,
                    text: 'رقم التصنيف (Category ID)',
                    font: { family: 'Cairo', size: 13 }
                },
                min: 0,
                max: 14,
                ticks: { stepSize: 1 }
            },
            y: {
                title: {
                    display: true,
                    text: 'نقاط التصنيف (Score)',
                    font: { family: 'Cairo', size: 13 }
                },
                beginAtZero: true,
                ticks: { stepSize: 1 }
            }
        }
    }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
