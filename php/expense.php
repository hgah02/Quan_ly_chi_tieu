<?php
session_start();
require_once 'db.php'; // $pdo
require '../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập!']);
    exit;
}
$user_id = intval($_SESSION['user_id']);

// Hàm lấy số dư hiện tại
function get_balance($pdo, $user_id) {
    $income = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM incomes WHERE user_id = ?");
    $income->execute([$user_id]);
    $total_income = $income->fetchColumn();

    $expense = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE user_id = ?");
    $expense->execute([$user_id]);
    $total_expense = $expense->fetchColumn();

    return $total_income - $total_expense;
}

// Thêm chi tiêu mới
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    header('Content-Type: application/json');
    $amount = floatval($_POST['amount'] ?? 0);
    $category_id = intval($_POST['category_id'] ?? 0);
    $date = $_POST['date'] ?? '';
    $note = $_POST['note'] ?? '';

    // Kiểm tra category_id có tồn tại và thuộc user này không
    $stmt = $pdo->prepare("SELECT id FROM categories WHERE id = ? AND user_id = ?");
    $stmt->execute([$category_id, $user_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Danh mục không hợp lệ!']);
        exit;
    }

    // Kiểm tra số dư
    $balance = get_balance($pdo, $user_id);
    $warning = null;
    if ($amount > $balance) {
        $warning = 'Cảnh báo: Số dư không đủ để chi tiêu!';
    }

    // Thêm vào bảng Expenses
    $insert = $pdo->prepare("INSERT INTO expenses (user_id, category_id, amount, note, date) VALUES (?, ?, ?, ?, ?)");
    $ok = $insert->execute([$user_id, $category_id, $amount, $note, $date]);

    $new_balance = get_balance($pdo, $user_id);

    if ($ok) {
        echo json_encode([
            'success' => true,
            'message' => 'Thêm chi tiêu thành công!' . ($warning ? (' ' . $warning) : ''),
            'warning' => $warning,
            'balance' => $new_balance
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Lỗi khi thêm chi tiêu!',
            'balance' => $balance
        ]);
    }
    exit;
}

// API lấy số dư hiện tại
if (($_GET['action'] ?? '') === 'balance') {
    header('Content-Type: application/json');
    $balance = get_balance($pdo, $user_id);
    echo json_encode(['balance' => $balance]);
    exit;
}

// Thêm danh mục mới
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_category') {
    header('Content-Type: application/json');
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        echo json_encode(['success' => false, 'message' => 'Tên danh mục không được để trống!']);
        exit;
    }
    // Kiểm tra trùng tên cho user này (không phân biệt hoa thường)
    $stmt = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND LOWER(name) = LOWER(?)");
    $stmt->execute([$user_id, $name]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Danh mục đã tồn tại!']);
        exit;
    }
    $insert = $pdo->prepare("INSERT INTO categories (user_id, name) VALUES (?, ?)");
    $ok = $insert->execute([$user_id, $name]);
    if ($ok) {
        // Lấy lại toàn bộ danh mục mới nhất cho user này
        $cats = $pdo->prepare("SELECT id, name FROM categories WHERE user_id = ? ORDER BY id DESC");
        $cats->execute([$user_id]);
        $categories = $cats->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode([
            'success' => true,
            'message' => 'Thêm danh mục thành công!',
            'categories' => $categories
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Lỗi khi thêm danh mục!']);
    }
    exit;
}

// API lấy danh sách danh mục
if (($_GET['action'] ?? '') === 'categories') {
    header('Content-Type: application/json');
    $cats = $pdo->prepare("SELECT id, name FROM categories WHERE user_id = ? ORDER BY id DESC");
    $cats->execute([$user_id]);
    $categories = $cats->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['categories' => $categories]);
    exit;
}
// Xóa danh mục
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_category') {
    header('Content-Type: application/json');
    $cat_id = intval($_POST['id'] ?? 0);
    // Chỉ xóa nếu không có chi tiêu nào dùng danh mục này
    $check = $pdo->prepare("SELECT 1 FROM expenses WHERE category_id = ? LIMIT 1");
    $check->execute([$cat_id]);
    if ($check->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Không thể xóa danh mục đã có chi tiêu!']);
        exit;
    }
    $del = $pdo->prepare("DELETE FROM categories WHERE id = ? AND user_id = ?");
    $ok = $del->execute([$cat_id, $user_id]);
    // Trả về danh mục mới nhất
    $cats = $pdo->prepare("SELECT id, name FROM categories WHERE user_id = ? ORDER BY id DESC");
    $cats->execute([$user_id]);
    $categories = $cats->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode([
        'success' => $ok,
        'message' => $ok ? 'Xóa thành công!' : 'Lỗi khi xóa!',
        'categories' => $categories
    ]);
    exit;
}

