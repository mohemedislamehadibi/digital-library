<?php
require_once 'includes/db.php';


$comment_id = $_GET['comment_id'] ?? 0;
$book_id = $_GET['book_id'] ?? 0;

if ($comment_id <= 0 || $book_id <= 0) {
    header("Location: book.php?id=$book_id");
    exit();
}


$current_commenter = $_COOKIE['commenter_name'] ?? '';


$stmt = $pdo->prepare("SELECT user_name FROM comments WHERE id = ?");
$stmt->execute([$comment_id]);
$comment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$comment || $comment['user_name'] !== $current_commenter) {
  
    header("Location: book.php?id=$book_id");
    exit();
}


$stmt = $pdo->prepare("DELETE FROM comments WHERE id = ?");
$stmt->execute([$comment_id]);


header("Location: book.php?id=$book_id");
exit();
?>