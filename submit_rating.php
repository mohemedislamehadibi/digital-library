<?php
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['user_id']) || !isset($_POST['book_id']) || !isset($_POST['rating'])) {
    echo "error";
    exit();
}

$user_id = $_SESSION['user_id'];
$book_id = (int)$_POST['book_id'];
$rating = (int)$_POST['rating'];

if ($rating < 1 || $rating > 5) exit();

// 1. إضافة أو تحديث التقييم
$stmt = $pdo->prepare("INSERT INTO ratings (user_id, book_id, rating) VALUES (?, ?, ?) 
                       ON DUPLICATE KEY UPDATE rating = ?");
$stmt->execute([$user_id, $book_id, $rating, $rating]);

// 2. حساب المتوسط الجديد للكتاب
$stmt_avg = $pdo->prepare("SELECT AVG(rating) as avg_r FROM ratings WHERE book_id = ?");
$stmt_avg->execute([$book_id]);
$new_avg = $stmt_avg->fetch()['avg_r'];

// 3. تحديث متوسط التقييم في جدول الكتب للسرعة
$stmt_update = $pdo->prepare("UPDATE books SET avg_rating = ? WHERE id = ?");
$stmt_update->execute([$new_avg, $book_id]);

echo "success";