<template>
  <div class="dashboard-container">
    
    <div class="card-section" v-if="!isPremium">
      <div class="data-box premium-box">
        <div class="premium-content">
          <div class="premium-header">
            <h2 class="premium-title">升級 Premium 會員</h2>
            <span class="premium-badge">PRO</span>
          </div>
          
          <div class="premium-price">
            每月僅需 <span class="price-tag">$3 USD</span> (約 NT$95)
          </div>

          <p class="premium-desc">
            訂閱會員可立即解鎖以下進階功能，**享受無限制的智慧服務**，真正掌握每一分財富：
        </p>
        <ul class="feature-list">
            <li>
                **無限次 AI 口語記帳：** 只需用日常對話的方式輸入收支，AI 即可精準識別、自動歸類。
                **【免費版限制：每日僅限使用 3 次口語記帳】**
            </li>
            <li>
                **無限次 AI 財務健檢：** 整合您的記帳、資產配置情況，**即時**獲取個人化的理財建議與知識。
                **【免費版限制：每年僅能使用 2 次 AI 財務健檢】**
            </li>
            <li>
                **解鎖長期歷史趨勢圖** 與 **資產負債總覽表**。
            </li>
            <li>
                **資產負債總覽表：** 一目瞭然您的淨值結構、資產與負債的完整配置，做出精準的財務決策。
            </li>
            <li>
                **多幣種整合追蹤：** 專為投資加密貨幣、外幣和海外資產的用戶設計，將所有幣種集中管理，無需手動換算。
            </li>
        </ul>
          
          <div class="payment-notice">
            <p>⚠<strong>付款前請注意：</strong></p>
            <ul>
              <li>🔹 <strong>BMC 支付：</strong>請務必在付款頁面填寫與下方綁定<strong>相同的 Email</strong>，以便系統自動開通。</li>
              <li>🔹 <strong>加密貨幣：</strong>請選擇 <strong>USDC/USDT</strong> 進行轉帳，推薦使用Polygon或是BSC鏈，手續費較低。</li>
              <li>🔹 <strong>經測試，瀏覽器Safari經常無法成功進入付款網頁，請使用Chrome。</strong></li>
              <li>🔹 <strong>測試階段，圖表功能將免費體驗，AI功能超過額度則不開放，敬請見諒。</strong></li>
            </ul>
          </div>

          <div class="payment-buttons">
              <button class="btn-pay btn-bmc" @click="openPaymentModal('bmc')">
                  Apple Pay / 信用卡 / Buy me a Coffee (BMC)
              </button>
              <button class="btn-pay btn-crypto" @click="openPaymentModal('crypto')">
                  加密貨幣支付（NOWPayments）
              </button>
          </div>
        </div>
      </div>
    </div>

    <div class="card-section">
      <div class="section-header">
        <h2>快速記帳</h2>
      </div>
      <div class="data-box input-card">
        <form id="add-transaction-form" @submit.prevent="handleTransactionSubmit">
          
          <div class="form-group type-select">
            <label>類型</label>
            <div class="radio-group">
              <label class="radio-label" :class="{ active: transactionForm.type === 'expense' }">
                <input type="radio" v-model="transactionForm.type" value="expense">
                <span>支出</span>
              </label>
              <label class="radio-label" :class="{ active: transactionForm.type === 'income' }">
                <input type="radio" v-model="transactionForm.type" value="income">
                <span>收入</span>
              </label>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group half">
              <label>金額</label>
              <input type="number" v-model.number="transactionForm.amount" required min="0.01" step="0.01" placeholder="0.00" class="input-minimal">
            </div>
            
            <div class="form-group half">
              <label>幣種</label>
              <div v-if="isCustomCurrency" class="custom-currency-wrapper">
                 <input type="text" v-model="transactionForm.currency" class="input-minimal" placeholder="代碼" required @input="forceUppercase">
                 <button type="button" class="back-btn" @click="resetCurrency" title="返回選單">↩</button>
              </div>
              <select v-else v-model="currencySelectValue" class="input-minimal" @change="handleCurrencyChange">
                <option value="TWD">新台幣 (TWD)</option>
                <option value="USD">美元 (USD)</option>
                <option value="JPY">日圓 (JPY)</option>
                <option value="CNY">人民幣 (CNY)</option>
                <option value="EUR">歐元 (EUR)</option>
                <option value="USDT">泰達幣 (USDT)</option>
                <option value="CUSTOM">自行輸入...</option>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label>日期</label>
            <input type="date" v-model="transactionForm.date" required class="input-minimal">
          </div>

          <div class="form-group">
            <label>項目說明</label>
            <input type="text" v-model="transactionForm.description" required placeholder="例如：拿鐵、書籍" class="input-minimal">
          </div>

          <div class="form-group">
            <label>分類</label>
            <div class="select-wrapper">
              <select v-model="transactionForm.category" required class="input-minimal">
                <option value="Food">飲食</option>
                <option value="Transport">交通</option>
                <option value="Entertainment">娛樂</option>
                <option value="Shopping">購物</option>
                <option value="Bills">帳單</option>
                <option value="Investment">投資</option>
                <option value="Medical">醫療</option>
                <option value="Education">教育</option>
                <option value="Salary">薪水</option>
                <option value="Allowance">津貼</option>
                <option value="Bonus">獎金</option>
                <option value="Miscellaneous">其他</option>
              </select>
            </div>
          </div>

          <button type="submit" class="submit-btn">新增紀錄</button>
        </form>
        
        <transition name="fade">
          <div v-if="formMessage" id="form-message" :class="messageClass">
            {{ formMessage }}
          </div>
        </transition>
      </div>
    </div>
    
    <div class="card-section">
      <div class="section-header">
        <h2>本月收支分佈</h2> 
      </div>
      <div id="expense-breakdown" class="data-box chart-card">
          
          <div class="stats-row">
            <div class="stat-item cursor-pointer" 
                 :class="{ 'active-stat': currentChartType === 'income' }"
                 @click="toggleChart('income')">
              <span class="label">總收入 (點擊切換)</span>
              <span class="value text-income">NT$ {{ numberFormat(totalIncome, 2) }}</span>
            </div>
            
            <div class="vertical-line"></div>
            
            <div class="stat-item cursor-pointer" 
                 :class="{ 'active-stat': currentChartType === 'expense' }"
                 @click="toggleChart('expense')">
              <span class="label">總支出 (點擊切換)</span>
              <span class="value text-expense">NT$ {{ numberFormat(totalExpense, 2) }}</span>
            </div>
          </div>

          <div id="chart-container">
              <div v-if="(currentChartType === 'expense' && totalExpense <= 0) || (currentChartType === 'income' && totalIncome <= 0)" class="no-data-msg">
                本月尚無{{ currentChartType === 'expense' ? '支出' : '收入' }}紀錄
              </div>
              <canvas v-else ref="expenseChartCanvas"></canvas>
          </div>
      </div>
    </div>

    <div class="card-section">
      <div class="section-header">
        <h2>歷史分類趨勢</h2>
      </div>
      <div class="data-box chart-card">
        <div class="date-controls mb-4">
            <input type="date" v-model="trendFilter.start" class="date-input">
            <span class="separator">~</span>
            <input type="date" v-model="trendFilter.end" class="date-input">
            <button @click="fetchTrendData" class="filter-btn">查詢</button>
        </div>
        <div class="chart-box-lg">
          <canvas ref="trendChartCanvas"></canvas>
        </div>
        <div class="chart-hint">
            * 點擊上方圖例可隱藏/顯示特定分類
        </div>
      </div>
    </div>

    <div class="card-section">
      <div class="section-header">
        <h2>近期收支明細</h2>
      </div>
      
      <div class="data-box tx-list-wrapper"> 
          <div class="list-controls">
              <h3>明細列表</h3>
              <div class="month-selector">
                  <input type="month" v-model="currentListMonth" @change="fetchTransactions" class="month-input-styled">
              </div>
          </div>

          <div v-if="txLoading" class="loading-box">
              <span class="loader"></span> 載入中...
          </div>
          
          <div v-else-if="transactions.length === 0" class="empty-msg">
              本月尚無紀錄
          </div>
          
          <div v-else class="tx-list">
              <div v-for="tx in transactions" :key="tx.id" class="tx-item">
                  <div class="tx-left">
                      <div class="tx-date">{{ tx.transaction_date.substring(5) }}</div>
                      <div class="tx-cat-badge" :class="tx.type">
                          {{ categoryMap[tx.category] || tx.category }}
                      </div>
                  </div>
                  <div class="tx-mid">
                      <div class="tx-desc">{{ tx.description }}</div>
                  </div>
                  <div class="tx-right">
                      <div class="tx-amount" :class="tx.type === 'income' ? 'text-income' : 'text-expense'">
                          {{ tx.type === 'income' ? '+' : '-' }} {{ numberFormat(tx.amount, 0) }}
                      </div>
                      <div class="tx-actions">
                          <button class="text-btn edit" @click="openEditModal(tx)">編輯</button>
                          <button class="text-btn delete" @click="handleDeleteTx(tx.id)">刪除</button>
                      </div>
                  </div>
              </div>
          </div>
      </div>
    </div>

    <div v-if="isEditModalOpen" class="modal-overlay" @click.self="closeModal">
      <div class="modal-content">
        <div class="modal-header">
          <h3>編輯紀錄</h3>
          <button class="close-btn" @click="closeModal">×</button>
        </div>
        <form @submit.prevent="handleUpdateTx">
            <div class="form-group type-select">
                <div class="radio-group">
                    <label class="radio-label" :class="{ active: editForm.type === 'expense' }"><input type="radio" v-model="editForm.type" value="expense">支出</label>
                    <label class="radio-label" :class="{ active: editForm.type === 'income' }"><input type="radio" v-model="editForm.type" value="income">收入</label>
                </div>
            </div>
            <div class="form-row">
                <input type="number" v-model.number="editForm.amount" required class="input-std half">
                <input type="text" v-model="editForm.currency" class="input-std half" required>
            </div>
            <div class="form-group mt-2">
                <input type="date" v-model="editForm.date" required class="input-std">
            </div>
            <div class="form-group">
                <input type="text" v-model="editForm.description" required class="input-std">
            </div>
            <div class="form-group">
                <select v-model="editForm.category" class="input-std">
                    <option v-for="(name, key) in categoryMap" :key="key" :value="key">{{ name }}</option>
                </select>
            </div>
            <button type="submit" class="save-btn">儲存修改</button>
        </form>
      </div>
    </div>

    <div v-if="isPaymentModalOpen" class="modal-overlay" @click.self="isPaymentModalOpen = false">
      <div class="modal-content payment-modal">
        <div class="modal-header">
          <h3>綁定 Email</h3>
          <button class="close-btn" @click="isPaymentModalOpen = false">×</button>
        </div>
        <div class="modal-body">
            <p class="text-sm text-gray-600 mb-4">
                請輸入您付款時使用的 <strong>Email</strong>，系統將依此自動開通權限。
            </p>
            <input type="email" v-model="paymentEmail" placeholder="name@example.com" class="input-std mb-4">
            <button class="save-btn" @click="handleLinkAndPay" :disabled="isLinking">
                {{ isLinking ? '處理中...' : '綁定並前往付款' }}
            </button>
        </div>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, onMounted, nextTick, watch } from 'vue';
