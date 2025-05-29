<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập!']);
    exit;
}
$user_id = intval($_SESSION['user_id']);

$quarter = intval($_GET['quarter'] ?? 1);
$year = intval($_GET['year'] ?? date('Y'));

// Lấy tháng bắt đầu/kết thúc của quý
$start_month = ($quarter - 1) * 3 + 1;
$end_month = $start_month + 2;

// Tổng thu nhập
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM incomes WHERE user_id = ? AND EXTRACT(YEAR FROM date) = ? AND EXTRACT(MONTH FROM date) BETWEEN ? AND ?");
$stmt->execute([$user_id, $year, $start_month, $end_month]);
$totalIncome = floatval($stmt->fetchColumn());

// Tổng chi tiêu
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE user_id = ? AND EXTRACT(YEAR FROM date) = ? AND EXTRACT(MONTH FROM date) BETWEEN ? AND ?");
$stmt->execute([$user_id, $year, $start_month, $end_month]);
$totalExpense = floatval($stmt->fetchColumn());

// Số dư cuối kỳ (giả sử số dư đầu kỳ = 0)
$finalBalance = $totalIncome - $totalExpense;

// Thống kê chi tiêu theo danh mục (tối đa 5 mục, gộp "Khác")
$stmt = $pdo->prepare("
    SELECT c.name, COALESCE(SUM(e.amount),0) as amount
    FROM categories c
    LEFT JOIN expenses e ON e.category_id = c.id AND e.user_id = ? AND EXTRACT(YEAR FROM e.date) = ? AND EXTRACT(MONTH FROM e.date) BETWEEN ? AND ?
    WHERE c.user_id = ?
    GROUP BY c.id, c.name
    HAVING COALESCE(SUM(e.amount),0) > 0
    ORDER BY amount DESC
");
$stmt->execute([$user_id, $year, $start_month, $end_month, $user_id]);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lấy tối đa 5 danh mục, gộp phần còn lại vào "Khác"
$categoryStats = [];
$otherAmount = 0;
$totalIncomeForPercent = $totalIncome > 0 ? $totalIncome : 1;
foreach ($categories as $i => $cat) {
    if ($i < 5) {
        $percent = round($cat['amount'] / $totalIncomeForPercent * 100, 1);
        $categoryStats[] = [
            'name' => $cat['name'],
            'amount' => floatval($cat['amount']),
            'percent' => $percent
        ];
    } else {
        $otherAmount += floatval($cat['amount']);
    }
}
if ($otherAmount > 0) {
    $percent = round($otherAmount / $totalIncomeForPercent * 100, 1);
    $categoryStats[] = [
        'name' => 'Khác',
        'amount' => $otherAmount,
        'percent' => $percent
    ];
}

// ======= XUẤT PDF =======
if (isset($_GET['action']) && $_GET['action'] === 'export_pdf') {
    $autoloadPath = dirname(__DIR__, 1) . '/vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        echo "Lỗi: Chưa cài đặt mPDF. Vui lòng chạy <b>composer require mpdf/mpdf</b> trong thư mục gốc dự án.";
        exit;
    }
    require_once $autoloadPath;
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'default_font' => 'dejavusans'
    ]);
    $saving = max(0, $finalBalance);
    $savingPercent = $totalIncome > 0 ? round($saving / $totalIncome * 100) : 0;

    // Chuẩn bị dữ liệu biểu đồ
    $pieLabels = array_map(function($cat) { return $cat['name']; }, $categoryStats);
    $pieData = array_map(function($cat) { return $cat['amount']; }, $categoryStats);
    $pieLabels[] = 'Tiết kiệm';
    $pieData[] = $saving;

    // Tạo URL QuickChart
    $chartConfig = [
        "type" => "doughnut",
        "data" => [
            "labels" => $pieLabels,
            "datasets" => [[
                "data" => $pieData,
                "backgroundColor" => [
                    "#60a5fa", "#fbbf24", "#34d399", "#f87171", "#a78bfa", "#f472b6", "#facc15", "#818cf8", "#4ade80", "#f59e42", "#10b981"
                ]
            ]]
        ],
        "options" => [
            "plugins" => [
                "legend" => ["position" => "bottom"]
            ],
            "cutout" => "60%"
        ]
    ];
    $chartUrl = "https://quickchart.io/chart?c=" . urlencode(json_encode($chartConfig)) . "&w=400&h=400&format=png&backgroundColor=white";

    // Lấy ảnh biểu đồ về base64
    $chartImg = @file_get_contents($chartUrl);
    $chartBase64 = $chartImg ? 'data:image/png;base64,' . base64_encode($chartImg) : '';

    $html = '
    <h2 style="text-align:center;">BÁO CÁO TÀI CHÍNH CÁ NHÂN</h2>
    <div style="margin-bottom:12px;text-align:center;">
        <b>Quý:</b> '.$quarter.' &nbsp; <b>Năm:</b> '.$year.'
    </div>
    <div style="text-align:center;margin-bottom:18px;">
        '.($chartBase64 ? '<img src="'.$chartBase64.'" style="max-width:260px;max-height:260px;display:inline-block;margin-bottom:8px;" />' : '').'
    </div>
    <table border="0" cellpadding="6" style="margin:0 auto 18px auto;font-size:1.05em;">
        <tr>
            <td><b>Tổng thu nhập:</b></td>
            <td style="color:#059669;">'.number_format($totalIncome,0,',','.').' VNĐ</td>
        </tr>
        <tr>
            <td><b>Tổng chi tiêu:</b></td>
            <td style="color:#ef4444;">'.number_format($totalExpense,0,',','.').' VNĐ</td>
        </tr>
        <tr>
            <td><b>Số dư cuối kỳ:</b></td>
            <td style="color:#2563eb;">'.number_format($finalBalance,0,',','.').' VNĐ</td>
        </tr>
    </table>
    <h3 style="margin-bottom:8px;">Chi tiết các danh mục chi tiêu</h3>
    <table border="1" cellpadding="6" cellspacing="0" width="100%" style="border-collapse:collapse;font-size:1em;">
        <thead>
            <tr style="background:#eaf4fb;color:#2563eb;">
                <th>Danh mục</th>
                <th>Số tiền</th>
                <th>Tỷ lệ (%)</th>
            </tr>
        </thead>
        <tbody>';
    foreach ($categoryStats as $cat) {
        $html .= '<tr>
            <td>'.$cat['name'].'</td>
            <td style="text-align:right">'.number_format($cat['amount'],0,',','.').' VNĐ</td>
            <td style="text-align:center">'.$cat['percent'].'</td>
        </tr>';
    }
    $html .= '</tbody>
    </table>
    <div style="margin-top:18px;font-size:1.07em;">
        <b>Tỷ lệ tiết kiệm:</b> <span style="color:#059669;">'.$savingPercent.'%</span><br/>
        <span style="color:#b45309;">
        '.(
            $savingPercent < 20
            ? 'Bạn chỉ tiết kiệm được <b>'.$savingPercent.'%</b> thu nhập. Hãy cố gắng tiết kiệm tối thiểu 20% thu nhập mỗi quý!'
            : ($savingPercent < 30
                ? 'Bạn đã tiết kiệm <b>'.$savingPercent.'%</b> thu nhập. Rất tốt, hãy duy trì hoặc nâng cao hơn nữa!'
                : 'Bạn tiết kiệm <b>'.$savingPercent.'%</b> thu nhập. Tuyệt vời!')
        ).'
        </span>
    </div>
    ';
    $mpdf->WriteHTML($html);
    $mpdf->Output('bao_cao_tai_chinh.pdf', 'D');
    exit;
}

// ======= API JSON =======
header('Content-Type: application/json');
// Khi trả về dữ liệu, escape các trường text nếu cần
foreach ($categoryStats as &$cat) {
    $cat['name'] = htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8');
}
echo json_encode([
    'totalIncome' => $totalIncome,
    'totalExpense' => $totalExpense,
    'finalBalance' => $finalBalance,
    'categoryStats' => $categoryStats
]);
exit;
