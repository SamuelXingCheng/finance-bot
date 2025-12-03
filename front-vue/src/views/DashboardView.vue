<template>
  <div class="dashboard-container">
    
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
                 <input type="text" v-model="transactionForm.currency" class="input-minimal" placeholder="代碼 (如 HKD)" required @input="forceUppercase">
                 <button type="button" class="back-btn" @click="resetCurrency" title="返回選單">↩</button>
              </div>
              <select v-else v-model="currencySelectValue" class="input-minimal" @change="handleCurrencyChange">
                <option value="TWD">新台幣 (TWD)</option>
                <option value="USD">美元 (USD)</option>
                <option value="JPY">日圓 (JPY)</option>
                <option value="CNY">人民幣 (CNY)</option>
                <option value="EUR">歐元 (EUR)</option>
                <option value="USDT">泰達幣 (USDT)</option>
                <option value="CUSTOM">➕ 自行輸入...</option>
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

  </div>
</template>

<script setup>
import { ref, onMounted, nextTick } from 'vue';
import { fetchWithLiffToken, numberFormat } from '@/utils/api';
import Chart from 'chart.js/auto'; 
import ChartDataLabels from 'chartjs-plugin-datalabels';
Chart.register(ChartDataLabels);

// --- 狀態管理 ---

// 1. 本月收支相關
const totalExpense = ref(0);
const totalIncome = ref(0);
const expenseBreakdown = ref({});
const incomeBreakdown = ref({});
const currentChartType = ref('expense'); 
const expenseChartCanvas = ref(null);
let chartInstance = null;

// 2. 歷史趨勢相關
const trendFilter = ref({
    start: new Date(new Date().setFullYear(new Date().getFullYear() - 1)).toISOString().substring(0, 10),
    end: new Date().toISOString().substring(0, 10)
});
const trendChartCanvas = ref(null);
let trendChart = null;

// 3. 表單相關
const formMessage = ref('');
const messageClass = ref('');
const transactionForm = ref({
  type: 'expense',
  amount: null,
  date: new Date().toISOString().substring(0, 10),
  description: '',
  category: 'Miscellaneous',
  currency: 'TWD',
});

// 🌟 幣種選擇邏輯
const currencySelectValue = ref('TWD');
const isCustomCurrency = ref(false);

function handleCurrencyChange() {
    if (currencySelectValue.value === 'CUSTOM') {
        isCustomCurrency.value = true;
        transactionForm.value.currency = ''; // 清空讓用戶輸入
    } else {
        isCustomCurrency.value = false;
        transactionForm.value.currency = currencySelectValue.value;
    }
}

function resetCurrency() {
    isCustomCurrency.value = false;
    currencySelectValue.value = 'TWD';
    transactionForm.value.currency = 'TWD';
}

function forceUppercase() {
    transactionForm.value.currency = transactionForm.value.currency.toUpperCase();
}

// 類別中英對照表
const categoryMap = {
  'Food': '飲食', 'Transport': '交通', 'Entertainment': '娛樂', 'Shopping': '購物',
  'Bills': '帳單', 'Investment': '投資', 'Medical': '醫療', 'Education': '教育',
  'Miscellaneous': '其他', 'Salary': '薪水', 'Allowance': '津貼', 'Bonus': '獎金',
};

// 色票產生器
const palette = [
    '#D4A373', '#FAEDCD', '#CCD5AE', '#E9EDC9', '#A98467', 
    '#ADC178', '#6C584C', '#B5838D', '#E5989B', '#FFB4A2',
    '#8fbc8f', '#4682b4', '#d2b48c'
];

// --- API 函式 ---

async function fetchExpenseData() {
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=monthly_expense_breakdown`);
    if (response && response.ok) {
        const result = await response.json();
        if (result.status === 'success') {
            totalExpense.value = result.data.total_expense;
            totalIncome.value = result.data.total_income || 0;
            expenseBreakdown.value = result.data.breakdown || {};
            incomeBreakdown.value = result.data.income_breakdown || {};
            
            await nextTick();
            renderChart();
        }
    }
}

async function fetchTrendData() {
  const { start, end } = trendFilter.value;
  // 🌟 關鍵：加上 mode=category 參數
  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=trend_data&start=${start}&end=${end}&mode=category`);
  
  if (response && response.ok) {
      const result = await response.json();
      if (result.status === 'success') {
          renderTrendChart(result.data);
      }
  }
}

// --- 圖表渲染 ---

// 1. 圓餅圖
function toggleChart(type) {
  currentChartType.value = type;
  nextTick(() => { renderChart(); });
}

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
    data: {
      labels: labels,
      datasets: [{
        data: dataValues,
        backgroundColor: palette,
        borderWidth: 0,
        hoverOffset: 6,
      }],
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20, color: '#666' } },
            title: { display: false },
            tooltip: {
              callbacks: {
                label: (context) => {
                  let label = context.label || '';
                  if (label) label += ': ';
                  let value = context.raw;
                  let percentage = Math.round((value / totalValue) * 100) + '%';
                  return label + 'NT$ ' + numberFormat(value, 0) + ' (' + percentage + ')';
                }
              }
            },
            datalabels: {
                formatter: (value, ctx) => {
                    const percentage = Math.round((value / totalValue) * 100);
                    return percentage > 4 ? percentage + '%' : '';
                },
                color: '#fff',
                font: { weight: 'bold', size: 11 },
                anchor: 'center',
                align: 'center'
            }
        },
        cutout: '65%',
    },
  });
}