import { fetchWithLiffToken, numberFormat } from '@/utils/api';
import Chart from 'chart.js/auto'; 
import ChartDataLabels from 'chartjs-plugin-datalabels';
Chart.register(ChartDataLabels);

// --- 狀態管理 ---
const isPremium = ref(false); 
const totalExpense = ref(0);
const totalIncome = ref(0);
const expenseBreakdown = ref({});
const incomeBreakdown = ref({});
const currentChartType = ref('expense'); 
const expenseChartCanvas = ref(null);
let chartInstance = null;

const trendFilter = ref({
    start: new Date(new Date().setFullYear(new Date().getFullYear() - 1)).toISOString().substring(0, 10),
    end: new Date().toISOString().substring(0, 10)
});
const trendChartCanvas = ref(null);
let trendChart = null;

const formMessage = ref('');
const messageClass = ref('');
const transactionForm = ref({
  type: 'expense', amount: null, date: new Date().toISOString().substring(0, 10),
  description: '', category: 'Miscellaneous', currency: 'TWD',
});

const currencySelectValue = ref('TWD');
const isCustomCurrency = ref(false);

const transactions = ref([]);
const txLoading = ref(false);
const currentListMonth = ref(new Date().toISOString().substring(0, 7)); 

const isEditModalOpen = ref(false);
const editForm = ref({}); 

