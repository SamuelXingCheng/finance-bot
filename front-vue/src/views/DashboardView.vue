<template>
  <div class="dashboard-container">
    <div class="card-section">
      <div class="section-header">
        <h2>✏️ 記一筆</h2>
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
              <input type="text" v-model="transactionForm.currency" maxlength="5" required class="input-minimal">
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
                <option value="Food">🍱 飲食 (Food)</option>
                <option value="Transport">🚗 交通 (Transport)</option>
                <option value="Entertainment">🎮 娛樂 (Entertainment)</option>
                <option value="Shopping">🛍️ 購物 (Shopping)</option>
                <option value="Bills">🧾 帳單 (Bills)</option>
                <option value="Investment">📈 投資 (Investment)</option>
                <option value="Medical">💊 醫療 (Medical)</option>
                <option value="Education">📚 教育 (Education)</option>
                <option value="Salary">💰 薪水 (Salary)</option>
                <option value="Allowance">🎁 津貼 (Allowance)</option>
                <option value="Miscellaneous">✨ 其他 (Miscellaneous)</option>
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
        <h2>💰 資產小計</h2>
      </div>
      
      <div v-if="assetLoading" class="loading-box">
        <span class="loader"></span> 載入中...
      </div>
      <div v-else-if="assetError" class="error-box">{{ assetError }}</div>
      
      <div v-else id="asset-summary" class="data-box asset-card">
          <div class="total-net-worth">
            <p class="label">全球淨值 (TWD)</p>
            <p class="amount" :class="globalNetWorth >= 0 ? 'text-earth-green' : 'text-earth-red'">
              NT$ {{ numberFormat(globalNetWorth, 2) }}
            </p>
          </div>
          
          <div class="divider"></div>
          
          <h3 class="sub-title">各幣種明細</h3>
          <ul class="asset-list">
            <li v-for="(data, currency) in assetData.breakdown" :key="currency" class="asset-item">
              <div class="asset-info">
                <span class="currency-tag">{{ currency }}</span>
                <span class="currency-amount" :class="data.net_worth >= 0 ? 'text-dark-green' : 'text-dark-red'">
                  {{ numberFormat(data.net_worth, 2) }}
                </span>
              </div>
              <div class="twd-val">≈ NT$ {{ numberFormat(data.twd_total, 2) }}</div>
            </li>
          </ul>
      </div>
    </div>

    <div class="card-section">
      <div class="section-header">
        <h2>📊 本月開銷</h2>
      </div>
      <div id="expense-breakdown" class="data-box chart-card">
          <div class="total-expense-display">
            <span class="label">總支出</span>
            <span class="value">NT$ {{ numberFormat(totalExpense, 2) }}</span>
          </div>
          <div id="chart-container">
              <canvas ref="expenseChartCanvas"></canvas>
          </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import { fetchWithLiffToken, numberFormat, generateColors } from '@/utils/api';
import Chart from 'chart.js/auto'; 

// 狀態管理
const assetData = ref({ breakdown: {}, global_twd_net_worth: 0 });
const assetLoading = ref(true);
const assetError = ref('');
const totalExpense = ref(0);
const expenseBreakdown = ref({});
const chartInstance = ref(null);
const expenseChartCanvas = ref(null);
const formMessage = ref('');
const messageClass = ref('');

// 表單數據
const transactionForm = ref({
  type: 'expense',
  amount: null,
  date: new Date().toISOString().substring(0, 10),
  description: '',
  category: 'Miscellaneous',
  currency: 'TWD',
});

// 計算屬性
const globalNetWorth = computed(() => assetData.value.global_twd_net_worth || 0);

// --- API 函式 ---
async function fetchAssetSummary() {
    assetLoading.value = true;
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=asset_summary`);
    if (response) {
        const result = await response.json();
        if (result.status === 'success') {
            assetData.value = result.data;
        } else {
            assetError.value = result.message || '載入失敗';
        }
    }
    assetLoading.value = false;
}

async function fetchExpenseData() {
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=monthly_expense_breakdown`);
    if (response) {
        const result = await response.json();
        if (result.status === 'success') {
            totalExpense.value = result.data.total_expense;
            expenseBreakdown.value = result.data.breakdown;
            renderChart();
        }
    }
}

