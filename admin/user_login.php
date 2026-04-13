<?php
session_start();

if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true) {
    header("Location: ../book.php");
    exit();
}

require_once '../includes/db.php';

$message = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $message = "<div class='alert alert-danger text-center'>يرجى إدخال اسم المستخدم وكلمة المرور</div>";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_logged_in'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            
            header("Location: ../book.php");
            exit();
        } else {
            $message = "<div class='alert alert-danger text-center'>اسم المستخدم أو كلمة المرور غير صحيحة!</div>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل دخول المستخدم</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Cairo', sans-serif; 
            background: linear-gradient(135deg, #667eea, #764ba2); 
            height: 100vh; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
        }
        .login-box { 
            background: white; 
            padding: 40px; 
            border-radius: 15px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.3); 
            width: 100%; 
            max-width: 400px; 
        }
    </style>
</head>
<body>
    <div class="login-box">
        <h2 class="text-center mb-4">تسجيل دخول المستخدم</h2>
        <?php echo $message; ?>
        
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">اسم المستخدم</label>
                <input type="text" name="username" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">كلمة المرور</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary w-100 py-2">تسجيل الدخول</button>
        </form>

        <div class="text-center mt-3">
            <a href="register.php">ليس لديك حساب؟ سجل الآن</a>
        </div>
    </div>
</body>
</html>