const isPaymentModalOpen = ref(false);
const isLinking = ref(false);
const paymentEmail = ref('');
const selectedPaymentMethod = ref('bmc'); 

const BMC_URL = 'https://buymeacoffee.com/finbot'; 
const NOWPAYMENTS_URL = 'https://nowpayments.io/donation/finbot2'; 

const categoryMap = {
  'Food': '飲食', 'Transport': '交通', 'Entertainment': '娛樂', 'Shopping': '購物',
  'Bills': '帳單', 'Investment': '投資', 'Medical': '醫療', 'Education': '教育',
  'Miscellaneous': '其他', 'Salary': '薪水', 'Allowance': '津貼', 'Bonus': '獎金',
};
const palette = ['#D4A373', '#FAEDCD', '#CCD5AE', '#E9EDC9', '#A98467', '#ADC178', '#6C584C', '#B5838D', '#E5989B', '#FFB4A2'];

// --- 邏輯函式 ---

function handleCurrencyChange() {
    if (currencySelectValue.value === 'CUSTOM') {
        isCustomCurrency.value = true; transactionForm.value.currency = ''; 
    } else {
        isCustomCurrency.value = false; transactionForm.value.currency = currencySelectValue.value;
    }
}
function resetCurrency() {
    isCustomCurrency.value = false; currencySelectValue.value = 'TWD'; transactionForm.value.currency = 'TWD';
}
function forceUppercase() { transactionForm.value.currency = transactionForm.value.currency.toUpperCase(); }