// 2. 🌟 歷史分類趨勢圖 (多條折線)
function renderTrendChart(data) {
    if (trendChart) trendChart.destroy();
    if (!trendChartCanvas.value) return;

    const labels = Object.keys(data); // 月份
    
    // 1. 找出所有出現過的 Category
    const allCategories = new Set();
    labels.forEach(month => {
        Object.keys(data[month]).forEach(cat => allCategories.add(cat));
    });

    // 2. 為每個 Category 建立 Dataset
    const datasets = Array.from(allCategories).map((cat, index) => {
        const catData = labels.map(month => data[month][cat] || 0); // 若該月無此分類數據補 0
        const color = palette[index % palette.length];
        
        return {
            label: categoryMap[cat] || cat, // 轉中文
            data: catData,
            borderColor: color,
            backgroundColor: color,
            tension: 0.3,
            fill: false, // 不填充，保持線條清晰
            pointRadius: 3
        };
    });

    trendChart = new Chart(trendChartCanvas.value, {
        type: 'line',
        data: {
            labels: labels,
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { 
                legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } }, 
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: NT$ ${numberFormat(ctx.raw, 0)}` } },
                datalabels: { display: false }
            },
            scales: {
                y: { beginAtZero: true, grid: { color: '#f0f0f0' }, ticks: { callback: (val) => 'NT$' + numberFormat(val, 0) } },
                x: { grid: { display: false } }
            }
        }
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
      formMessage.value = '成功：' + result.message;
      messageClass.value = 'msg-success';
      transactionForm.value.amount = null;
      transactionForm.value.description = '';
      
      fetchExpenseData();
      fetchTrendData();
      
      setTimeout(() => { formMessage.value = ''; }, 3000);
    } else {
      formMessage.value = '錯誤：' + (result.message || '新增失敗');
      messageClass.value = 'msg-error';
    }
  }
}

defineExpose({ refreshAllData: () => { fetchExpenseData(); fetchTrendData(); } });

onMounted(() => {
    fetchExpenseData();
    fetchTrendData();
});
</script>

<style scoped>
/* 樣式與之前一致，但增加了 custom-currency-wrapper 的樣式 */
.dashboard-container { max-width: 100%; margin: 0 auto; color: #5d5d5d; font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; letter-spacing: 0.03em; }
.card-section { margin-bottom: 24px; }
.section-header h2 { font-size: 1.1rem; font-weight: 600; color: #8c7b75; margin-bottom: 12px; margin-left: 4px; position: relative; }
.data-box { background-color: #ffffff; border-radius: 16px; padding: 24px; box-shadow: 0 4px 20px rgba(220, 210, 200, 0.3); border: 1px solid #f0ebe5; transition: transform 0.2s ease; }

.input-minimal { width: 100%; padding: 10px 0; border: none; border-bottom: 1px solid #e0e0e0; background: transparent; font-size: 16px; color: #333; border-radius: 0; transition: border-color 0.3s; box-sizing: border-box; }
.input-minimal:focus { outline: none; border-bottom: 1px solid #d4a373; }
.form-group { margin-bottom: 20px; }
.form-group label { display: block; font-size: 0.85rem; color: #999; margin-bottom: 4px; }
.form-row { display: flex; gap: 16px; }
.half { flex: 1; }

/* 幣種自訂輸入區塊 */
.custom-currency-wrapper { display: flex; align-items: center; gap: 8px; }
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

/* Chart */
.chart-card { display: flex; flex-direction: column; align-items: center; }
.stats-row { display: flex; justify-content: space-around; align-items: center; width: 100%; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px dashed #f0ebe5; }
.stat-item { text-align: center; flex: 1; padding: 8px; border-radius: 12px; transition: background-color 0.2s, transform 0.1s; }
.cursor-pointer { cursor: pointer; }
.active-stat { background-color: #f7f5f0; box-shadow: inset 0 2px 4px rgba(0,0,0,0.05); }
.vertical-line { width: 1px; height: 40px; background-color: #f0ebe5; }
.stat-item .label { display: block; font-size: 0.85rem; color: #999; margin-bottom: 4px; }
.stat-item .value { font-size: 1.4rem; font-weight: 700; letter-spacing: 0.5px; }
.text-income { color: #8fbc8f; } 
.text-expense { color: #d67a7a; } 

#chart-container, .chart-box-lg { width: 100%; height: 250px; position: relative; }
.no-data-msg { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #aaa; font-size: 0.9rem; width: 100%; text-align: center; }
.chart-hint { font-size: 0.75rem; color: #aaa; margin-top: 10px; text-align: center; }

/* Date Control */
.date-controls { display: flex; align-items: center; gap: 8px; background: #f7f5f0; padding: 6px 12px; border-radius: 20px; width: 100%; justify-content: space-between; box-sizing: border-box; }
.date-input { border: none; background: transparent; color: #666; font-size: 0.85rem; outline: none; width: 35%; }
.separator { color: #aaa; }
.filter-btn { background-color: #d4a373; color: white; border: none; padding: 4px 12px; border-radius: 12px; font-size: 0.8rem; cursor: pointer; transition: background 0.2s; white-space: nowrap;}
.filter-btn:hover { background-color: #c19263; }
.mb-4 { margin-bottom: 16px; }

/* Message */
.msg-processing { color: #999; margin-top: 15px; font-size: 0.9rem; text-align: center;}
.msg-success { background-color: #f0f7f0; color: #556b2f; padding: 10px; border-radius: 8px; margin-top: 15px; font-size: 0.9rem; text-align: center; }
.msg-error { background-color: #fff0f0; color: #d67a7a; padding: 10px; border-radius: 8px; margin-top: 15px; font-size: 0.9rem; text-align: center; }
.fade-enter-active, .fade-leave-active { transition: opacity 0.5s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>