// --- 圖表渲染 (使用文青色系) ---
function renderChart() {
  if (chartInstance.value) {
    chartInstance.value.destroy();
  }

  const labels = Object.keys(expenseBreakdown.value);
  const dataValues = Object.values(expenseBreakdown.value).map(v => parseFloat(v));

  if (labels.length === 0 || totalExpense.value <= 0) return;

  // 定義一組莫蘭迪色系/大地色系
  const morandiColors = [
    '#D4A373', '#FAEDCD', '#CCD5AE', '#E9EDC9', '#A98467', 
    '#ADC178', '#6C584C', '#B5838D', '#E5989B', '#FFB4A2'
  ];

  chartInstance.value = new Chart(expenseChartCanvas.value, {
    type: 'doughnut', // 改用甜甜圈圖，比較時尚
    data: {
      labels: labels,
      datasets: [{
        data: dataValues,
        backgroundColor: morandiColors, // 使用自訂色系
        borderWidth: 0, // 去掉邊框
        hoverOffset: 6,
      }],
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { 
              position: 'bottom',
              labels: {
                usePointStyle: true,
                padding: 20,
                font: { family: 'sans-serif', size: 12 },
                color: '#666'
              }
            },
            title: { display: false }
        },
        cutout: '65%', // 甜甜圈中間留白
    },
  });
}

