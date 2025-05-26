<?php
$host = "localhost";
$port = "5432";
$dbname = "QLtaichinh";
$user = "postgres";
$password = "0212";

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
    // Thiết lập chế độ lỗi để phát hiện lỗi
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Kết nối thất bại: " . $e->getMessage());
}
?>