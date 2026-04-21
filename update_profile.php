<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/csrf.php';

if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: profile.php");
    exit();
}

if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    header("Location: profile.php?error=invalid_request");
    exit();
}

$user_id      = $_SESSION['user_id'];
$new_username = trim($_POST['username'] ?? '');
$new_email    = trim($_POST['email'] ?? '');
$new_password = $_POST['new_password'] ?? '';
$confirm_pass = $_POST['confirm_password'] ?? '';

if (empty($new_username) || empty($new_email)) {
    header("Location: profile.php?error=empty");
    exit();
}

if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
    header("Location: profile.php?error=invalid_email");
    exit();
}

if (!empty($new_password)) {
    if (strlen($new_password) < 6) {
        header("Location: profile.php?error=short_password");
        exit();
    }
    if ($new_password !== $confirm_pass) {
        header("Location: profile.php?error=password_mismatch");
        exit();
    }
}

try {
    $check = $pdo->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
    $check->execute([$new_username, $new_email, $user_id]);

    if ($check->fetch()) {
        header("Location: profile.php?error=exists");
        exit();
    }

    $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
    $stmt->execute([$new_username, $new_email, $user_id]);

    if (!empty($new_password)) {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $user_id]);
    }

    $_SESSION['username'] = $new_username;
    consume_csrf_token(); // ✅ نجاح — احذف التوكن
    header("Location: profile.php?status=updated");
    exit();

} catch (PDOException $e) {
    error_log("Update Profile Error: " . $e->getMessage());
    header("Location: profile.php?error=exists");
    exit();
}
?>