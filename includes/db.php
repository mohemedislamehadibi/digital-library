<?php
$host = '127.0.0.1';
$port = '3307'; // المنفذ الذي يظهر لديك في XAMPP
$dbname = 'library_db';
$username = 'root';
$password = '';

try {
    // تم إضافة المنفذ (port) داخل نص الاتصال لضمان الوصول للقاعدة
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );
    
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("SET NAMES utf8mb4");

} catch (PDOException $e) {
    // في حالة الفشل، سيقوم الكود بطباعة السبب الحقيقي الآن لنعرف إذا كان هناك نقص في البيانات
    error_log("DB Connection Error: " . $e->getMessage());
    
    // أثناء البرمجة، يفضل رؤية الخطأ الحقيقي:
    die("فشل الاتصال: " . $e->getMessage());
}
?>