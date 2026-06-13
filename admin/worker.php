<?php


require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/BookProcessor.php';

set_time_limit(0);
ignore_user_abort(true);

$log_file = __DIR__ . '/worker_log.txt';

function log_worker($message) {
    global $log_file;
    $line = "[" . date('Y-m-d H:i:s') . "] $message\n";
    file_put_contents($log_file, $line, FILE_APPEND);
    if (php_sapi_name() === 'cli') {
        echo $line;
    }
}

try {
    $processor = new BookProcessor($pdo);
    log_worker(" Worker بدأ التشغيل");

    
    $processed = $processor->processQueue(5);

    if ($processed > 0) {
        log_worker(" تمت معالجة {$processed} كتاب");
    } else {
        log_worker("💤 لا توجد وظائف معلقة");
    }

    
$stats = $processor->getQueueStats();
log_worker(" الإحصائيات - الكلي: {$stats['total']}, المعلقة: {$stats['pending']}, المنجزة: {$stats['done']}, الفاشلة: {$stats['failed']}");

} catch (Exception $e) {
    log_worker("❌ خطأ: " . $e->getMessage());
    exit(1);
}
?>