// Lấy 3 bản ghi chi tiêu gần nhất (cho dashboard/trang expense)
if (($_GET['action'] ?? '') === 'recent') {
    header('Content-Type: application/json');
    $stmt = $pdo->prepare("SELECT e.*, c.name as category_name 
                            FROM expenses e JOIN categories c ON e.category_id = c.id 
                            WHERE e.user_id = ? ORDER BY e.date DESC, e.id DESC LIMIT 3");
    $stmt->execute([$user_id]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Khi trả về dữ liệu, escape các trường text nếu cần
    foreach ($data as &$item) {
        $item['note'] = htmlspecialchars($item['note'], ENT_QUOTES, 'UTF-8');
    }
    echo json_encode($data);
    exit;
}

// PHÂN TRANG lịch sử chi tiêu (cho trang history)
if (($_GET['action'] ?? '') === 'history') {
    header('Content-Type: application/json');
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = max(1, intval($_GET['per_page'] ?? 20));
    $offset = ($page - 1) * $per_page;

    $month = $_GET['month'] ?? '';
    $category = $_GET['category'] ?? '';
    $keyword = trim($_GET['keyword'] ?? '');

    $where = "e.user_id = ?";
    $params = [$user_id];

    if ($month) {
        $where .= " AND TO_CHAR(e.date, 'YYYY-MM') = ?";
        $params[] = $month;
    }
    if ($category) {
        $where .= " AND e.category_id = ?";
        $params[] = $category;
    }
    if ($keyword) {
        $where .= " AND e.note ILIKE ?";
        $params[] = "%$keyword%";
    }

    // Đếm tổng
    $count = $pdo->prepare("SELECT COUNT(*) FROM expenses e WHERE $where");
    $count->execute($params);
    $total = $count->fetchColumn();

    // Lấy dữ liệu (join để lấy tên danh mục)
    $sql = "SELECT e.id, e.amount, e.date, e.note, e.category_id, c.name AS category_name
            FROM expenses e
            LEFT JOIN categories c ON e.category_id = c.id
            WHERE $where
            ORDER BY e.date DESC, e.id DESC
            LIMIT $per_page OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Khi trả về dữ liệu, escape các trường text nếu cần
    foreach ($items as &$item) {
        $item['note'] = htmlspecialchars($item['note'], ENT_QUOTES, 'UTF-8');
    }

    echo json_encode([
        'total' => intval($total),
        'items' => $items
    ]);
    exit;
}

// Sửa chi tiêu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    header('Content-Type: application/json');
    $id = intval($_POST['id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $category_id = intval($_POST['category_id'] ?? 0);
    $date = $_POST['date'] ?? '';
    $note = $_POST['note'] ?? '';
    if (!$id || !$amount || !$category_id || !$date) {
        echo json_encode(['success' => false, 'message' => 'Dữ liệu không hợp lệ!']);
        exit;
    }
    // Kiểm tra category_id có thuộc user không
    $stmt = $pdo->prepare("SELECT id FROM categories WHERE id = ? AND user_id = ?");
    $stmt->execute([$category_id, $user_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Danh mục không hợp lệ!']);
        exit;
    }
    // Sửa lại câu lệnh UPDATE, bỏ updated_at
    $stmt = $pdo->prepare("UPDATE expenses SET amount = ?, category_id = ?, note = ?, date = ? WHERE id = ? AND user_id = ?");
    $stmt->execute([$amount, $category_id, $note, $date, $id, $user_id]);
    echo json_encode(['success' => true, 'message' => 'Cập nhật thành công!']);
    exit;
}

// Xóa chi tiêu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    header('Content-Type: application/json');
    $id = intval($_POST['id'] ?? 0);
    $del = $pdo->prepare("DELETE FROM expenses WHERE id = ? AND user_id = ?");
    $ok = $del->execute([$id, $user_id]);
    echo json_encode([
        'success' => $ok,
        'message' => $ok ? 'Xóa chi tiêu thành công!' : 'Lỗi khi xóa chi tiêu!'
    ]);
    exit;
}

// API lấy tổng chi tháng này
if (($_GET['action'] ?? '') === 'total') {
    header('Content-Type: application/json');
    $month = date('m');
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as total FROM expenses WHERE user_id = ? AND EXTRACT(MONTH FROM date) = ? AND EXTRACT(YEAR FROM date) = ?");
    $stmt->execute([$user_id, $month, $year]);
    $total = $stmt->fetchColumn();
    echo json_encode(['total' => $total]);
    exit;
}

// Xuất Excel lịch sử chi tiêu (cần cài phpoffice/phpspreadsheet)
if (($_GET['action'] ?? '') === 'export_excel') {

    $month = $_GET['month'] ?? '';
    $category = $_GET['category'] ?? '';
    $keyword = trim($_GET['keyword'] ?? '');

    $where = "e.user_id = ?";
    $params = [$user_id];
    if ($month) {
        $where .= " AND TO_CHAR(e.date, 'YYYY-MM') = ?";
        $params[] = $month;
    }
    if ($category) {
        $where .= " AND e.category_id = ?";
        $params[] = $category;
    }
    if ($keyword) {
        $where .= " AND e.note ILIKE ?";
        $params[] = "%$keyword%";
    }
    $sql = "SELECT e.amount, c.name as category, e.date, e.note
            FROM expenses e
            LEFT JOIN categories c ON e.category_id = c.id
            WHERE $where
            ORDER BY e.date DESC, e.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['Số tiền', 'Danh mục', 'Ngày', 'Ghi chú'], NULL, 'A1');
    $sheet->fromArray($rows, NULL, 'A2');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="lich_su_chi_tieu.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => 'Yêu cầu không hợp lệ!']);
exit;