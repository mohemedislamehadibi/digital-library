<?php

function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf_token($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    // ✅ نتحقق فقط — لا نحذف هنا
    return hash_equals($_SESSION['csrf_token'], $token);
}

function consume_csrf_token() {
    // ✅ نحذف التوكن فقط عند النجاح الكامل
    unset($_SESSION['csrf_token']);
}

function csrf_input() {
    $token = generate_csrf_token();
    return "<input type='hidden' name='csrf_token' value='" . htmlspecialchars($token) . "'>";
}