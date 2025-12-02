<?php 
require_once __DIR__ . '/../config.php';
$liffId = defined('LINE_LIFF_ID') ? LINE_LIFF_ID : '';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>記帳機器人儀表板 (LIFF)</title>
    
    <meta name="referrer" content="no-referrer-when-downgrade">
    
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        /* CSS 樣式保持不變 */
        body { font-family: Arial, sans-serif; padding: 20px; background-color: #f4f4f9; }
        .container { max-width: 900px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        .data-box { margin-top: 20px; padding: 15px; border: 1px solid #ccc; border-radius: 6px; background-color: #e9e9f1; }
        .net-worth { font-size: 2em; font-weight: bold; color: #007AFF; }
        .section-title { border-bottom: 2px solid #ccc; padding-bottom: 5px; margin-top: 30px; }
        #chart-container { width: 100%; max-width: 450px; margin: 20px auto; }
        .asset-list { list-style-type: none; padding: 0; }
        .asset-list li { margin-bottom: 8px; border-bottom: 1px dashed #ccc; padding-bottom: 5px; }
        #add-transaction-form input[type="text"], #add-transaction-form input[type="number"], #add-transaction-form input[type="date"], #add-transaction-form select {
            padding: 8px; margin-top: 5px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; width: 100%;
        }
        #add-transaction-form p { margin-bottom: 5px; font-weight: bold; }
        #add-transaction-form label { font-weight: normal; }
    </style>
</head>
<body>
    <div class="container">
        <div id="auth-status">
            <h1>我的財務總覽 (LIFF)</h1>
            <p id="loading-msg">正在初始化 LIFF...</p>
        </div>
        
        <div id="finance-content" style="display: none;">
            <div class="section-title"><h2>手動新增交易</h2></div>
            <div class="data-box">
                <form id="add-transaction-form">
                    <p>類型:</p>
                    <input type="radio" id="expense" name="type" value="expense" checked required> <label for="expense">支出</label>&nbsp;&nbsp;
                    <input type="radio" id="income" name="type" value="income" required> <label for="income">收入</label>
                    <p>金額 (Amount): <input type="number" name="amount" required min="0.01" step="0.01"></p>
                    <p>日期 (YYYY-MM-DD): <input type="date" name="date" value="<?php echo date('Y-m-d'); ?>"></p>
                    <p>描述/品項: <input type="text" name="description" required></p>
                    <p>類別 (Category):
                        <select name="category" required>
                            <option value="Food">Food (飲食)</option>
                            <option value="Transport">Transport (交通)</option>
                            <option value="Entertainment">Entertainment (娛樂)</option>
                            <option value="Shopping">Shopping (購物)</option>
                            <option value="Bills">Bills (帳單)</option>
                            <option value="Investment">Investment (投資)</option>
                            <option value="Medical">Medical (醫療)</option>
                            <option value="Education">Education (教育)</option>
                            <option value="Salary">Salary (薪水)</option>
                            <option value="Allowance">Allowance (津貼)</option>
                            <option value="Miscellaneous" selected>Miscellaneous (雜項)</option>
                        </select>
                    </p>
                    <p>幣種 (Currency): <input type="text" name="currency" value="TWD" maxlength="5" required></p>
                    <button type="submit" style="padding: 10px 20px; background-color: #007AFF; color: white; border: none; border-radius: 5px; cursor: pointer;">新增交易</button>
                </form>
                <div id="form-message" style="margin-top: 15px; font-weight: bold;"></div>
            </div>
            
            <div class="section-title"><h2>淨資產總覽</h2></div>
            <div id="asset-summary" class="data-box">正在載入資產數據...</div>
            <div class="section-title"><h2>本月支出報表</h2></div>
            <div id="expense-breakdown" class="data-box">
                <div id="chart-container"><canvas id="expensePieChart"></canvas></div>
                <p id="total-expense-text" style="text-align: center;"></p>
            </div>
        </div>
    </div>

    <script>
        const LIFF_ID = '<?php echo $liffId; ?>';
        const API_BASE_URL = '../api.php'; 

        // 核心功能：使用 LIFF token 呼叫 API
        async function fetchWithLiffToken(url, options = {}) {
            if (typeof liff === 'undefined' || !liff.isLoggedIn()) return null;
            const idToken = liff.getIDToken();
            const defaultHeaders = { 'Authorization': `Bearer ${idToken}`, 'Content-Type': 'application/json' };
            options.headers = { ...defaultHeaders, ...options.headers };
            const response = await fetch(url, options);
            if (response.status === 401) {
                alert("登入狀態失效，請重新登入。");
                liff.logout(); liff.login();
                return null;
            }
            return response;
        }

        // 輔助函式
        function generateColors(count) {
            const colors = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#E7E9ED', '#4CAF50', '#F44336', '#2196F3'];
            const result = [];
            for (let i = 0; i < count; i++) result.push(colors[i % colors.length]);
            return result;
        }

        // 數據抓取
        async function fetchAssetSummary() {
            const response = await fetchWithLiffToken(`${API_BASE_URL}?action=asset_summary`);
            if (!response) return; 
            const result = await response.json();
            const container = document.getElementById('asset-summary');
            if (result.status === 'success') {
                const data = result.data;
                const globalNetWorthTWD = data.global_twd_net_worth.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
                let html = `<p>全球淨值 (TWD): <span class="net-worth">NT$ ${globalNetWorthTWD}</span></p><h3>幣種淨值總覽:</h3><ul class="asset-list">`;
                for (const currency in data.breakdown) {
                    const item = data.breakdown[currency];
                    const netWorth = item.net_worth.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
                    const twdValue = item.twd_total.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
                    const color = item.net_worth >= 0 ? 'green' : 'red';
                    html += `<li><strong>${currency} 淨值: </strong><span style="color: ${color}; font-weight: bold;">${currency} ${netWorth}</span><span style="font-size: 0.8em; color: #777;"> (約 NT$ ${twdValue})</span></li>`;
                }
                html += `</ul>`;
                container.innerHTML = html;
            } else {
                container.textContent = '❌ 載入資產失敗: ' + (result.message || 'API 錯誤');
            }
        }

        let expensePieChart = null; 
        async function fetchExpenseBreakdown() {
            const response = await fetchWithLiffToken(`${API_BASE_URL}?action=monthly_expense_breakdown`);
            if (!response) return; 
            const result = await response.json();
            const breakdownContainer = document.getElementById('expense-breakdown');
            const totalExpenseText = document.getElementById('total-expense-text');
            const chartContainer = document.getElementById('chart-container');
            if (expensePieChart) { expensePieChart.destroy(); }
            chartContainer.innerHTML = '<canvas id="expensePieChart"></canvas>';
            if (result.status === 'success') {
                const data = result.data;
                const totalExpense = data.total_expense.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
                const breakdown = data.breakdown;
                totalExpenseText.innerHTML = `本月總支出: <span style="color: #FF334B; font-size: 1.5em; font-weight: bold;">NT$ ${totalExpense}</span>`;
                if (Object.keys(breakdown).length === 0 || data.total_expense <= 0) {
                    chartContainer.innerHTML = '<p style="text-align:center;">本月無支出數據。</p>';
                    return;
                }
                const labels = Object.keys(breakdown); 
                const dataValues = Object.values(breakdown).map(v => parseFloat(v)); 
                const backgroundColors = generateColors(labels.length); 
                expensePieChart = new Chart(document.getElementById('expensePieChart'), {
                    type: 'pie',
                    data: { labels: labels, datasets: [{ data: dataValues, backgroundColor: backgroundColors, hoverOffset: 4 }] },
                    options: { responsive: true, plugins: { legend: { position: 'top' }, title: { display: true, text: '本月支出分類圓餅圖' } } }
                });
            } else {
                breakdownContainer.textContent = '❌ 載入報表失敗: ' + (result.message || 'API 錯誤');
            }
        }

        async function handleTransactionSubmit(event) {
            event.preventDefault(); 
            const form = event.target;
            const formMessage = document.getElementById('form-message');
            formMessage.textContent = '處理中...';
            formMessage.style.color = '#333';
            const formData = new FormData(form);
            const data = Object.fromEntries(formData.entries());
            data.amount = parseFloat(data.amount); 
            try {
                const response = await fetchWithLiffToken(`${API_BASE_URL}?action=add_transaction`, {
                    method: 'POST',
                    body: JSON.stringify(data)
                });
                if (!response) return; 
                const result = await response.json();
                if (result.status === 'success') {
                    formMessage.textContent = '🎉 ' + result.message; formMessage.style.color = 'green';
                    form.reset(); fetchExpenseBreakdown(); fetchAssetSummary();
                } else {
                    formMessage.textContent = '❌ ' + (result.message || '新增失敗'); formMessage.style.color = 'red';
                }
            } catch (error) {
                console.error('Submit error:', error);
                formMessage.textContent = '❌ 網路錯誤或 API 連線失敗。'; formMessage.style.color = 'red';
            }
        }
        
        function initializeApp() {
            document.getElementById('add-transaction-form').addEventListener('submit', handleTransactionSubmit);
            document.getElementById('finance-content').style.display = 'block';
            document.getElementById('loading-msg').style.display = 'none';
            fetchAssetSummary(); fetchExpenseBreakdown();
        }

        // 初始化
        if (typeof liff === 'undefined') {
            document.getElementById('loading-msg').innerHTML = "<span style='color:red;'>❌ SDK 載入失敗！</span><br>無法從 LINE 官方伺服器下載 SDK。";
        } else if (!LIFF_ID) {
            document.getElementById('loading-msg').innerHTML = "❌ 錯誤：PHP 未能讀取到 LIFF ID。請檢查 config.php。";
        } else {
            liff.init({ liffId: LIFF_ID })
                .then(() => {
                    if (!liff.isLoggedIn()) { liff.login(); } 
                    else { initializeApp(); }
                })
                .catch((err) => {
                    console.error('LIFF Initialization failed', err);
                    document.getElementById('loading-msg').innerHTML = `LIFF 初始化失敗：${err.code} - ${err.message}`;
                });
        }
    </script>
</body>
</html>