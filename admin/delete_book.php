
<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

require_once '../includes/db.php';

// ============================================
// التحقق من معرّف الكتاب
// ============================================
$book_id = $_GET['id'] ?? 0;
if ($book_id <= 0) {
    $_SESSION['message'] = "⚠️ معرّف الكتاب غير صحيح";
    $_SESSION['message_type'] = "warning";
    header("Location: dashboard.php");
    exit();
}

try {
    // ============================================
    // جلب بيانات الكتاب
    // ============================================
    $stmt = $pdo->prepare("
        SELECT id, title, cover_image, pdf_file 
        FROM books 
        WHERE id = ?
    ");
    $stmt->execute([$book_id]);
    $book = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$book) {
        $_SESSION['message'] = "⚠️ الكتاب غير موجود";
        $_SESSION['message_type'] = "warning";
        header("Location: dashboard.php");
        exit();
    }

    // ============================================
    //  احذف السجلات من جدول import_queue أولاً
    // ============================================
    $stmt = $pdo->prepare("
        DELETE FROM import_queue 
        WHERE book_id = ?
    ");
    $stmt->execute([$book_id]);
    $queue_deleted = $stmt->rowCount();

    // ============================================
    //  احذف الملفات من النظام
    // ============================================
    $cover_path = '../assets/uploads/covers/' . $book['cover_image'];
    $pdf_path = '../assets/uploads/pdfs/' . $book['pdf_file'];

    // حذف صورة الغلاف
    if ($book['cover_image'] && $book['cover_image'] !== 'default_book.jpg' && file_exists($cover_path)) {
        @unlink($cover_path);
    }

    // حذف ملف PDF
    if ($book['pdf_file'] && file_exists($pdf_path)) {
        @unlink($pdf_path);
    }

    // ============================================
    //  احذف الكتاب من جدول books
    // ============================================
    $stmt = $pdo->prepare("
        DELETE FROM books 
        WHERE id = ?
    ");
    $stmt->execute([$book_id]);
    $book_deleted = $stmt->rowCount();

    // ============================================
    // رسالة النجاح
    // ============================================
    if ($book_deleted > 0) {
        $message = "✅ تم حذف الكتاب <b>" . htmlspecialchars($book['title']) . "</b> بنجاح!";
        
        if ($queue_deleted > 0) {
            $message .= "<br>🗑️ تم حذف <b>$queue_deleted</b> سجل من طابور المعالجة";
        }

        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = "⚠️ لم يتم حذف الكتاب";
        $_SESSION['message_type'] = "warning";
    }

} catch (PDOException $e) {
    // ============================================
    // معالجة أخطاء قاعدة البيانات
    // ============================================
    error_log("Delete Book Error: " . $e->getMessage());
    
    $_SESSION['message'] = "❌ خطأ قاعدة البيانات: " . htmlspecialchars($e->getMessage());
    $_SESSION['message_type'] = "danger";

} catch (Exception $e) {
    // ============================================
    // معالجة الأخطاء العامة
    // ============================================
    error_log("Delete Book Error: " . $e->getMessage());
    
    $_SESSION['message'] = "❌ حدث خطأ: " . htmlspecialchars($e->getMessage());
    $_SESSION['message_type'] = "danger";
}

// ============================================
// إعادة التوجيه
// ============================================
header("Location: dashboard.php");
exit();
?>