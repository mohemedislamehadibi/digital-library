<?php
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) {
    header("Location: user_login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$book_id = (int)$_GET['id'];

// التحقق إذا كان الكتاب موجوداً أصلاً في المفضلة
$stmt = $pdo->prepare("SELECT id FROM favorites WHERE user_id = ? AND book_id = ?");
$stmt->execute([$user_id, $book_id]);
$fav = $stmt->fetch();

if ($fav) {
    // إذا وجده، يقوم بحذفه
    $stmt = $pdo->prepare("DELETE FROM favorites WHERE id = ?");
    $stmt->execute([$fav['id']]);
} else {
    // إذا لم يجده، يقوم بإضافته
    $stmt = $pdo->prepare("INSERT INTO favorites (user_id, book_id) VALUES (?, ?)");
    $stmt->execute([$user_id, $book_id]);
}

// العودة لصفحة الكتاب
header("Location: book.php?id=" . $book_id);
exit();