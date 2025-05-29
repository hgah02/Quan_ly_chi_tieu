<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập!']);
    exit;
}
$user_id = intval($_SESSION['user_id']);

// CSRF token check (nếu muốn bảo mật hơn, thêm vào form và kiểm tra ở đây)
// if ($_SERVER['REQUEST_METHOD'] === 'POST') {
//     if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
//         echo json_encode(['success' => false, 'message' => 'CSRF token không hợp lệ!']);
//         exit;
//     }
// }

// Đổi mật khẩu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $old = $_POST['old_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    if (!$old || !$new) {
        echo json_encode(['success' => false, 'message' => 'Thiếu thông tin!']);
        exit;
    }
    // Lấy mật khẩu cũ
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !password_verify($old, $row['password'])) {
        echo json_encode(['success' => false, 'message' => 'Mật khẩu hiện tại không đúng!']);
        exit;
    }
    // Đổi mật khẩu
    $hash = password_hash($new, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
    $ok = $stmt->execute([$hash, $user_id]);
    echo json_encode([
        'success' => $ok,
        'message' => $ok ? 'Đổi mật khẩu thành công!' : 'Lỗi khi đổi mật khẩu!'
    ]);
    exit;
}

// Lấy thông tin tài khoản
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_info') {
    $stmt = $pdo->prepare("SELECT username, email, created_at FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        // Escape output
        $username = htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8');
        $email = htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8');
        $created_at = htmlspecialchars($row['created_at'], ENT_QUOTES, 'UTF-8');
        echo json_encode([
            'success' => true,
            'username' => $username,
            'email' => $email,
            'created_at' => $created_at
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy tài khoản!']);
    }
    exit;
}

// Xóa tài khoản và toàn bộ dữ liệu liên quan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_account') {
    // Xóa chi tiêu
    $pdo->prepare("DELETE FROM expenses WHERE user_id = ?")->execute([$user_id]);
    // Xóa thu nhập
    $pdo->prepare("DELETE FROM incomes WHERE user_id = ?")->execute([$user_id]);
    // Xóa danh mục
    $pdo->prepare("DELETE FROM categories WHERE user_id = ?")->execute([$user_id]);
    // Xóa user
    $ok = $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
    // Hủy session
    session_unset();
    session_destroy();
    echo json_encode([
        'success' => $ok,
        'message' => $ok ? 'Đã xóa tài khoản và toàn bộ dữ liệu.' : 'Lỗi khi xóa tài khoản!'
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Yêu cầu không hợp lệ!']);
exit;
