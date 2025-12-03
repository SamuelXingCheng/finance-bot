<template>
  <div class="accounts-container">
    <div class="page-header">
      <div class="title-group">
        <h2>📂 帳戶管理</h2>
        <p class="subtitle">資產配置與詳細列表</p>
      </div>
      <button class="add-btn" @click="showCustomModal('新增帳戶請從 LINE Bot 輸入指令')">
        <span>+</span> 指令
      </button>
    </div>

    <div class="ai-section mb-6">
      <div v-if="aiAnalysis" class="ai-box">
        <div class="ai-header">
          <span class="ai-icon">🤖</span> 財務健檢報告
        </div>
        <div class="ai-content">{{ aiAnalysis }}</div>
      </div>
      <div v-else-if="aiLoading" class="ai-loading">
        <span class="loader"></span> 正在分析您的財務結構...
      </div>
      <button v-else @click="fetchAIAnalysis" class="ai-btn">
        ✨ 點擊生成 AI 資產配置建議
      </button>
    </div>

    <div class="charts-wrapper mb-6">
      
      <div class="chart-card">
        <h3>🍰 配置 (現金 vs 投資)</h3>
        <div class="chart-box">
          <canvas ref="allocationChartCanvas"></canvas>
        </div>
        <div class="chart-meta">
          <span class="dot cash"></span> 現金: {{ numberFormat(chartData.cash, 0) }}
          <span class="dot invest ml-2"></span> 投資: {{ numberFormat(chartData.investment, 0) }}
        </div>
      </div>

      <div class="chart-card">
        <h3>🌍 幣種分佈</h3>
        <div class="chart-box">
          <canvas ref="currencyChartCanvas"></canvas>
        </div>
      </div>

      <div class="chart-card">
        <h3>⚖️ 資產負債表</h3>
        <div class="chart-box">
          <canvas ref="netWorthChartCanvas"></canvas>
        </div>
      </div>

      <div class="chart-card wide-card">
        <div class="chart-header-row">
            <h3>📈 收支趨勢</h3>
            
            <div class="date-controls">
                <input type="date" v-model="trendFilter.start" class="date-input">
                <span class="separator">~</span>
                <input type="date" v-model="trendFilter.end" class="date-input">
                <button @click="fetchTrendData" class="filter-btn">查詢</button>
            </div>
        </div>

        <div class="chart-box-lg">
          <canvas ref="trendChartCanvas"></canvas>
        </div>
      </div>

    </div>

    <div v-if="loading" class="state-box">
      <span class="loader"></span> 讀取中...
    </div>

    <div v-else-if="accounts.length === 0" class="state-box empty">
      <p>📭 目前還沒有帳戶記錄</p>
      <p class="subtitle mt-2">請從 LINE Bot 輸入「設定 帳戶名 類型 金額 幣種」來新增。</p>
    </div>

    <div v-else class="account-list">
      <div class="list-header">詳細列表</div>
      <div v-for="account in accounts" :key="account.name" class="account-card">
        <div class="card-left">
          <div class="acc-name">{{ account.name }}</div>
          <div class="acc-meta">
            <span class="badge" :class="getTypeClass(account.type)">{{ account.type }}</span>
            <span class="currency">{{ account.currency_unit }}</span>
          </div>
        </div>
        
        <div class="card-right">
          <div class="acc-balance" :class="account.type === 'Liability' ? 'text-debt' : 'text-asset'">
            {{ numberFormat(account.balance, 2) }}
          </div>
          <button class="delete-icon" @click="handleDelete(account.name)" title="刪除">
            🗑️
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { fetchWithLiffToken, numberFormat } from '@/utils/api'; 
import { defineEmits } from 'vue';
import Chart from 'chart.js/auto';

const accounts = ref([]);
const loading = ref(true);
const aiLoading = ref(false);
const aiAnalysis = ref('');
const emit = defineEmits(['refreshDashboard']);

// 圖表數據與 Canvas Refs
const chartData = ref({ cash: 0, investment: 0, total_assets: 0, total_liabilities: 0 });
const assetBreakdown = ref({}); 
const allocationChartCanvas = ref(null);
const currencyChartCanvas = ref(null);
const netWorthChartCanvas = ref(null);
const trendChartCanvas = ref(null);

let allocChart = null;
let currChart = null;
let nwChart = null;
let trendChart = null;

// 趨勢圖篩選器
const trendFilter = ref({
    // 預設過去一年
    start: new Date(new Date().setFullYear(new Date().getFullYear() - 1)).toISOString().substring(0, 10),
    end: new Date().toISOString().substring(0, 10)
});

function showCustomModal(message) {
    console.log(`[Modal Placeholder] ${message}`);
    alert(message); 
}