function openPaymentModal(method) {
    selectedPaymentMethod.value = method;
    isPaymentModalOpen.value = true;
}

async function fetchAssetSummary() {
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=asset_summary`);
    if (response && response.ok) {
        const result = await response.json();
        if (result.status === 'success') {
            isPremium.value = result.data.is_premium || false;
        }
    }
}

async function fetchTransactions() {
    txLoading.value = true;
    const monthToSend = currentListMonth.value.substring(0, 7); 
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=get_transactions&month=${monthToSend}`);
    
    if (response && response.ok) {
        const result = await response.json();
        if (result.status === 'success') {
            transactions.value = result.data;
        } else {
            console.error("Failed to load transactions:", result);
        }
    }
    txLoading.value = false;
}

async function handleDeleteTx(id) {
    if (!confirm("確定要刪除這筆紀錄嗎？")) return;
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=delete_transaction`, {
        method: 'POST', body: JSON.stringify({ id })
    });
    if (response && (await response.json()).status === 'success') {
        refreshAllData();
    }
}

function openEditModal(tx) {
    editForm.value = { 
        id: tx.id, amount: parseFloat(tx.amount), type: tx.type,
        date: tx.transaction_date, description: tx.description,
        category: tx.category, currency: tx.currency
    };
    isEditModalOpen.value = true;
}
function closeModal() { isEditModalOpen.value = false; }

async function handleUpdateTx() {
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=update_transaction`, {
        method: 'POST', body: JSON.stringify(editForm.value)
    });
    if (response && (await response.json()).status === 'success') {
        closeModal(); refreshAllData();
        alert("更新成功");
    } else { alert("更新失敗"); }
}

function refreshAllData() {
    fetchAssetSummary(); 
    fetchExpenseData();
    fetchTrendData();
    fetchTransactions(); 
}

watch(currentListMonth, (newMonth) => { fetchTransactions(); });

async function fetchExpenseData() {
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=monthly_expense_breakdown`);
    if (response && response.ok) {
        const result = await response.json();
        if (result.status === 'success') {
            totalExpense.value = result.data.total_expense;
            totalIncome.value = result.data.total_income || 0;
            expenseBreakdown.value = result.data.breakdown || {};
            incomeBreakdown.value = result.data.income_breakdown || {};
            await nextTick(); renderChart();
        }
    }
}

async function fetchTrendData() {
  const { start, end } = trendFilter.value;
  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=trend_data&start=${start}&end=${end}&mode=category`);
  if (response && response.ok) {
      const result = await response.json();
      if (result.status === 'success') renderTrendChart(result.data);
  }
}

