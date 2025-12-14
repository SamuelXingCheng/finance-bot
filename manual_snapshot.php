<?php
require_once 'config.php';
require_once 'src/CryptoService.php';

$userId = 1; // 你的 User ID
$service = new CryptoService();

if ($service->captureSnapshot($userId)) {
    echo "✅ 快照建立成功！";
} else {
    echo "❌ 失敗";
}