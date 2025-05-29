<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? '');
    $email = $_POST["email"] ?? '';
    $password = $_POST["password"] ?? '';
    $confirm_password = $_POST["confirm_password"] ?? '';

    if (!$username || !$password || !$confirm_password) {
        echo json_encode(['success' => false, 'message' => 'Vui lòng nhập đầy đủ thông tin!']);
        exit;
    }
    // Kiểm tra username: chỉ chữ và số, có cả chữ và số, không ký tự đặc biệt, tối thiểu 6 ký tự
    if (!preg_match('/^(?=.*[a-zA-Z])(?=.*\d)[a-zA-Z\d]{6,32}$/', $username)) {
        echo json_encode(['success' => false, 'message' => 'Tên đăng nhập phải từ 6 ký tự, chỉ gồm chữ và số, và phải có cả chữ lẫn số.']);
        exit;
    }
    // Kiểm tra password: tối thiểu 6 ký tự, có cả chữ và số
    if (!preg_match('/^(?=.*[a-zA-Z])(?=.*\d).{6,32}$/', $password)) {
        echo json_encode(['success' => false, 'message' => 'Mật khẩu phải từ 6 ký tự, có cả chữ và số.']);
        exit;
    }
    if ($password !== $confirm_password) {
        echo json_encode(['success' => false, 'message' => 'Mật khẩu nhập lại không khớp.']);
        exit;
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username OR email = :email");
    $stmt->execute(['username' => $username, 'email' => $email]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => false, 'message' => 'Tên đăng nhập hoặc email đã tồn tại.']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO users (username, password, email, created_at) VALUES (:username, :password, :email, NOW())");
    $stmt->execute([
        'username' => $username,
        'password' => $hashedPassword,
        'email' => $email
    ]);

    echo json_encode(['success' => true, 'message' => 'Đăng ký thành công!']);
    exit;
}
echo json_encode(['success' => false, 'message' => 'Yêu cầu không hợp lệ.']);
exit;
?>