function toggleChart(type) { currentChartType.value = type; nextTick(() => { renderChart(); }); }
function renderChart() {
  if (chartInstance) chartInstance.destroy();
  const sourceData = currentChartType.value === 'expense' ? expenseBreakdown.value : incomeBreakdown.value;
  const totalValue = currentChartType.value === 'expense' ? totalExpense.value : totalIncome.value;
  const rawLabels = Object.keys(sourceData);
  if (rawLabels.length === 0 || totalValue <= 0) return;
  const labels = rawLabels.map(key => categoryMap[key] || key);
  const dataValues = Object.values(sourceData).map(v => parseFloat(v));
  if (!expenseChartCanvas.value) return;
  chartInstance = new Chart(expenseChartCanvas.value, {
    type: 'doughnut',
    data: { labels: labels, datasets: [{ data: dataValues, backgroundColor: palette, borderWidth: 0, hoverOffset: 6 }] },
    options: { 
        responsive: true,
        maintainAspectRatio: false, // 關鍵設定：讓 CSS 控制大小
        cutout: '65%', 
        plugins: { 
            legend: { display: false }, 
            datalabels: { formatter: (value) => Math.round((value/totalValue)*100) >= 5 ? Math.round((value/totalValue)*100)+'%' : '', color: '#fff' } 
        } 
    }
  });
}

function renderTrendChart(data) {
    if (trendChart) trendChart.destroy();
    if (!trendChartCanvas.value) return;
    const labels = Object.keys(data); 
    const allCategories = new Set();
    labels.forEach(month => { Object.keys(data[month]).forEach(cat => allCategories.add(cat)); });
    const datasets = Array.from(allCategories).map((cat, index) => {
        const catData = labels.map(month => data[month][cat] || 0); 
        const color = palette[index % palette.length];
        return { label: categoryMap[cat] || cat, data: catData, borderColor: color, backgroundColor: color, tension: 0.3, fill: false, pointRadius: 3 };
    });
    trendChart = new Chart(trendChartCanvas.value, {
        type: 'line', data: { labels: labels, datasets: datasets },
        options: { 
            responsive: true, maintainAspectRatio: false, // 關鍵設定
            interaction: { mode: 'index', intersect: false }, 
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } }, datalabels: { display: false } }, 
            scales: { y: { beginAtZero: true, grid: { color: '#f0f0f0' }, ticks: { callback: (val) => 'NT$' + numberFormat(val, 0) } }, x: { grid: { display: false } } } 
        }
    });
}

async function handleTransactionSubmit() {
  formMessage.value = '處理中...';
  messageClass.value = 'msg-processing';
  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=add_transaction`, {
    method: 'POST', body: JSON.stringify(transactionForm.value)
  });
  if (response && (await response.json()).status === 'success') {
      formMessage.value = '成功'; messageClass.value = 'msg-success';
      transactionForm.value.amount = null; transactionForm.value.description = '';
      refreshAllData();
      setTimeout(() => { formMessage.value = ''; }, 3000);
  } else {
      formMessage.value = '失敗'; messageClass.value = 'msg-error';
  }
}

async function handleLinkAndPay() {
    if (!paymentEmail.value) { alert('請輸入 Email'); return; }
    
    isLinking.value = true;

    // 1. 先執行資料庫綁定 (這步不變，確保 DB 有紀錄)
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=link_bmc`, {
        method: 'POST',
        body: JSON.stringify({ email: paymentEmail.value })
    });
    
    if (response && response.ok) {
        const result = await response.json();
        
        if (result.status === 'success') {
            
            // 2. 判斷付款方式
            if (selectedPaymentMethod.value === 'crypto') {
                // 💎 加密貨幣：呼叫後端建立訂單
                try {
                    const orderResponse = await fetchWithLiffToken(`${window.API_BASE_URL}?action=create_crypto_order`, {
                        method: 'POST',
                        body: JSON.stringify({ email: paymentEmail.value }) // 傳送 Email 給後端
                    });
                    
                    const orderResult = await orderResponse.json();
                    
                    if (orderResult.status === 'success') {
                        isPaymentModalOpen.value = false;
                        // 開啟後端回傳的專屬付款連結
                        window.open(orderResult.data.invoice_url, '_blank');
                        alert('已為您建立專屬訂單！\n請在跳出的頁面完成支付，系統確認後將自動開通權限。');
                    } else {
                        alert('建立訂單失敗：' + (orderResult.message || '未知錯誤'));
                    }
                } catch (e) {
                    console.error(e);
                    alert('建立訂單時發生網路錯誤，請稍後再試。');
                }

            } else {
                // ☕ BMC / 信用卡：維持原樣，開啟靜態連結
                isPaymentModalOpen.value = false;
                window.open(BMC_URL, '_blank');
                alert('已跳轉至付款頁面，請務必填寫相同的 Email 以便系統自動開通！');
            }

        } else {
            alert(result.message); // 綁定失敗的錯誤訊息
        }
    } else {
        alert('API 連線失敗');
    }
    isLinking.value = false;
}

