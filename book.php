<?php
require_once 'includes/db.php';


$book_id = $_GET['id'] ?? 0;
if ($book_id <= 0) {
    header("Location: index.php");
    exit();
}


$stmt = $pdo->prepare("SELECT * FROM books WHERE id = ?");
$stmt->execute([$book_id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$book) {
    header("Location: index.php");
    exit();
}


$stmt = $pdo->prepare("UPDATE books SET views = views + 1 WHERE id = ?");
$stmt->execute([$book_id]);


$stmt = $pdo->prepare("SELECT * FROM comments WHERE book_id = ? ORDER BY created_at DESC");
$stmt->execute([$book_id]);
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);


$message = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_name = trim($_POST['user_name']);
    $comment_text = trim($_POST['comment']);

    if (empty($user_name) || empty($comment_text)) {
        $message = "<div class='alert alert-danger'>يرجى ملء الاسم والتعليق.</div>";
    } elseif (strlen($comment_text) < 5) {
        $message = "<div class='alert alert-danger'>التعليق قصير جداً.</div>";
    } else {
        $stmt = $pdo->prepare("INSERT INTO comments (book_id, user_name, comment, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$book_id, $user_name, $comment_text]);

       
        setcookie('commenter_name', $user_name, time() + (365 * 24 * 60 * 60), "/");

        $message = "<div class='alert alert-success'>تم إضافة تعليقك بنجاح!</div>";
       
        header("Location: book.php?id=$book_id");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($book['title']); ?> - مكتبة الثقافة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { font-family: 'Cairo', sans-serif; background: #f8f9fa; }
        .book-cover { max-width: 100%; height: auto; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .btn-read { background: #667eea; border: none; }
        .btn-download { background: #28a745; }
        .comment-box { background: white; padding: 15px; border-radius: 10px; margin-bottom: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand fs-4" href="index.php">مكتبة الثقافة</a>
            <a href="index.php" class="btn btn-outline-light">العودة للرئيسية</a>
        </div>
    </nav>

    <div class="container my-5">
        <div class="row">
           
            <div class="col-md-4 text-center">
                <?php if ($book['cover_image']): ?>
                    <img src="assets/uploads/covers/<?php echo htmlspecialchars($book['cover_image']); ?>" class="book-cover" alt="<?php echo htmlspecialchars($book['title']); ?>">
                <?php else: ?>
                    <div class="bg-secondary text-white d-flex align-items-center justify-content-center" style="height:500px; border-radius:15px;">
                        <h3>لا يوجد غلاف</h3>
                    </div>
                <?php endif; ?>
            </div>

           
            <div class="col-md-8">
                <h1 class="mb-3"><?php echo htmlspecialchars($book['title']); ?></h1>
                <p class="lead text-muted">المؤلف: <?php echo htmlspecialchars($book['author']); ?></p>
                <p class="lead text-muted">التصنيف: <?php echo htmlspecialchars($book['category']); ?></p>
                <p class="text-muted">تاريخ الإضافة: <?php echo date('Y-m-d', strtotime($book['created_at'])); ?></p>

                <div class="my-4">
                    <a href="view.php?id=<?php echo $book['id']; ?>" class="btn btn-read text-white btn-lg me-3">قراءة الكتاب</a>
                    <a href="increment_downloads.php?id=<?php echo $book['id']; ?>" class="btn btn-download text-white btn-lg" target="_blank">تحميل PDF</a>
                </div>

                <div class="mt-4">
                    <p>👁️ <?php echo $book['views']; ?> مشاهدة | ⬇️ <?php echo $book['downloads']; ?> تحميل</p>
                </div>
            </div>
        </div>

        
        <div class="row mt-5">
            <div class="col-12">
                <h3 class="mb-4">التعليقات (<?php echo count($comments); ?>)</h3>
                <?php echo $message; ?>

             
                <form method="POST" class="mb-5 p-4 bg-light rounded shadow">
                    <div class="mb-3">
                        <label class="form-label">اسمك</label>
                        <input type="text" name="user_name" class="form-control" value="<?php echo $_COOKIE['commenter_name'] ?? ''; ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">تعليقك</label>
                        <textarea name="comment" class="form-control" rows="4" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">أضف تعليقك</button>
                </form>

             
                <?php if (count($comments) > 0): ?>
                    <?php foreach ($comments as $comment): ?>
                        <div class="comment-box">
                            <strong><?php echo htmlspecialchars($comment['user_name']); ?></strong>
                            <small class="text-muted float-start"><?php echo date('Y-m-d H:i', strtotime($comment['created_at'])); ?></small>
                            <p class="mt-2"><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></p>
                            
                            <?php 
                            $current_commenter = $_COOKIE['commenter_name'] ?? '';
                            if ($current_commenter === $comment['user_name']): ?>
                                <a href="delete_comment.php?comment_id=<?php echo $comment['id']; ?>&book_id=<?php echo $book_id; ?>" 
                                   class="btn btn-danger btn-sm mt-2" 
                                   onclick="return confirm('هل أنت متأكد من حذف تعليقك؟');">
                                   حذف تعليقي
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted">لا توجد تعليقات بعد. كن أول من يعلق!</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function incrementDownloads(bookId) {
            fetch('increment_downloads.php?id=' + bookId);
        }
    </script>
</body>
</html>