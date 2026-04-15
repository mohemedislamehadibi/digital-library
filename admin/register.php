<?php
session_start();

if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true) {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db.php';

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "<div class='alert alert-danger'>طلب غير صالح.</div>";
    } else {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (empty($username) || empty($email) || empty($password)) {
            $message = "<div class='alert alert-danger'>يرجى ملء جميع الحقول</div>";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "<div class='alert alert-danger'>صيغة البريد الإلكتروني غير صحيحة</div>";
        } elseif ($password !== $confirm) {
            $message = "<div class='alert alert-danger'>كلمة المرور غير متطابقة</div>";
        } elseif (strlen($password) < 6) {
            $message = "<div class='alert alert-danger'>كلمة المرور يجب أن تكون 6 أحرف على الأقل</div>";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
            try {
                $stmt->execute([$username, $email, $hashed]);
                unset($_SESSION['csrf_token']);
                $message = "<div class='alert alert-success'>تم التسجيل بنجاح! <a href='user_login.php'>تسجيل الدخول</a></div>";
            } catch (PDOException $e) {
                $message = "<div class='alert alert-danger'>اسم المستخدم أو البريد الإلكتروني مستخدم مسبقاً</div>";
            }
        }
    }
}

$csrf_token = generateCsrfToken();
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل حساب جديد</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; background: linear-gradient(135deg, #667eea, #764ba2); min-height: 100vh; display: flex; align-items: center; }
        .register-box { background: white; padding: 40px; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); max-width: 450px; width: 100%; }
        .btn-primary { background: #667eea; border: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="register-box mx-auto">
            <h2 class="text-center mb-4">إنشاء حساب جديد</h2>
            <?php echo $message; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="mb-3">
                    <label class="form-label">اسم المستخدم</label>
                    <input type="text" name="username" class="form-control"
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">البريد الإلكتروني</label>
                    <input type="email" name="email" class="form-control"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">كلمة المرور (6 أحرف على الأقل)</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">تأكيد كلمة المرور</label>
                    <input type="password" name="confirm_password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">تسجيل حساب جديد</button>
            </form>
            <div class="text-center mt-3">
                <a href="user_login.php">لديك حساب بالفعل؟ تسجيل الدخول</a>
            </div>
        </div>
    </div>
</body>
</html>