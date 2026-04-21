<?php
// 1. بدء الجلسة في أول السطر
session_start();

// 2. استدعاء الملفات الأساسية
require_once '../includes/csrf.php';
require_once '../includes/db.php';

// 3. إذا كان الأدمن مسجلاً للدخول مسبقاً، حوله للوحة التحكم
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header("Location: dashboard.php");
    exit();
}

$message = "";

// 4. معالجة طلب تسجيل الدخول
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // التحقق من توكن CSRF (المفتاح الأمني)
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = "<div class='alert alert-danger text-center'>فشل التحقق الأمني (CSRF). حاول مجدداً.</div>";
    } else {
        $input_username = trim($_POST['username'] ?? '');
        $input_password = $_POST['password'] ?? '';

        if (empty($input_username) || empty($input_password)) {
            $message = "<div class='alert alert-danger text-center'>يرجى إدخال اسم المستخدم وكلمة المرور.</div>";
        } else {
            // جلب بيانات المسؤول من القاعدة
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
            $stmt->execute([$input_username]);
            $admin = $stmt->fetch();

            // التحقق من صحة البيانات
            if ($admin && password_verify($input_password, $admin['password'])) {
                
                // إجراءات أمنية للجلسة
                session_regenerate_id(true);
                
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_username']  = $admin['username'];
                
                // استهلاك التوكن لمرة واحدة (اختياري حسب مكتبة csrf لديك)
                if (function_exists('consume_csrf_token')) {
                    consume_csrf_token();
                }

                header("Location: dashboard.php");
                exit();
            } else {
                $message = "<div class='alert alert-danger text-center'>بيانات الدخول غير صحيحة!</div>";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل دخول الإدارة - مكتبة الثقافة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; background: linear-gradient(135deg, #667eea, #764ba2); height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; }
        .login-box { background: white; padding: 40px; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); width: 100%; max-width: 400px; }
        .btn-primary { background: #667eea; border: none; padding: 10px; font-weight: bold; }
        .btn-primary:hover { background: #5a6fd6; }
        .form-label { font-weight: bold; color: #444; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2 class="text-center mb-4">تسجيل دخول الإدارة</h2>
        
        <?php echo $message; ?>

        <form method="POST" action="login.php">
            <?php echo csrf_input(); ?>

            <div class="mb-3">
                <label class="form-label">اسم المستخدم</label>
                <input type="text" name="username" class="form-control" placeholder="أدخل اسم المستخدم" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label">كلمة المرور</label>
                <input type="password" name="password" class="form-control" placeholder="أدخل كلمة المرور" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">دخول النظام</button>
        </form>
    </div>
</body>
</html>