defineExpose({ refreshAllData });

onMounted(() => {
    refreshAllData();
});
</script>

<style scoped>
/* 基本容器設定 */
.dashboard-container { 
    width: 100%; 
    max-width: 100%; /* 防止父層溢出 */
    margin: 0 auto; 
    color: #5d5d5d; 
    font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; 
    letter-spacing: 0.03em; 
    overflow-x: hidden; /* 禁止橫向卷軸 */
    padding-bottom: 30px;
}

/* 區塊卡片設定 - 手機版優化 */
.card-section { margin-bottom: 20px; }
.section-header h2 { font-size: 1.1rem; font-weight: 600; color: #8c7b75; margin-bottom: 12px; margin-left: 4px; }
.data-box { 
    background-color: #ffffff; 
    border-radius: 16px; 
    padding: 16px; /* 手機版減少 padding (原本 24px) */
    box-shadow: 0 4px 20px rgba(220, 210, 200, 0.3); 
    border: 1px solid #f0ebe5; 
}

/* Premium Box Style */
.premium-box { background: linear-gradient(135deg, #fff8f0 0%, #fff 100%); border: 1px solid #eeddcc; position: relative; overflow: hidden; }
.premium-content { position: relative; z-index: 1; }
.premium-header { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
.premium-title { font-size: 1.1rem; font-weight: bold; color: #b45309; margin: 0; }
.premium-badge { font-size: 0.7rem; background: #b45309; color: white; padding: 2px 6px; border-radius: 4px; font-weight: bold; }
.premium-price { font-size: 1rem; color: #555; margin-bottom: 12px; font-weight: 500; }
.price-tag { color: #d97706; font-weight: bold; font-size: 1.1rem; }
.premium-desc { font-size: 0.9rem; color: #666; margin-bottom: 12px; line-height: 1.5; }
.feature-list { list-style: none; padding: 0; margin: 0 0 16px 0; }
.feature-list li { font-size: 0.9rem; color: #555; margin-bottom: 6px; }
.payment-buttons { display: flex; gap: 10px; width: 100%; flex-wrap: wrap; }
.btn-pay { flex: 1; padding: 10px; border-radius: 12px; font-weight: bold; border: none; cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.1); transition: transform 0.1s; font-size: 0.9rem; min-width: 120px; }
.btn-pay:hover { transform: translateY(-1px); }
.btn-bmc { background-color: #FFDD00; color: #000; }
.btn-crypto { background-color: #3861FB; color: #fff; }

/* Payment Notice */
.payment-notice { background-color: #fff; border: 1px dashed #d4a373; border-radius: 8px; padding: 12px; margin-bottom: 16px; font-size: 0.85rem; color: #666; }
.payment-notice ul { padding-left: 0; list-style: none; margin: 6px 0 0 0; }
.payment-notice li { margin-bottom: 4px; }

/* 輸入表單優化 */
.input-minimal { width: 100%; padding: 10px 0; border: none; border-bottom: 1px solid #e0e0e0; background: transparent; font-size: 16px; color: #333; border-radius: 0; transition: border-color 0.3s; box-sizing: border-box; }
.input-minimal:focus { outline: none; border-bottom: 1px solid #d4a373; }
.form-group { margin-bottom: 16px; } /* 減少間距 */
.form-group label { display: block; font-size: 0.85rem; color: #999; margin-bottom: 4px; }
.form-row { display: flex; gap: 12px; } /* 減少間距 */
.half { flex: 1; width: 50%; } /* 確保一半寬度 */

.custom-currency-wrapper { display: flex; align-items: center; gap: 8px; width: 100%; }
.back-btn { border: none; background: #eee; border-radius: 50%; width: 24px; height: 24px; cursor: pointer; color: #666; font-size: 0.8rem; display: flex; align-items: center; justify-content: center;}

.radio-group { display: flex; background: #f7f5f0; border-radius: 8px; padding: 4px; }
.radio-label { flex: 1; text-align: center; padding: 8px 0; cursor: pointer; border-radius: 6px; font-size: 0.9rem; color: #888; transition: all 0.3s; position: relative; }
.radio-label input { display: none; }
.radio-label.active { background: #ffffff; color: #d4a373; font-weight: bold; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }

.select-wrapper { position: relative; }
.select-wrapper::after { content: '▼'; font-size: 0.7rem; color: #aaa; position: absolute; right: 0; top: 14px; pointer-events: none; }

.submit-btn { width: 100%; padding: 14px; background-color: #d4a373; color: white; border: none; border-radius: 12px; font-size: 1rem; font-weight: 500; cursor: pointer; margin-top: 10px; transition: background-color 0.3s, transform 0.1s; box-shadow: 0 4px 10px rgba(212, 163, 115, 0.3); }
.submit-btn:hover { background-color: #c19263; }
.submit-btn:active { transform: scale(0.98); }

/* 📊 圖表卡片設定 (修正跑版關鍵) */
.chart-card { 
    display: flex; 
    flex-direction: column; 
    align-items: center; 
    width: 100%; /* 確保滿版 */
    box-sizing: border-box; /* 包含 padding */
}
.stats-row { 
    display: flex; 
    justify-content: space-around; 
    align-items: center; 
    width: 100%; 
    margin-bottom: 16px; 
    padding-bottom: 12px; 
    border-bottom: 1px dashed #f0ebe5; 
}
.stat-item { 
    text-align: center; 
    flex: 1; 
    padding: 6px; 
    border-radius: 12px; 
    transition: background-color 0.2s, transform 0.1s; 
}
.cursor-pointer { cursor: pointer; }
.active-stat { background-color: #f7f5f0; box-shadow: inset 0 2px 4px rgba(0,0,0,0.05); }
.vertical-line { width: 1px; height: 30px; background-color: #f0ebe5; }
.stat-item .label { display: block; font-size: 0.75rem; color: #999; margin-bottom: 2px; }
.stat-item .value { font-size: 1.1rem; font-weight: 700; letter-spacing: 0.5px; word-break: break-all; } /* 縮小字體並允許換行 */
.text-income { color: #8fbc8f; } 
.text-expense { color: #d67a7a; } 

/* 🛠️ 圖表容器修正：使用 Flexbox 強制置中 + 限制寬度 */
#chart-container { 
    width: 100%;
    max-width: 300px; /* 手機版限制最大寬度，避免過大 */
    height: 250px; 
    position: relative;
    display: flex;       
    justify-content: center; 
    align-items: center; 
    margin: 0 auto; /* 水平置中 */
}
.chart-box-lg { width: 100%; height: 250px; position: relative; }
.no-data-msg { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #aaa; font-size: 0.9rem; width: 100%; text-align: center; }
.chart-hint { font-size: 0.75rem; color: #aaa; margin-top: 10px; text-align: center; }

/* 日期選擇器 RWD */
.date-controls { 
    display: flex; 
    align-items: center; 
    gap: 8px; 
    background: #f7f5f0; 
    padding: 6px 12px; 
    border-radius: 20px; 
    width: 100%; 
    justify-content: space-between; 
    box-sizing: border-box; 
    flex-wrap: wrap; /* 允許換行 */
}
.date-input { border: none; background: transparent; color: #666; font-size: 0.85rem; outline: none; width: 35%; min-width: 80px; }
.separator { color: #aaa; }
.filter-btn { background-color: #d4a373; color: white; border: none; padding: 4px 12px; border-radius: 12px; font-size: 0.8rem; cursor: pointer; transition: background 0.2s; white-space: nowrap;}
.filter-btn:hover { background-color: #c19263; }
.mb-4 { margin-bottom: 16px; }

/* 訊息與列表樣式 */
.msg-processing { color: #999; margin-top: 15px; font-size: 0.9rem; text-align: center;}
.msg-success { background-color: #f0f7f0; color: #556b2f; padding: 10px; border-radius: 8px; margin-top: 15px; font-size: 0.9rem; text-align: center; }
.msg-error { background-color: #fff0f0; color: #d67a7a; padding: 10px; border-radius: 8px; margin-top: 15px; font-size: 0.9rem; text-align: center; }
.fade-enter-active, .fade-leave-active { transition: opacity 0.5s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.tx-list-wrapper { padding: 16px; } /* 手機版減少內距 */
.list-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #f0ebe5; padding-bottom: 12px; }
.list-controls h3 { margin: 0; font-size: 1rem; color: #8c7b75; }
.month-selector { display: flex; align-items: center; }
.month-input-styled { border: 1px solid #ddd; padding: 4px 10px; border-radius: 20px; font-size: 0.9rem; color: #666; background: #f9f9f9; outline: none; box-sizing: border-box; }
.tx-list { display: flex; flex-direction: column; gap: 12px; }
.tx-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px dashed #eee; }
.tx-item:last-child { border-bottom: none; }
.tx-left { display: flex; flex-direction: column; gap: 4px; min-width: 50px; }
.tx-date { font-size: 0.8rem; color: #aaa; }
.tx-cat-badge { font-size: 0.75rem; padding: 2px 6px; border-radius: 4px; display: inline-block; width: fit-content; font-weight: bold; }
.tx-cat-badge.expense { color: #c44536; background: #ffe5d9; }
.tx-cat-badge.income { color: #556b2f; background: #e9edc9; }
.tx-mid { flex: 1; padding: 0 8px; font-weight: 500; color: #444; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tx-right { text-align: right; display: flex; flex-direction: column; align-items: flex-end; gap: 4px; min-width: 70px; }
.tx-amount { font-weight: bold; font-size: 0.95rem; }
.tx-actions { display: flex; gap: 8px; }
.text-btn { background: transparent; border: none; cursor: pointer; font-size: 0.8rem; padding: 0; text-decoration: underline; color: #999; transition: color 0.2s; }
.text-btn:hover { color: #d4a373; }
.empty-msg { text-align: center; padding: 20px; color: #aaa; }

/* Modal 樣式 */
.modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 1000; display: flex; justify-content: center; align-items: center; padding: 20px; box-sizing: border-box;}
.modal-content { background: white; width: 100%; max-width: 400px; border-radius: 16px; padding: 20px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1); animation: slideUp 0.3s ease-out; }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.modal-header h3 { margin: 0; color: #8c7b75; font-size: 1.1rem; }
.close-btn { background: transparent; border: none; font-size: 1.5rem; color: #aaa; cursor: pointer; }
.input-std { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; font-size: 1rem; color: #333; outline: none; background: #f9f9f9; box-sizing: border-box; height: 44px; }
.input-std:focus { border-color: #d4a373; background: white; }
.save-btn { width: 100%; padding: 12px; background: #d4a373; color: white; border: none; border-radius: 10px; font-size: 1rem; font-weight: bold; cursor: pointer; margin-top: 10px; }
.mt-2 { margin-top: 12px; }
@keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

/* 手機版微調 */
@media (max-width: 480px) {
    .chart-header-row { flex-direction: column; align-items: flex-start; gap: 10px; }
    .date-controls { width: 100%; justify-content: space-between; }
    .stat-item .value { font-size: 1rem; } /* 手機版數字變小 */
}
</style>