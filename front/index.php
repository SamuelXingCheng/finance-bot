<?php 
require_once __DIR__ . '/../config.php';
$liffId = defined('LINE_LIFF_ID') ? LINE_LIFF_ID : '';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LIFF 除錯模式</title>
    <script src="https://static.line-scdn.net/liff/sdk/v2/liff.js"></script>
    <style>body { padding: 20px; font-family: sans-serif; }</style>
</head>
<body>
    <h2>LIFF 初始化診斷</h2>
    <div id="status" style="background: #eee; padding: 10px; border-radius: 5px;">準備中...</div>
    <hr>
    <p><strong>讀取到的 LIFF ID:</strong> <span style="color:red; font-weight:bold;"><?php echo $liffId; ?></span></p>
    
    <script>
        const LIFF_ID = "<?php echo $liffId; ?>";
        const statusDiv = document.getElementById('status');

        statusDiv.innerHTML = "1. 檢查 ID...";
        
        if (!LIFF_ID) {
            statusDiv.innerHTML = "❌ 錯誤：PHP 未能讀取到 LIFF ID。請檢查 .env 和 config.php。";
        } else if (LIFF_ID.startsWith("http")) {
            statusDiv.innerHTML = "❌ 錯誤：LIFF ID 不能是網址！<br>請修改 .env 中的 LINE_LIFF_ID 為純 ID (例如 2008...)";
        } else {
            statusDiv.innerHTML = "2. 正在執行 liff.init()...";
            
            liff.init({ liffId: LIFF_ID })
                .then(() => {
                    statusDiv.innerHTML = "✅ LIFF 初始化成功！<br>如果是電腦版，請登入。<br>如果是手機，應已自動登入。";
                    if (!liff.isLoggedIn()) {
                        statusDiv.innerHTML += "<br>狀態：未登入 (準備跳轉)";
                        liff.login();
                    } else {
                        statusDiv.innerHTML += "<br>狀態：已登入";
                        alert("成功！您的 LIFF 設定是正確的。現在請換回原本的 index.php");
                    }
                })
                .catch((err) => {
                    console.error(err);
                    statusDiv.innerHTML = "❌ 初始化失敗：<br>" + err.code + "<br>" + err.message;
                    alert("LIFF 錯誤：" + err.message);
                });
        }
    </script>
</body>
</html>