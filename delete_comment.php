<?php
session_start();
require_once 'includes/db.php';

$comment_id = (int)($_GET['comment_id'] ?? 0);
$book_id    = (int)($_GET['book_id'] ?? 0);
$from       = $_GET['from'] ?? 'book';

// التحقق من صحة القيم
if ($comment_id <= 0 || $book_id <= 0) {
    header("Location: index.php");
    exit();
}

// التحقق من صحة قيمة from
$allowed = ['dashboard', 'book'];
$from = in_array($from, $allowed) ? $from : 'book';

$is_admin        = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$current_user_id = $_SESSION['user_id'] ?? 0;

// التحقق من وجود التعليق
$stmt = $pdo->prepare("SELECT user_id FROM comments WHERE id = ?");
$stmt->execute([$comment_id]);
$comment = $stmt->fetch();

if (!$comment) {
    header("Location: index.php");
    exit();
}

// التحقق من الصلاحية
if (!$is_admin && $comment['user_id'] != $current_user_id) {
    header("Location: book.php?id=$book_id");
    exit();
}

// الحذف
$stmt = $pdo->prepare("DELETE FROM comments WHERE id = ?");
$stmt->execute([$comment_id]);

// التوجيه الصحيح
if ($from === 'dashboard') {
    header("Location: admin/dashboard.php");
} else {
    header("Location: book.php?id=$book_id");
}
exit();
?>