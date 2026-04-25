<?php
/**
 * ml_test.php — صفحة تشخيص ML
 * ضعه في: C:\xampp\htdocs\library\ml_test.php
 * افتحه في المتصفح: localhost/library/ml_test.php
 */

require_once __DIR__ . '/includes/MLClassifier.php';

$ml   = new MLClassifier();
$diag = $ml->diagnose();

// اختبار تصنيف حقيقي
$test_books = [
    ['Think and Grow Rich',       'success wealth motivation habits leadership'],
    ['Dracula',                    'vampire horror gothic terror night castle'],
    ['The Adventures of Sherlock Holmes', 'detective mystery crime investigation clues'],
    ['Dune',                       'space planet future sci-fi desert galaxy robot'],
    ['Harry Potter',               'magic wizard fantasy dragon spell school'],
    ['Python Programming',         'code software programming database algorithms'],
];

$results = [];
foreach ($test_books as [$title, $desc]) {
    $results[] = array_merge(
        ['title' => $title],
        $ml->predict($title, $desc)
    );
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تشخيص ML</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
</head>
<body class="p-4 bg-light">
<div class="container">

    <h2 class="mb-4">🔍 تشخيص نظام ML</h2>

    <!-- حالة النظام -->
    <div class="card mb-4 shadow-sm">
        <div class="card-header fw-bold">⚙️ حالة النظام</div>
        <div class="card-body">
            <table class="table table-sm">
                <tr>
                    <td>مسار Python</td>
                    <td><code><?= htmlspecialchars($diag['python_exe']) ?></code></td>
                    <td><?= $diag['python_version'] ? '✅ ' . htmlspecialchars($diag['python_version']) : '❌ غير موجود' ?></td>
                </tr>
                <tr>
                    <td>مجلد ml_classifier</td>
                    <td><code><?= htmlspecialchars($diag['ml_dir']) ?></code></td>
                    <td><?= $diag['ml_dir'] ? '✅' : '❌ غير موجود' ?></td>
                </tr>
                <tr>
                    <td>model.pkl</td>
                    <td></td>
                    <td><?= $diag['model_exists']   ? '✅ موجود' : '❌ مفقود' ?></td>
                </tr>
                <tr>
                    <td>vectorizer.pkl</td>
                    <td></td>
                    <td><?= $diag['vec_exists']     ? '✅ موجود' : '❌ مفقود' ?></td>
                </tr>
                <tr>
                    <td>predict.py</td>
                    <td></td>
                    <td><?= $diag['predict_exists'] ? '✅ موجود' : '❌ مفقود' ?></td>
                </tr>
                <tr class="table-<?= $diag['available'] ? 'success' : 'danger' ?>">
                    <td><strong>ML متاح؟</strong></td>
                    <td></td>
                    <td><strong><?= $diag['available'] ? '✅ نعم — ML يعمل' : '❌ لا — سيستخدم Keywords' ?></strong></td>
                </tr>
            </table>

            <?php if (!empty($diag['test_output'])): ?>
            <div class="mt-2">
                <strong>مخرج اختبار predict.py:</strong>
                <pre class="bg-dark text-light p-2 rounded mt-1"><?= htmlspecialchars($diag['test_output']) ?></pre>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- نتائج التصنيف -->
    <div class="card shadow-sm">
        <div class="card-header fw-bold">🧪 اختبار التصنيف</div>
        <div class="card-body">
            <table class="table table-striped">
                <thead class="table-dark">
                    <tr>
                        <th>الكتاب</th>
                        <th>التصنيف</th>
                        <th>الثقة</th>
                        <th>المصدر</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['title']) ?></td>
                        <td><span class="badge bg-primary"><?= htmlspecialchars($r['category_name'] ?? '?') ?></span></td>
                        <td>
                            <?php $conf = round(($r['confidence'] ?? 0) * 100); ?>
                            <div class="progress" style="width:100px">
                                <div class="progress-bar bg-<?= $conf >= 50 ? 'success' : ($conf >= 25 ? 'warning' : 'danger') ?>"
                                     style="width:<?= max($conf,5) ?>%"><?= $conf ?>%</div>
                            </div>
                        </td>
                        <td>
                            <?php $src = $r['source'] ?? 'keywords'; ?>
                            <span class="badge bg-<?= $src === 'ml_model' ? 'success' : 'secondary' ?>">
                                <?= $src === 'ml_model' ? '🤖 ML' : '🔑 Keywords' ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!$diag['available']): ?>
    <div class="alert alert-warning mt-4">
        <h5>⚠️ ML لا يعمل — الأسباب المحتملة:</h5>
        <ol>
            <li>Python غير موجود في المسار المحدد — تحقق من مسار Python على جهازك</li>
            <li>ملفات model.pkl أو vectorizer.pkl مفقودة — شغّل <code>python train.py</code></li>
            <li>predict.py غير موجود في مجلد ml_classifier</li>
        </ol>
        <hr>
        <p>للتحقق من مسار Python على جهازك، افتح CMD واكتب:</p>
        <pre>where python</pre>
        <p>ثم انسخ المسار وضعه في <code>MLClassifier.php</code> داخل مصفوفة <code>$windows_paths</code></p>
    </div>
    <?php endif; ?>

</div>
</body>
</html>
