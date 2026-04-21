<?php
session_start();
require_once '../includes/csrf.php';

if (isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db.php';

$message = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = "<div class='alert alert-danger'>طلب غير صالح. حاول مجدداً.</div>";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $message = "<div class='alert alert-danger'>يرجى ملء جميع الحقول.</div>";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_logged_in'] = true;
                $_SESSION['user_id']        = $user['id'];
                $_SESSION['username']       = $user['username'];
                if ($user && password_verify($password, $user['password'])) {
    session_regenerate_id(true);
    $_SESSION['user_logged_in'] = true;
    $_SESSION['user_id']        = $user['id'];
    $_SESSION['username']       = $user['username'];
    consume_csrf_token(); // ✅ أضف هذا
    header("Location: ../index.php");
    exit();
}
                header("Location: ../index.php");
                exit();
            } else {
                $message = "<div class='alert alert-danger'>بيانات غير صحيحة!</div>";
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
    <title>تسجيل الدخول</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; background: linear-gradient(135deg, #667eea, #764ba2); min-height: 100vh; display: flex; align-items: center; }
        .login-box { background: white; padding: 40px; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); max-width: 420px; width: 100%; }
        .btn-primary { background: #667eea; border: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-box mx-auto">
            <h2 class="text-center mb-4">تسجيل الدخول</h2>
            <?php echo $message; ?>
            <form method="POST">
                <?php echo csrf_input(); ?>
                <div class="mb-3">
                    <label class="form-label">اسم المستخدم</label>
                    <input type="text" name="username" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">كلمة المرور</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">دخول</button>
            </form>
            <div class="text-center mt-3">
                <a href="register.php">ليس لديك حساب؟ إنشاء حساب</a>
            </div>
        </div>
    </div>
</body>
</html>