// 1. 獲取帳戶列表
async function fetchAccounts() {
  loading.value = true;
  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=get_accounts`);
  if (response && response.ok) {
      const result = await response.json();
      if (result.status === 'success') {
        accounts.value = result.data;
      }
  }
  loading.value = false;
}

// 2. 獲取資產摘要 (用於前三個圖表)
async function fetchChartData() {
  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=asset_summary`);
  if (response && response.ok) {
      const result = await response.json();
      if (result.status === 'success') {
          chartData.value = result.data.charts || { cash: 0, investment: 0, total_assets: 0, total_liabilities: 0 };
          assetBreakdown.value = result.data.breakdown || {};
          
          renderAllocationChart();
          renderCurrencyChart();
          renderNetWorthChart();
      }
  }
}

// 3. 獲取趨勢數據 (用於折線圖)
async function fetchTrendData() {
  const { start, end } = trendFilter.value;
  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=trend_data&start=${start}&end=${end}`);
  
  if (response && response.ok) {
      const result = await response.json();
      if (result.status === 'success') {
          renderTrendChart(result.data);
      }
  }
}

// 4. AI 分析
async function fetchAIAnalysis() {
    aiLoading.value = true;
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=analyze_portfolio`);
    if (response && response.ok) {
        const result = await response.json();
        if (result.status === 'success') {
            aiAnalysis.value = result.data;
        } else {
            aiAnalysis.value = "AI 連線失敗。";
        }
    }
    aiLoading.value = false;
}

// --- 圖表繪製函式 ---

function renderAllocationChart() {
    if (allocChart) allocChart.destroy();
    allocChart = new Chart(allocationChartCanvas.value, {
        type: 'doughnut',
        data: {
            labels: ['現金', '投資'],
            datasets: [{
                data: [chartData.value.cash, chartData.value.investment],
                backgroundColor: ['#A8DADC', '#457B9D'], 
                borderWidth: 0
            }]
        },
        options: { cutout: '65%', plugins: { legend: { display: false } } }
    });
}

function renderCurrencyChart() {
    if (currChart) currChart.destroy();
    const labels = [];
    const data = [];
    for (const currency in assetBreakdown.value) {
        const item = assetBreakdown.value[currency];
        if (item.twd_total > 0) {
            labels.push(currency);
            data.push(item.twd_total);
        }
    }
    const colors = ['#D4A373', '#FAEDCD', '#CCD5AE', '#E9EDC9', '#A98467', '#ADC178'];

    currChart = new Chart(currencyChartCanvas.value, {
        type: 'pie',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: colors,
                borderWidth: 0
            }]
        },
        options: { plugins: { legend: { position: 'right', labels: { boxWidth: 10, font: { size: 10 } } } } }
    });
}

function renderNetWorthChart() {
    if (nwChart) nwChart.destroy();
    nwChart = new Chart(netWorthChartCanvas.value, {
        type: 'bar',
        data: {
            labels: ['資產', '負債'],
            datasets: [{
                label: '金額',
                data: [chartData.value.total_assets, chartData.value.total_liabilities],
                backgroundColor: ['#8fbc8f', '#d67a7a'],
                borderRadius: 6
            }]
        },
        options: { 
            indexAxis: 'y', 
            plugins: { legend: { display: false } },
            scales: { x: { display: false }, y: { grid: { display: false } } }
        }
    });
}

function renderTrendChart(data) {
    if (trendChart) trendChart.destroy();
    const labels = Object.keys(data); 
    const incomes = labels.map(m => data[m].income);
    const expenses = labels.map(m => data[m].expense);

    trendChart = new Chart(trendChartCanvas.value, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: '收入',
                    data: incomes,
                    borderColor: '#8fbc8f',
                    backgroundColor: 'rgba(143, 188, 143, 0.1)',
                    tension: 0.3, fill: true
                },
                {
                    label: '支出',
                    data: expenses,
                    borderColor: '#d67a7a',
                    backgroundColor: 'rgba(214, 122, 122, 0.1)',
                    tension: 0.3, fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'top' }, tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: NT$ ${numberFormat(ctx.raw, 0)}` } } },
            scales: {
                y: { beginAtZero: true, grid: { color: '#f0f0f0' }, ticks: { callback: (val) => 'NT$' + numberFormat(val, 0) } },
                x: { grid: { display: false } }
            }
        }
    });
}

// 列表操作
async function handleDelete(name) {
  if (!window.confirm(`確定要刪除 [${name}] 嗎？`)) return;
  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=delete_account`, {
    method: 'POST', body: JSON.stringify({ name: name })
  });
  if (response && response.ok) {
      fetchAccounts(); 
      fetchChartData(); 
      emit('refreshDashboard');
  }
}

function getTypeClass(type) {
  return type === 'Liability' ? 'badge-debt' : 'badge-asset';
}

