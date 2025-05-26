<?php
session_start();
require_once 'db.php';

// Nếu dùng xuất Excel, require autoload và use ở đầu file
require '../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập!']);
    exit;
}
$user_id = intval($_SESSION['user_id']);

// Lấy 3 bản ghi gần nhất (cho dashboard)
if (($_GET['action'] ?? '') === 'recent') {
    $stmt = $pdo->prepare("SELECT id, amount, note, date FROM incomes WHERE user_id = :user_id ORDER BY date DESC, id DESC LIMIT 3");
    $stmt->execute(['user_id' => $user_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Lấy lịch sử thu nhập (phân trang, lọc cho trang history)
if (($_GET['action'] ?? '') === 'history') {
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = max(1, intval($_GET['per_page'] ?? 20));
    $offset = ($page - 1) * $per_page;

    $month = $_GET['month'] ?? '';
    $keyword = trim($_GET['keyword'] ?? '');

    $where = "user_id = :user_id";
    $params = ['user_id' => $user_id];

    if ($month) {
        $where .= " AND TO_CHAR(date, 'YYYY-MM') = :month";
        $params['month'] = $month;
    }
    if ($keyword) {
        $where .= " AND note ILIKE :keyword";
        $params['keyword'] = "%$keyword%";
    }

    // Đếm tổng
    $count = $pdo->prepare("SELECT COUNT(*) FROM incomes WHERE $where");
    $count->execute($params);
    $total = $count->fetchColumn();

    // Lấy dữ liệu
    $sql = "SELECT id, amount, date, note FROM incomes WHERE $where ORDER BY date DESC, id DESC LIMIT $per_page OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'total' => intval($total),
        'items' => $items
    ]);
    exit;
}

// Lấy tổng thu nhập tháng này
if (($_GET['action'] ?? '') === 'total') {
    $stmt = $pdo->prepare("SELECT SUM(amount) AS total FROM incomes WHERE user_id = :user_id AND DATE_TRUNC('month', date) = DATE_TRUNC('month', CURRENT_DATE)");
    $stmt->execute(['user_id' => $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['total' => $row['total'] ?? 0]);
    exit;
}

// Thêm mới thu nhập
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $amount = preg_replace('/\D/', '', $_POST['amount'] ?? '');
    $date = $_POST['date'] ?? '';
    $note = $_POST['note'] ?? '';
    if (!$amount || !$date || $amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Dữ liệu không hợp lệ!']);
        exit;
    }
    $stmt = $pdo->prepare("INSERT INTO incomes (user_id, amount, note, date, created_at, updated_at) VALUES (:user_id, :amount, :note, :date, NOW(), NOW())");
    $stmt->execute([
        'user_id' => $user_id,
        'amount' => $amount,
        'note' => $note,
        'date' => $date
    ]);
    echo json_encode(['success' => true, 'message' => 'Thêm thu nhập thành công!']);
    exit;
}

// Sửa thu nhập
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $id = $_POST['id'] ?? '';
    $amount = preg_replace('/\D/', '', $_POST['amount'] ?? '');
    $date = $_POST['date'] ?? '';
    $note = $_POST['note'] ?? '';
    if (!$id || !$amount || !$date || $amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Dữ liệu không hợp lệ!']);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE incomes SET amount = :amount, note = :note, date = :date, updated_at = NOW() WHERE id = :id AND user_id = :user_id");
    $stmt->execute([
        'amount' => $amount,
        'note' => $note,
        'date' => $date,
        'id' => $id,
        'user_id' => $user_id
    ]);
    echo json_encode(['success' => true, 'message' => 'Cập nhật thành công!']);
    exit;
}

// Xóa thu nhập
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = $_POST['id'] ?? '';
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Thiếu ID!']);
        exit;
    }
    $stmt = $pdo->prepare("DELETE FROM incomes WHERE id = :id AND user_id = :user_id");
    $stmt->execute([
        'id' => $id,
        'user_id' => $user_id
    ]);
    echo json_encode(['success' => true, 'message' => 'Đã xóa thành công!']);
    exit;
}

// Xuất Excel lịch sử thu nhập (lọc theo tháng nếu có)
if (($_GET['action'] ?? '') === 'export_excel') {
    $month = $_GET['month'] ?? '';
    $keyword = trim($_GET['keyword'] ?? '');

    $where = "user_id = :user_id";
    $params = ['user_id' => $user_id];
    if ($month) {
        $where .= " AND TO_CHAR(date, 'YYYY-MM') = :month";
        $params['month'] = $month;
    }
    if ($keyword) {
        $where .= " AND note ILIKE :keyword";
        $params['keyword'] = "%$keyword%";
    }
    $sql = "SELECT amount, date, note FROM incomes WHERE $where ORDER BY date DESC, id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['Số tiền', 'Ngày', 'Ghi chú'], NULL, 'A1');
    $sheet->fromArray($rows, NULL, 'A2');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="lich_su_thu_nhap.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

echo json_encode(['success' => false, 'message' => 'Yêu cầu không hợp lệ!']);
exit;