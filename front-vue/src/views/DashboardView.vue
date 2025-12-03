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
                <option value="Bonus">🧧 獎金 (Bonus)</option>
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
        <h2>📊 本月收支分佈</h2> 
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
                📭 本月尚無{{ currentChartType === 'expense' ? '支出' : '收入' }}紀錄
              </div>
              <canvas v-else ref="expenseChartCanvas"></canvas>
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

// 收支數據
const totalExpense = ref(0);
const totalIncome = ref(0);
const expenseBreakdown = ref({});
const incomeBreakdown = ref({});
const currentChartType = ref('expense'); // 預設顯示支出

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

// 類別中英對照表
const categoryMap = {
  // 支出
  'Food': '🍱 飲食',
  'Transport': '🚗 交通',
  'Entertainment': '🎮 娛樂',
  'Shopping': '🛍️ 購物',
  'Bills': '🧾 帳單',
  'Investment': '📈 投資',
  'Medical': '💊 醫療',
  'Education': '📚 教育',
  'Miscellaneous': '✨ 其他',
  // 收入
  'Salary': '💰 薪水',
  'Allowance': '🎁 津貼',
  'Bonus': '🧧 獎金',
};

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
            totalIncome.value = result.data.total_income || 0;
            // 確保 breakdown 存在，避免 null 錯誤
            expenseBreakdown.value = result.data.breakdown || {};
            incomeBreakdown.value = result.data.income_breakdown || {};
            
            renderChart();
        }
    }
}

// 切換圖表類型的函式
function toggleChart(type) {
  currentChartType.value = type;
  renderChart();
}

// --- 圖表渲染 (支援中文化與切換) ---
function renderChart() {
  if (chartInstance.value) {
    chartInstance.value.destroy();
  }

  // 1. 根據目前模式決定使用哪一份數據
  const sourceData = currentChartType.value === 'expense' ? expenseBreakdown.value : incomeBreakdown.value;
  const totalValue = currentChartType.value === 'expense' ? totalExpense.value : totalIncome.value;

  const rawLabels = Object.keys(sourceData);
  
  // 2. 如果沒有數據，直接返回 (由 template 的 v-if 處理顯示)
  if (rawLabels.length === 0 || totalValue <= 0) return;

  // 3. 將英文 Label 轉為中文
  const labels = rawLabels.map(key => categoryMap[key] || key);
  
  const dataValues = Object.values(sourceData).map(v => parseFloat(v));

  // 莫蘭迪色系
  const morandiColors = [
    '#D4A373', '#FAEDCD', '#CCD5AE', '#E9EDC9', '#A98467', 
    '#ADC178', '#6C584C', '#B5838D', '#E5989B', '#FFB4A2'
  ];

  chartInstance.value = new Chart(expenseChartCanvas.value, {
    type: 'doughnut',
    data: {
      labels: labels,
      datasets: [{
        data: dataValues,
        backgroundColor: morandiColors,
        borderWidth: 0,
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
                font: { family: '"Helvetica Neue", "Microsoft JhengHei", sans-serif', size: 12 },
                color: '#666'
              }
            },
            title: { display: false },
            tooltip: {
              callbacks: {
                label: function(context) {
                  let label = context.label || '';
                  if (label) label += ': ';
                  let value = context.raw;
                  let percentage = Math.round((value / totalValue) * 100) + '%';
                  return label + 'NT$ ' + numberFormat(value, 0) + ' (' + percentage + ')';
                }
              }
            }
        },
        cutout: '65%',
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
      
      // 重新載入數據以更新圖表
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

// 暴露給父組件調用
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
  color: #5d5d5d;
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
  color: #8c7b75;
  margin-bottom: 12px;
  margin-left: 4px;
  position: relative;
}

.data-box {
  background-color: #ffffff;
  border-radius: 16px;
  padding: 24px;
  box-shadow: 0 4px 20px rgba(220, 210, 200, 0.3);
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
  border-bottom: 1px solid #d4a373;
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
  background-color: #d4a373;
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

/* 互動式統計列 */
.stats-row {
  display: flex;
  justify-content: space-around;
  align-items: center;
  width: 100%;
  margin-bottom: 24px;
  padding-bottom: 16px;
  border-bottom: 1px dashed #f0ebe5;
}

.stat-item {
  text-align: center;
  flex: 1;
  padding: 8px;
  border-radius: 12px;
  transition: background-color 0.2s, transform 0.1s;
}

/* 游標樣式 & 點擊效果 */
.cursor-pointer {
  cursor: pointer;
}
.cursor-pointer:active {
  transform: scale(0.98);
}

/* 選中狀態 */
.active-stat {
  background-color: #f7f5f0; /* 淺米色背景 */
  box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
}

.vertical-line {
  width: 1px;
  height: 40px;
  background-color: #f0ebe5;
}

.stat-item .label {
  display: block;
  font-size: 0.85rem;
  color: #999;
  margin-bottom: 4px;
}

.stat-item .value {
  font-size: 1.4rem;
  font-weight: 700;
  letter-spacing: 0.5px;
}

.text-income { color: #8fbc8f; } /* 柔和綠 */
.text-expense { color: #d67a7a; } /* 柔和紅 */

/* 圖表容器 */
#chart-container {
  width: 100%;
  height: 250px;
  position: relative;
}

.no-data-msg {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  color: #aaa;
  font-size: 0.9rem;
  width: 100%;
  text-align: center;
}

/* 顏色工具類 */
.text-earth-green { color: #8fbc8f; } 
.text-earth-red { color: #d67a7a; }   
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