onMounted(() => {
    fetchAccounts();
    fetchChartData();
    fetchTrendData();
});
</script>

<style scoped>
/* 文青風樣式 */
.accounts-container { max-width: 100%; padding-bottom: 40px; }

/* 標題區 */
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
.title-group h2 { font-size: 1.2rem; color: var(--text-primary); margin: 0; }
.subtitle { font-size: 0.85rem; color: var(--text-secondary); margin: 4px 0 0 0; }
.add-btn { background-color: var(--color-primary); color: white; border: none; padding: 6px 12px; border-radius: 20px; font-size: 0.85rem; cursor: pointer; }

/* AI 區塊 */
.ai-section { background: #fdfcf8; border: 1px dashed #d4a373; border-radius: 12px; padding: 15px; }
.ai-header { font-weight: bold; color: #8c7b75; margin-bottom: 8px; }
.ai-content { white-space: pre-wrap; font-size: 0.9rem; color: #555; line-height: 1.5; }
.ai-btn { width: 100%; padding: 8px; border: 1px solid #d4a373; color: #d4a373; background: white; border-radius: 8px; cursor: pointer; font-weight: bold; }
.ai-loading { text-align: center; color: #999; font-size: 0.85rem; }

/* 圖表容器 (RWD: 手機單欄，平板雙欄) */
.charts-wrapper { display: grid; grid-template-columns: 1fr; gap: 16px; }
@media (min-width: 600px) { .charts-wrapper { grid-template-columns: 1fr 1fr; } }

.chart-card { background: white; padding: 16px; border-radius: 16px; border: 1px solid #f0ebe5; box-shadow: var(--shadow-soft); display: flex; flex-direction: column; align-items: center; }
.chart-card h3 { font-size: 0.95rem; color: #8c7b75; margin: 0 0 12px 0; align-self: flex-start; }
.chart-box { width: 100%; height: 160px; position: relative; display: flex; justify-content: center; }
.chart-meta { margin-top: 10px; font-size: 0.8rem; color: #666; }
.dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; }
.dot.cash { background: #A8DADC; } .dot.invest { background: #457B9D; }
.ml-2 { margin-left: 8px; }

/* 寬卡片設定 (趨勢圖) */
.wide-card {
  grid-column: 1 / -1; 
  display: block; 
}

/* 趨勢圖標題與控制器 */
.chart-header-row {
    display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-bottom: 15px;
}
.chart-header-row h3 { margin: 0; white-space: nowrap; }

.date-controls {
    display: flex; align-items: center; gap: 8px; background: #f7f5f0; padding: 6px 12px; border-radius: 20px;
}
.date-input { border: none; background: transparent; color: #666; font-size: 0.85rem; outline: none; max-width: 110px; }
.separator { color: #aaa; }
.filter-btn { background-color: #d4a373; color: white; border: none; padding: 4px 12px; border-radius: 12px; font-size: 0.8rem; cursor: pointer; transition: background 0.2s; }
.filter-btn:hover { background-color: #c19263; }

.chart-box-lg { width: 100%; height: 250px; position: relative; }

/* 列表區 */
.list-header { font-size: 0.9rem; font-weight: bold; color: #8c7b75; margin-bottom: 10px; margin-top: 10px; }
.account-list { display: flex; flex-direction: column; gap: 12px; }
.account-card { background: var(--bg-card); padding: 16px; border-radius: 12px; box-shadow: var(--shadow-soft); display: flex; justify-content: space-between; align-items: center; border: 1px solid #f0ebe5; }
.acc-name { font-weight: 600; font-size: 1rem; color: var(--text-primary); }
.acc-meta { display: flex; align-items: center; gap: 8px; margin-top: 4px; }
.currency { font-size: 0.75rem; color: var(--text-secondary); }
.badge { font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; }
.badge-asset { background: #e9edc9; color: #556b2f; }
.badge-debt { background: #ffe5d9; color: #c44536; }
.card-right { text-align: right; display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
.acc-balance { font-size: 1rem; font-weight: 700; letter-spacing: 0.5px; }
.text-asset { color: var(--text-primary); } .text-debt { color: var(--color-danger); }
.delete-icon { background: transparent; border: none; cursor: pointer; font-size: 0.9rem; opacity: 0.3; padding: 4px; }
.delete-icon:hover { opacity: 1; }
.state-box { text-align: center; padding: 30px; color: var(--text-secondary); background: var(--bg-card); border-radius: var(--border-radius); box-shadow: var(--shadow-soft); }
.mb-6 { margin-bottom: 24px; }

/* RWD 調整 */
@media (max-width: 480px) {
    .chart-header-row { flex-direction: column; align-items: flex-start; gap: 10px; }
    .date-controls { width: 100%; justify-content: space-between; }
}
</style>