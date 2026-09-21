<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');

echo '<h2>TiDB Connection Diagnostic Tool</h2>';

$host = 'gateway01.ap-southeast-1.prod.aws.tidbcloud.com';
$port = 4000;
$db   = 'test';
$user = 'dtzC8ywp1FLVzF4.root';
$pass = 'wY1GbrwwnlYNL09o';

// 1. Kiểm tra mở cổng mạng (Network Socket Port 4000)
echo '<h3>1. Kiểm tra mở cổng kết nối Outbound (Port 4000):</h3>';
$t1 = microtime(true);
$fp = @fsockopen($host, $port, $errno, $errstr, 5);
if (!$fp) {
    echo "<p style='color:red; font-weight:bold;'>❌ KHÔNG THỂ KẾT NỐI TỚI CỔNG 4000: [$errno] $errstr</p>";
    echo "<p>👉 <b>Kết luận:</b> Tường lửa của hosting (CSF/Firewall) đang CHẶN cổng 4000. Bạn cần liên hệ nhà cung cấp hosting (vHost) nhờ mở Outbound Port 4000 TCP.</p>";
} else {
    fclose($fp);
    $time = round((microtime(true) - $t1) * 1000, 2);
    echo "<p style='color:green; font-weight:bold;'>✅ Cổng 4000 ĐÃ MỞ (Thời gian phản hồi: {$time}ms)</p>";
}

// 2. Kiểm tra file chứng chỉ SSL cacert.pem
echo '<h3>2. Kiểm tra file chứng chỉ SSL (cacert.pem):</h3>';
$appRoot = dirname(__DIR__);
$caFile = $appRoot . '/cacert.pem';
if (file_exists($caFile)) {
    echo "<p style='color:green; font-weight:bold;'>✅ Tìm thấy file cacert.pem tại: $caFile (" . round(filesize($caFile)/1024, 2) . " KB)</p>";
} else {
    echo "<p style='color:red; font-weight:bold;'>❌ KHÔNG TÌM THẤY file cacert.pem tại: $caFile</p>";
    echo "<p>👉 <b>Khắc phục:</b> Hãy tải file cacert.pem lên thư mục <code>$appRoot</code> trên hosting.</p>";
}

// 3. Kiểm tra kết nối PDO MySQL tới TiDB
echo '<h3>3. Thử kết nối PDO MySQL tới TiDB:</h3>';
try {
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ];
    if (file_exists($caFile)) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $caFile;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }

    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    echo "<p style='color:green; font-weight:bold;'>🎉 KẾT NỐI TIDB THÀNH CÔNG RỰC RỠ!</p>";
    
    $stmt = $pdo->query("SELECT count(*) as total FROM licenses");
    $count = $stmt->fetch()['total'];
    echo "<p>Tổng số licenses trong DB TiDB: <b>$count</b></p>";

} catch (Throwable $e) {
    echo "<p style='color:red; font-weight:bold;'>❌ LỖI KẾT NỐI PDO: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre style='background:#f4f4f4; padding:10px; border:1px solid #ccc;'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