// --- 表單提交 ---
async function handleTransactionSubmit() {
  formMessage.value = '處理中...';
  messageClass.value = 'msg-processing';

  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=add_transaction`, {
    method: 'POST',
    body: JSON.stringify(transactionForm.value)
  });

  if (response) {
    const result = await response.json();
    if (result.status === 'success') {
      formMessage.value = '✨ ' + result.message;
      messageClass.value = 'msg-success';
      transactionForm.value.amount = null;
      transactionForm.value.description = '';
      transactionForm.value.category = 'Miscellaneous';
      
      fetchAssetSummary();
      fetchExpenseData();
      
      // 3秒後消失
      setTimeout(() => { formMessage.value = ''; }, 3000);
    } else {
      formMessage.value = '❌ ' + (result.message || '新增失敗');
      messageClass.value = 'msg-error';
    }
  }
}

defineExpose({ refreshAllData: () => { fetchAssetSummary(); fetchExpenseData(); } });

onMounted(() => {
    fetchAssetSummary();
    fetchExpenseData();
});
</script>

<style scoped>
/* --- 文青風/米色系 CSS --- */

/* 1. 全局變數 */
.dashboard-container {
  max-width: 100%;
  margin: 0 auto;
  color: #5d5d5d; /* 深灰文字 */
  font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
  letter-spacing: 0.03em;
}

/* 2. 卡片設計 */
.card-section {
  margin-bottom: 24px;
}

.section-header h2 {
  font-size: 1.1rem;
  font-weight: 600;
  color: #8c7b75; /* 暖棕色標題 */
  margin-bottom: 12px;
  margin-left: 4px;
  position: relative;
}

.data-box {
  background-color: #ffffff;
  border-radius: 16px; /* 更圓潤 */
  padding: 24px;
  box-shadow: 0 4px 20px rgba(220, 210, 200, 0.3); /* 暖色系陰影 */
  border: 1px solid #f0ebe5;
  transition: transform 0.2s ease;
}

/* 3. 表單元素 (Input Minimal Style) */
.input-minimal {
  width: 100%;
  padding: 10px 0;
  border: none;
  border-bottom: 1px solid #e0e0e0;
  background: transparent;
  font-size: 16px;
  color: #333;
  border-radius: 0;
  transition: border-color 0.3s;
  box-sizing: border-box;
}

.input-minimal:focus {
  outline: none;
  border-bottom: 1px solid #d4a373; /* 聚焦時變大地色 */
}

.form-group {
  margin-bottom: 20px;
}

.form-group label {
  display: block;
  font-size: 0.85rem;
  color: #999;
  margin-bottom: 4px;
}

.form-row {
  display: flex;
  gap: 16px;
}
.half {
  flex: 1;
}

/* Radio 按鈕設計 */
.radio-group {
  display: flex;
  background: #f7f5f0;
  border-radius: 8px;
  padding: 4px;
}

.radio-label {
  flex: 1;
  text-align: center;
  padding: 8px 0;
  cursor: pointer;
  border-radius: 6px;
  font-size: 0.9rem;
  color: #888;
  transition: all 0.3s;
  position: relative;
}

.radio-label input {
  display: none;
}

.radio-label.active {
  background: #ffffff;
  color: #d4a373;
  font-weight: bold;
  box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

/* 下拉選單 */
.select-wrapper {
  position: relative;
}
.select-wrapper::after {
  content: '▼';
  font-size: 0.7rem;
  color: #aaa;
  position: absolute;
  right: 0;
  top: 14px;
  pointer-events: none;
}

/* 提交按鈕 */
.submit-btn {
  width: 100%;
  padding: 14px;
  background-color: #d4a373; /* 經典大地色 */
  color: white;
  border: none;
  border-radius: 12px;
  font-size: 1rem;
  font-weight: 500;
  cursor: pointer;
  margin-top: 10px;
  transition: background-color 0.3s, transform 0.1s;
  box-shadow: 0 4px 10px rgba(212, 163, 115, 0.3);
}

.submit-btn:hover {
  background-color: #c19263;
}
.submit-btn:active {
  transform: scale(0.98);
}

/* 4. 資產卡片 */
.asset-card {
  text-align: center;
}

.total-net-worth .label {
  font-size: 0.9rem;
  color: #999;
  margin-bottom: 4px;
}

.total-net-worth .amount {
  font-size: 2rem;
  font-weight: 700;
  margin: 0;
  letter-spacing: 0.5px;
}

.divider {
  height: 1px;
  background-color: #f0ebe5;
  margin: 20px 0;
}

.sub-title {
  font-size: 0.95rem;
  color: #8c7b75;
  margin-bottom: 16px;
  text-align: left;
}

.asset-list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.asset-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 0;
  border-bottom: 1px dashed #eee;
}

.asset-item:last-child {
  border-bottom: none;
}

.asset-info {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
}

.currency-tag {
  font-size: 0.75rem;
  background: #f4f1ea;
  padding: 2px 6px;
  border-radius: 4px;
  color: #888;
  margin-bottom: 2px;
}

.currency-amount {
  font-weight: 600;
  font-size: 1rem;
}

.twd-val {
  font-size: 0.85rem;
  color: #bbb;
}

/* 5. 圖表卡片 */
.chart-card {
  display: flex;
  flex-direction: column;
  align-items: center;
}

.total-expense-display {
  text-align: center;
  margin-bottom: 20px;
}

.total-expense-display .label {
  display: block;
  font-size: 0.85rem;
  color: #999;
}

.total-expense-display .value {
  font-size: 1.6rem;
  font-weight: 700;
  color: #d67a7a; /* 柔和紅 */
}

#chart-container {
  width: 100%;
  height: 250px; /* 固定高度讓甜甜圈圖好看 */
  position: relative;
}

/* 顏色工具類 */
.text-earth-green { color: #8fbc8f; } /* 鼠尾草綠 */
.text-earth-red { color: #d67a7a; }   /* 乾燥玫瑰紅 */
.text-dark-green { color: #556b2f; }
.text-dark-red { color: #b22222; }

/* Loading & Message */
.loading-box {
  text-align: center;
  color: #aaa;
  padding: 40px;
  background: #fff;
  border-radius: 16px;
}

.msg-processing { color: #999; margin-top: 15px; font-size: 0.9rem; text-align: center;}
.msg-success { 
  background-color: #f0f7f0; 
  color: #556b2f; 
  padding: 10px; 
  border-radius: 8px; 
  margin-top: 15px; 
  font-size: 0.9rem;
  text-align: center;
}
.msg-error { 
  background-color: #fff0f0; 
  color: #d67a7a; 
  padding: 10px; 
  border-radius: 8px; 
  margin-top: 15px; 
  font-size: 0.9rem; 
  text-align: center;
}

/* 動畫 */
.fade-enter-active, .fade-leave-active {
  transition: opacity 0.5s;
}
.fade-enter-from, .fade-leave-to {
  opacity: 0;
}
</style>