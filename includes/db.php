<?php
$host   = '127.0.0.1';
$port   = '3307';
$dbname = 'library_db';
$username = 'root';
$password = '';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("SET NAMES utf8mb4");

} catch (PDOException $e) {
    error_log("DB Connection Error: " . $e->getMessage());
    die("عذراً، حدث خطأ في الاتصال. يرجى المحاولة لاحقاً.");
}
?>