<template>
  <div class="accounts-container">
    <div class="page-header">
      <div class="title-group">
        <h2>帳戶管理</h2>
        <p class="subtitle">資產配置與詳細列表</p>
      </div>
      <button class="add-btn" @click="openModal()">
        <span>+</span> 新增帳戶
      </button>
    </div>

    <div class="ai-section mb-6">
      <div v-if="aiAnalysis" class="ai-box">
        <div class="ai-header">
          <span class="ai-label">AI</span> 財務健檢報告
        </div>
        <div class="ai-content">{{ aiAnalysis }}</div>
      </div>
      <div v-else-if="aiLoading" class="ai-loading">
        <span class="loader"></span> 正在分析您的財務結構...
      </div>
      <button v-else @click="fetchAIAnalysis" class="ai-btn">
        生成 AI 資產配置建議
      </button>
    </div>

    <div class="charts-wrapper mb-6">
      
      <div class="chart-card wide-card">
        <div class="chart-header-row">
            <h3>資產成長趨勢 (歷史淨值)</h3>
            <div class="date-controls">
                <button @click="fetchAssetHistory('1m')" class="filter-btn-sm" :class="{active: historyRange==='1m'}">1月</button>
                <button @click="fetchAssetHistory('6m')" class="filter-btn-sm" :class="{active: historyRange==='6m'}">6月</button>
                <button @click="fetchAssetHistory('1y')" class="filter-btn-sm" :class="{active: historyRange==='1y'}">1年</button>
            </div>
        </div>
        <div class="chart-box-lg">
          <canvas ref="assetHistoryChartCanvas"></canvas>
        </div>
        <p class="chart-hint-sm">* 顯示依據您手動記錄的「資產快照」加總，建議定期更新所有帳戶以維持準確性。</p>
      </div>

      <div class="chart-card">
        <h3>現金流配置 (現金 vs 投資)</h3>
        <div class="chart-box">
          <canvas ref="allocationChartCanvas"></canvas>
        </div>
        <div class="chart-meta">
          <span class="dot cash"></span> 現金: {{ numberFormat(chartData.cash, 0) }}
          <span class="dot invest ml-2"></span> 投資: {{ numberFormat(chartData.investment, 0) }}
        </div>
      </div>

      <div class="chart-card">
        <h3>地區配置 (台灣 vs 海外)</h3>
        <div class="chart-box">
          <canvas ref="twUsChartCanvas"></canvas>
        </div>
        <div class="chart-meta">
          <span class="dot tw-stock"></span> 台: {{ numberFormat(chartData.tw_invest, 0) }}
          <span class="dot us-stock ml-2"></span> 外: {{ numberFormat(chartData.overseas_invest, 0) }}
        </div>
      </div>

      <div class="chart-card">
        <h3>股債配置</h3>
        <div class="chart-box">
          <canvas ref="stockBondChartCanvas"></canvas>
        </div>
        <div class="chart-meta">
          <span class="dot stock"></span> 股: {{ numberFormat(chartData.stock, 0) }}
          <span class="dot bond ml-2"></span> 債: {{ numberFormat(chartData.bond, 0) }}
        </div>
      </div>

      <div class="chart-card">
        <h3>法幣 vs 加密貨幣配置</h3>
        <div class="chart-box">
          <canvas ref="currencyChartCanvas"></canvas>
        </div>
      </div>

      <div class="chart-card">
        <h3>加密貨幣分佈</h3>
        <div class="chart-box">
          <canvas ref="holdingValueChartCanvas"></canvas>
        </div>
      </div>

      <div class="chart-card">
        <h3>資產負債總覽</h3>
        <div class="chart-box">
          <canvas ref="netWorthChartCanvas"></canvas>
        </div>
      </div>

      <div class="chart-card wide-card">
        <div class="chart-header-row">
            <h3>收支趨勢</h3>
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
      <p>目前還沒有帳戶記錄</p>
      <p class="subtitle mt-2">請點擊右上方新增按鈕。</p>
    </div>

    <div v-else class="account-list">
      <div class="list-header">詳細列表</div>
      <div v-for="account in accounts" :key="account.name" class="account-card">
        <div class="card-left">
          <div class="acc-name">{{ account.name }}</div>
          <div class="acc-meta">
            <span class="badge" :class="getTypeClass(account.type)">
              {{ typeNameMap[account.type] || account.type }}
            </span>
            <span class="currency">{{ account.currency_unit }}</span>
          </div>
        </div>
        
        <div class="card-right">
          <div class="acc-balance" :class="account.type === 'Liability' ? 'text-debt' : 'text-asset'">
            {{ numberFormat(account.balance, 2) }}
          </div>
          
          <div class="action-buttons">
            <button 
                class="text-btn view-history" 
                @click="fetchAccountHistory(account.name)"
                :disabled="historyLoading"
            >
                編輯歷史資產
            </button>
            
            <button class="text-btn edit" @click="openModal(account)">更新資產</button>
            <button class="text-btn delete" @click="handleDelete(account.name)">刪除</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="isHistoryModalOpen" class="modal-backdrop" @click.self="closeHistoryModal">
        <div class="modal-content history-modal">
            <div class="modal-header">
                <h3>{{ currentAccountName }} - 歷史資產快照</h3>
                <button @click="closeHistoryModal" class="close-btn">&times;</button>
            </div>
            <div class="modal-body">
                <div v-if="historyLoading" class="list-group">
                    <li class="list-group-item">載入中...</li>
                </div>
                <div v-else-if="accountHistory.length === 0" class="list-group">
                     <li class="list-group-item">此帳戶尚無歷史資產快照記錄。</li>
                </div>
                <ul v-else class="list-group">
                    <li 
                        v-for="item in accountHistory" 
                        :key="item.snapshot_date"
                        class="list-group-item"
                    >
                        <div class="list-left">
                            <span class="date">{{ item.snapshot_date }}</span>
                            <span class="balance">
                                {{ numberFormat(item.balance, 2) }} {{ item.currency_unit }}
                            </span>
                        </div>
                        <div class="list-actions-sm">
                            <button 
                                class="text-btn edit-sm" 
                                @click="openModalForSnapshot(item)" 
                                title="修改該日資產快照"
                            >
                                修改
                            </button>
                            <button 
                                class="text-btn delete-sm" 
                                @click="handleDeleteSnapshot(item.account_name, item.snapshot_date)"
                                title="刪除該日資產快照"
                            >
                                刪除
                            </button>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <div v-if="isModalOpen" class="modal-overlay" @click.self="closeModal">
      <div class="modal-content">
        <div class="modal-header">
          <h3>{{ isEditMode ? '記錄資產快照' : '新增帳戶' }}</h3>
          <button class="close-btn" @click="closeModal">×</button>
        </div>
        
        <form @submit.prevent="handleSave">
          <div class="form-group">
            <label>帳戶名稱 (唯一識別)</label>
            <input type="text" v-model="form.name" required class="input-std" :disabled="isEditMode" placeholder="例如：錢包、台新銀行">
            <p v-if="isEditMode" class="hint">名稱無法修改，如需更名請刪除後重建。</p>
          </div>

          <div class="form-group">
            <label>資產快照日期 (生效日)</label>
            <input type="date" v-model="form.date" required class="input-std">
            <p class="hint">系統將以這天作為此餘額的記錄時間點。</p>
          </div>

          <div class="form-group">
            <label>資產類型</label>
            <select v-model="form.type" class="input-std">
              <option value="Cash">現金/活存</option>
              <option value="Stock">股票 (台灣/海外)</option>
              <option value="Bond">債券</option>
              <option value="Investment">其他投資</option>
              <option value="Liability">負債</option>
            </select>
          </div>

          <div class="form-row">
            <div class="form-group half">
              <label>資產快照餘額</label>
              <input type="number" v-model.number="form.balance" step="0.01" required class="input-std">
            </div>
            
            <div class="form-group half">
              <label>幣種</label>
              <div v-if="isCustomCurrency" class="custom-currency-wrapper">
                  <input type="text" v-model="form.currency" class="input-std" placeholder="代碼" required @input="forceUppercase">
                  <button type="button" class="back-btn" @click="resetCurrency" title="返回選單">↩</button>
              </div>
              <select v-else v-model="currencySelectValue" class="input-std" @change="handleCurrencyChange">
                <option v-for="c in currencyList" :key="c.code" :value="c.code">
                  {{ c.name }}
                </option>
                <option value="CUSTOM">➕ 自行輸入...</option>
              </select>
            </div>
          </div>

          <button type="submit" class="save-btn" :disabled="isSaving">
            {{ isSaving ? '儲存中...' : '儲存資產快照並更新' }}
          </button>
        </form>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { fetchWithLiffToken, numberFormat } from '@/utils/api'; 
import { defineEmits } from 'vue';
import Chart from 'chart.js/auto';
import ChartDataLabels from 'chartjs-plugin-datalabels';
Chart.register(ChartDataLabels);

const emit = defineEmits(['refreshDashboard']);

// 🌟 狀態變數：避免白屏的關鍵
const isHistoryModalOpen = ref(false);
const currentAccountName = ref('');
const accountHistory = ref([]);
const historyLoading = ref(false);

const accounts = ref([]);
const loading = ref(true);
const aiLoading = ref(false);
const aiAnalysis = ref('');

const typeNameMap = { 
    'Cash': '現金', 
    'Investment': '投資', 
    'Stock': '股票', 
    'Bond': '債券', 
    'Liability': '負債' 
};

const currencyList = [
  { code: 'TWD', name: '新台幣 (TWD)' }, { code: 'USD', name: '美元 (USD)' },
  { code: 'JPY', name: '日圓 (JPY)' }, { code: 'CNY', name: '人民幣 (CNY)' },
  { code: 'EUR', name: '歐元 (EUR)' }, { code: 'USDT', name: '泰達幣 (USDT)' },
  { code: 'BTC', name: '比特幣 (BTC)' }, { code: 'ETH', name: '以太幣 (ETH)' },
  { code: 'ADA', name: '艾達幣 (ADA)' },
];

const chartData = ref({ 
    cash: 0, investment: 0, total_assets: 0, total_liabilities: 0,
    stock: 0, bond: 0, tw_invest: 0, overseas_invest: 0 
});
const assetBreakdown = ref({}); 
const trendFilter = ref({
  start: new Date(new Date().setFullYear(new Date().getFullYear() - 1)).toISOString().substring(0, 10),
  end: new Date().toISOString().substring(0, 10)
});

// Canvas Refs
const allocationChartCanvas = ref(null);
const twUsChartCanvas = ref(null);
const stockBondChartCanvas = ref(null);
const currencyChartCanvas = ref(null);
const holdingValueChartCanvas = ref(null);
const netWorthChartCanvas = ref(null);
const trendChartCanvas = ref(null);
const assetHistoryChartCanvas = ref(null);
let assetHistoryChart = null;
const historyRange = ref('1y');

// Chart Instances
let allocChart = null; 
let twUsChart = null;
let stockBondChart = null;
let currChart = null; 
let holdingValueChart = null;
let nwChart = null; 
let trendChart = null;

// Modal 與表單狀態
const isModalOpen = ref(false);
const isEditMode = ref(false);
const isSaving = ref(false);
const form = ref({ 
    name: '', 
    type: 'Cash', 
    balance: 0, 
    currency: 'TWD',
    date: new Date().toISOString().substring(0, 10)
});

const currencySelectValue = ref('TWD');
const isCustomCurrency = ref(false);

const fiatCurrencies = ['TWD', 'USD', 'JPY', 'CNY', 'EUR', 'GBP', 'HKD', 'AUD', 'CAD', 'SGD', 'KRW'];


// --- 歷史快照功能 ---

async function fetchAccountHistory(name) {
    historyLoading.value = true;
    currentAccountName.value = name;
    
    try {
        const response = await fetchWithLiffToken(
            `${window.API_BASE_URL}?action=get_account_history&name=${encodeURIComponent(name)}`
        );

        if (response && response.ok) {
            const result = await response.json();
            if (result.status === 'success') {
                accountHistory.value = result.data;
                isHistoryModalOpen.value = true;
            } else {
                alert(`查詢歷史失敗: ${result.message}`);
            }
        }
    } catch (error) {
        console.error("Fetch history error:", error);
        alert("網路錯誤，無法獲取歷史記錄");
    } finally {
        historyLoading.value = false;
    }
}

async function handleDeleteSnapshot(accountName, snapshotDate) {
    if (!confirm(`確定要刪除帳戶 [${accountName}] 在 ${snapshotDate} 的歷史資產快照嗎？\n此操作不可逆，且會影響歷史圖表。`)) return;
    
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=delete_snapshot`, {
        method: 'POST', 
        body: JSON.stringify({ account_name: accountName, snapshot_date: snapshotDate })
    });
    
    if (response && response.ok) {
        const result = await response.json();
        if (result.status === 'success') {
            alert(result.message);
            fetchAccountHistory(accountName); // 刷新 Modal 內的列表
            fetchAssetHistory();              // 刷新趨勢圖
        } else {
            alert('刪除失敗: ' + (result.message || '未知錯誤'));
        }
    }
}

function closeHistoryModal() {
    isHistoryModalOpen.value = false;
    accountHistory.value = [];
}

function openModalForSnapshot(snapshotItem) {
    closeHistoryModal();

    const sourceAccount = accounts.value.find(acc => acc.name === snapshotItem.account_name);
    const accountType = sourceAccount ? sourceAccount.type : 'Cash';

    isEditMode.value = true;
    form.value = { 
        name: snapshotItem.account_name, 
        type: accountType, 
        balance: parseFloat(snapshotItem.balance), 
        currency: snapshotItem.currency_unit,
        date: snapshotItem.snapshot_date
    };
    
    const currencyToSet = snapshotItem.currency_unit;
    const knownCurrency = currencyList.find(c => c.code === currencyToSet);
    if (knownCurrency) {
        currencySelectValue.value = currencyToSet;
        isCustomCurrency.value = false;
    } else {
        currencySelectValue.value = 'CUSTOM';
        isCustomCurrency.value = true;
    }

    isModalOpen.value = true;
}


// --- 資產趨勢圖表函式 ---

async function fetchAssetHistory(range = '1y') {
    historyRange.value = range;
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=asset_history&range=${range}`);
    
    if (response && response.ok) {
        const result = await response.json();
        
        if (result.status === 'success') {
            renderAssetHistoryChart(result.data);
        }
    }
}

function renderAssetHistoryChart(resultData) {
    if (assetHistoryChart) assetHistoryChart.destroy();
    if (!assetHistoryChartCanvas.value) return;

    const labels = resultData.labels;
    const dataValues = resultData.data;

    if (labels.length === 0 || dataValues.length === 0) {
        return; 
    }

    assetHistoryChart = new Chart(assetHistoryChartCanvas.value, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: '總淨值 (TWD)',
                data: dataValues,
                borderColor: '#d4a373', 
                backgroundColor: 'rgba(212, 163, 115, 0.1)',
                borderWidth: 2,
                tension: 0.4, 
                fill: true,
                pointRadius: 4, 
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `淨值: NT$ ${numberFormat(ctx.raw, 0)}` 
                    }
                },
                datalabels: {
                    display: false 
                }
            },
            scales: {
                x: { 
                    grid: { display: false } 
                },
                y: { 
                    beginAtZero: false,
                    grid: { color: '#f0f0f0' },
                    ticks: { 
                        callback: function(value, index, values) {
                            return 'NT$' + numberFormat(value, 0); 
                        },
                        maxTicksLimit: 8 
                    }
                }
            }
        }
    });
}


// --- Modal 邏輯 ---

function handleCurrencyChange() {
    if (currencySelectValue.value === 'CUSTOM') {
        isCustomCurrency.value = true;
        form.value.currency = ''; 
    } else {
        isCustomCurrency.value = false;
        form.value.currency = currencySelectValue.value;
    }
}

function resetCurrency() {
    isCustomCurrency.value = false;
    currencySelectValue.value = 'TWD';
    form.value.currency = 'TWD';
}

function forceUppercase() {
    form.value.currency = form.value.currency.toUpperCase();
}

// --- Modal 操作 ---
function openModal(account = null) {
  const today = new Date().toISOString().substring(0, 10);

  if (account) {
    isEditMode.value = true;
    form.value = { 
      name: account.name, 
      type: account.type, 
      balance: parseFloat(account.balance), 
      currency: account.currency_unit,
      date: today
    };
    
    const knownCurrency = currencyList.find(c => c.code === account.currency_unit);
    if (knownCurrency) {
        currencySelectValue.value = account.currency_unit;
        isCustomCurrency.value = false;
    } else {
        currencySelectValue.value = 'CUSTOM';
        isCustomCurrency.value = true;
    }

  } else {
    isEditMode.value = false;
    form.value = { 
        name: '', 
        type: 'Cash', 
        balance: 0, 
        currency: 'TWD',
        date: today
    };
    resetCurrency(); 
  }
  isModalOpen.value = true;
}

function closeModal() { isModalOpen.value = false; }

async function handleSave() {
  isSaving.value = true;
  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=save_account`, {
    method: 'POST', body: JSON.stringify(form.value)
  });

  if (response && response.ok) {
    const result = await response.json();
    if (result.status === 'success') {
      closeModal();
      fetchAccounts(); 
      fetchChartData(); 
      fetchAssetHistory();
      emit('refreshDashboard');
    } else {
      alert('儲存失敗：' + result.message);
    }
  } else {
    alert('網路錯誤');
  }
  isSaving.value = false;
}


// --- API 函式 (簡化) ---
async function fetchAccounts() {
  loading.value = true;
  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=get_accounts`);
  if (response && response.ok) {
      const result = await response.json();
      if (result.status === 'success') accounts.value = result.data;
  }
  loading.value = false;
}

async function handleDelete(name) {
  if (!confirm(`確定要刪除 [${name}] 嗎？這會清除該帳戶所有歷史資產快照和資產紀錄。`)) return;
  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=delete_account`, {
    method: 'POST', body: JSON.stringify({ name: name })
  });
  if (response && response.ok) {
      fetchAccounts(); fetchChartData(); fetchAssetHistory(); emit('refreshDashboard');
  }
}

async function fetchChartData() {
  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=asset_summary`);
  if (response && response.ok) {
      const result = await response.json();
      if (result.status === 'success') {
          chartData.value = {
              ...result.data.charts,
              stock: result.data.charts.stock || 0,
              bond: result.data.charts.bond || 0,
              tw_invest: result.data.charts.tw_invest || 0,
              overseas_invest: result.data.charts.overseas_invest || 0 
          };
          assetBreakdown.value = result.data.breakdown || {};
          
          renderAllocationChart();
          renderRegionChart();      
          renderStockBondChart(); 
          renderFiatCryptoChart();  
          renderHoldingValueChart();
          renderNetWorthChart();
      }
  }
}

async function fetchTrendData() {
  const { start, end } = trendFilter.value;
  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=trend_data&start=${start}&end=${end}`);
  if (response && response.ok) {
      const result = await response.json();
      if (result.status === 'success') renderTrendChart(result.data);
  }
}

async function fetchAIAnalysis() {
    aiLoading.value = true;
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=analyze_portfolio`);
    if (response && response.ok) {
        const result = await response.json();
        if (result.status === 'success') aiAnalysis.value = result.data;
        else {
            if (result.message && result.message.includes('免費版')) {
                 aiAnalysis.value = result.message; 
            } else {
                 aiAnalysis.value = "AI 回傳錯誤: " + result.message;
            }
        }
    } else {
        aiAnalysis.value = "連線失敗。";
    }
    aiLoading.value = false;
}

// --- 完整的圖表渲染函式 (修復白屏的關鍵) ---
function renderAllocationChart() {
    if (allocChart) allocChart.destroy();
    const total = chartData.value.cash + chartData.value.investment;

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
        options: { 
            cutout: '65%', 
            plugins: { 
                legend: { display: false },
                datalabels: {
                    formatter: (value, ctx) => {
                        if (total === 0) return '';
                        const percentage = Math.round((value / total) * 100);
                        return percentage >= 5 ? percentage + '%' : '';
                    },
                    color: '#fff',
                    font: { weight: 'bold', size: 12 },
                    anchor: 'center',
                    align: 'center'
                }
            } 
        }
    });
}

function renderRegionChart() {
    if (twUsChart) twUsChart.destroy();
    const total = chartData.value.tw_invest + chartData.value.overseas_invest;

    twUsChart = new Chart(twUsChartCanvas.value, {
        type: 'doughnut',
        data: {
            labels: ['台灣', '海外'],
            datasets: [{ 
                data: [chartData.value.tw_invest, chartData.value.overseas_invest], 
                backgroundColor: ['#E9C46A', '#264653'], 
                borderWidth: 0 
            }]
        },
        options: { 
            cutout: '65%', 
            plugins: { 
                legend: { display: false },
                datalabels: {
                    formatter: (value, ctx) => {
                        if (total === 0) return '';
                        const percentage = Math.round((value / total) * 100);
                        return percentage >= 5 ? percentage + '%' : '';
                    },
                    color: '#fff',
                    font: { weight: 'bold', size: 12 },
                    anchor: 'center',
                    align: 'center'
                }
            } 
        }
    });
}

function renderStockBondChart() {
    if (stockBondChart) stockBondChart.destroy();
    const total = chartData.value.stock + chartData.value.bond;
    stockBondChart = new Chart(stockBondChartCanvas.value, {
        type: 'doughnut',
        data: { labels: ['股票', '債券'], datasets: [{ data: [chartData.value.stock, chartData.value.bond], backgroundColor: ['#F4A261', '#2A9D8F'], borderWidth: 0 }] },
        options: { cutout: '65%', plugins: { legend: { display: false }, datalabels: { formatter: (val) => total===0?'':Math.round((val/total)*100)>5?Math.round((val/total)*100)+'%':'', color:'#fff', font:{weight:'bold'} } } }
    });
}

function renderFiatCryptoChart() {
    if (currChart) currChart.destroy();
    
    let totalFiat = 0;
    let totalCrypto = 0;

    Object.entries(assetBreakdown.value).forEach(([currency, data]) => {
        if (data.twd_total <= 0) return;
        if (fiatCurrencies.includes(currency)) {
            totalFiat += data.twd_total;
        } else {
            totalCrypto += data.twd_total;
        }
    });

    const total = totalFiat + totalCrypto;

    currChart = new Chart(currencyChartCanvas.value, {
        type: 'doughnut',
        data: { 
            labels: ['法幣', '加密貨幣'], 
            datasets: [{ 
                data: [totalFiat, totalCrypto], 
                backgroundColor: ['#A5A58D', '#6B705C'],
                borderWidth: 0
            }] 
        },
        options: { 
            cutout: '65%',
            responsive: true,
            maintainAspectRatio: false, 
            plugins: { 
                legend: { display: false },
                datalabels: {
                    formatter: (value, ctx) => {
                        if (total === 0) return '';
                        const percentage = Math.round((value / total) * 100);
                        return percentage >= 3 ? percentage + '%' : '';
                    },
                    color: '#fff',
                    font: { weight: 'bold', size: 12 },
                    textShadowBlur: 2,
                    textShadowColor: 'rgba(0,0,0,0.3)'
                }
            } 
        }
    });
}

function renderHoldingValueChart() {
    if (holdingValueChart) holdingValueChart.destroy();
    
    const sortedItems = Object.entries(assetBreakdown.value)
        .filter(([key, val]) => !fiatCurrencies.includes(key) && val.twd_total > 0)
        .map(([currency, data]) => ({ currency, value: data.twd_total }))
        .sort((a, b) => b.value - a.value);

    const labels = sortedItems.map(i => i.currency);
    const dataValues = sortedItems.map(i => i.value);
    const total = dataValues.reduce((a,b) => a+b, 0);

    const cryptoColors = ['#0077B6', '#0096C7', '#00B4D8', '#48CAE4', '#90E0EF', '#ADE8F4', '#CAF0F8'];

    holdingValueChart = new Chart(holdingValueChartCanvas.value, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: dataValues,
                backgroundColor: cryptoColors, 
                borderWidth: 0
            }]
        },
        options: {
            cutout: '65%',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }, 
                datalabels: {
                    formatter: (value, ctx) => {
                        if (total === 0) return '';
                        const percentage = Math.round((value / total) * 100);
                        return percentage >= 5 ? ctx.chart.data.labels[ctx.dataIndex] + ' ' + percentage + '%' : '';
                    },
                    color: '#fff',
                    font: { size: 11, weight: 'bold' }
                }
            }
        }
    });
}

function renderNetWorthChart() {
    if (nwChart) nwChart.destroy();
    nwChart = new Chart(netWorthChartCanvas.value, {
        type: 'bar',
        data: {
            labels: ['資產', '負債'],
            datasets: [{ label: '金額', data: [chartData.value.total_assets, chartData.value.total_liabilities], backgroundColor: ['#8fbc8f', '#d67a7a'], borderRadius: 6 }]
        },
        options: { 
            indexAxis: 'y', 
            plugins: { 
                legend: { display: false },
                datalabels: { display: false } 
            }, 
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
                { label: '收入', data: incomes, borderColor: '#8fbc8f', backgroundColor: 'rgba(143, 188, 143, 0.1)', tension: 0.3, fill: true },
                { label: '支出', data: expenses, borderColor: '#d67a7a', backgroundColor: 'rgba(214, 122, 122, 0.1)', tension: 0.3, fill: true }
            ]
        },
        options: { 
            responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, 
            plugins: { 
                legend: { position: 'top' }, 
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: NT$ ${numberFormat(ctx.raw, 0)}` } },
                datalabels: { display: false } 
            }, 
            scales: { y: { beginAtZero: true, grid: { color: '#f0f0f0' }, ticks: { callback: (val) => 'NT$' + numberFormat(val, 0) } }, x: { grid: { display: false } } } 
        }
    });
}

function getTypeClass(type) { return type === 'Liability' ? 'badge-debt' : 'badge-asset'; }

onMounted(() => {
    fetchAccounts();
    fetchChartData();
    fetchTrendData();
    fetchAssetHistory(); 
});
</script>

<style scoped>
/* 文青風樣式 */
.accounts-container { max-width: 100%; padding-bottom: 40px; }
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
.title-group h2 { font-size: 1.2rem; color: var(--text-primary); margin: 0; }
.subtitle { font-size: 0.85rem; color: var(--text-secondary); margin: 4px 0 0 0; }
.add-btn { background-color: var(--color-primary); color: white; border: none; padding: 8px 16px; border-radius: 20px; font-size: 0.9rem; cursor: pointer; transition: transform 0.1s; }
.add-btn:active { transform: scale(0.95); }

/* AI 區塊 */
.ai-section { background: #fdfcf8; border: 1px dashed #d4a373; border-radius: 12px; padding: 15px; }
.ai-header { font-weight: bold; color: #8c7b75; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
.ai-label { background: #8c7b75; color: white; font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; }
.ai-content { white-space: pre-wrap; font-size: 0.9rem; color: #555; line-height: 1.5; }
.ai-btn { width: 100%; padding: 8px; border: 1px solid #d4a373; color: #d4a373; background: white; border-radius: 8px; cursor: pointer; font-weight: bold; }
.ai-loading { text-align: center; color: #999; font-size: 0.85rem; }

/* 圖表容器 */
.charts-wrapper { display: grid; grid-template-columns: 1fr; gap: 16px; }
@media (min-width: 600px) { 
    .charts-wrapper { grid-template-columns: 1fr 1fr; } 
}
.chart-card { background: white; padding: 16px; border-radius: 16px; border: 1px solid #f0ebe5; box-shadow: var(--shadow-soft); display: flex; flex-direction: column; align-items: center; }
.chart-card h3 { font-size: 0.95rem; color: #8c7b75; margin: 0 0 12px 0; align-self: flex-start; }
.chart-box { 
    width: 100%; 
    height: 220px; 
    position: relative; 
    display: flex; 
    justify-content: center; 
}
.chart-meta { margin-top: 10px; font-size: 0.8rem; color: #666; }
.dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; }
.dot.cash { background: #A8DADC; } .dot.invest { background: #457B9D; }
/* 新增的顏色點樣式 */
.dot.tw-stock { background: #E9C46A; } .dot.us-stock { background: #264653; }
.dot.stock { background: #F4A261; } .dot.bond { background: #2A9D8F; }


.chart-hint-sm {
    font-size: 0.75rem;
    color: #aaa;
    text-align: center;
    margin-top: 8px;
}
.filter-btn-sm {
    background: transparent;
    border: 1px solid #d4a373;
    color: #d4a373;
    border-radius: 12px;
    padding: 2px 8px;
    font-size: 0.75rem;
    cursor: pointer;
    margin-left: 4px;
    transition: all 0.2s;
}
.filter-btn-sm:hover {
    background: #d4a373;
    color: white;
}

.ml-2 { margin-left: 8px; }
.wide-card { grid-column: 1 / -1; display: block; }
.chart-header-row { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-bottom: 15px; }
.chart-header-row h3 { margin: 0; white-space: nowrap; }
.date-controls { display: flex; align-items: center; gap: 8px; background: #f7f5f0; padding: 6px 12px; border-radius: 20px; }
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

/* 🌟 修正 action-buttons 樣式 */
.action-buttons { 
    display: flex; 
    gap: 8px; 
    margin-top: 6px; 
    align-items: center;
}

.text-btn { background: transparent; border: none; cursor: pointer; font-size: 0.85rem; padding: 2px 4px; transition: opacity 0.2s; text-decoration: underline; }
.text-btn:hover { opacity: 0.7; }
.delete { color: #e5989b; }
.edit { color: #a98467; }

/* 🌟 快照按鈕樣式：使其與「編輯」/「刪除」風格一致 */
.text-btn.view-history {
    color: var(--text-secondary); /* 採用中性次要文字色，融入背景 */
    text-decoration: underline;
    background: none;
    border: none;
    padding: 2px 4px; /* 匹配其他 text-btn */
    cursor: pointer;
    font-size: 0.85rem; 
}
.text-btn.view-history:hover {
    color: var(--color-primary); /* Hover 時採用主題色 */
    opacity: 1; 
}

/* Modal 樣式 */
.modal-overlay {
  position: fixed; top: 0; left: 0; width: 100%; height: 100%;
  background: rgba(0, 0, 0, 0.5); z-index: 1000;
  display: flex; justify-content: center; align-items: center;
  padding: 20px;
}
.modal-content {
  background: white; width: 100%; max-width: 400px;
  border-radius: 16px; padding: 24px;
  box-shadow: 0 10px 25px rgba(0,0,0,0.1);
  animation: slideUp 0.3s ease-out;
}
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.modal-header h3 { margin: 0; color: #8c7b75; font-size: 1.1rem; }
.close-btn { background: transparent; border: none; font-size: 1.5rem; color: #aaa; cursor: pointer; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 0.85rem; color: #666; margin-bottom: 6px; }
.form-row { display: flex; gap: 12px; }
.half { flex: 1; }

.input-std {
  width: 100%; 
  padding: 10px; 
  border: 1px solid #ddd;
  border-radius: 8px; 
  font-size: 1rem; 
  color: #333; 
  outline: none;
  background: #f9f9f9;
  box-sizing: border-box; 
  line-height: 1.5;
  height: 44px; 
}
select.input-std {
  appearance: none;
  -webkit-appearance: none;
  background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%23007CB2%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E");
  background-repeat: no-repeat;
  background-position: right .7em top 50%;
  background-size: .65em auto;
}

.input-std:focus { border-color: #d4a373; background: white; }
.input-std:disabled { background: #eee; color: #999; cursor: not-allowed; }

.custom-currency-wrapper { display: flex; align-items: center; gap: 8px; width: 100%; }
.back-btn { border: none; background: #eee; border-radius: 8px; width: 44px; height: 44px; cursor: pointer; color: #666; font-size: 1.2rem; display: flex; align-items: center; justify-content: center; }

.save-btn {
  width: 100%; padding: 12px; background: #d4a373; color: white;
  border: none; border-radius: 10px; font-size: 1rem; font-weight: bold;
  cursor: pointer; margin-top: 10px;
}
.save-btn:disabled { background: #e0d0c0; cursor: wait; }
.hint { font-size: 0.75rem; color: #d67a7a; margin-top: 4px; }

@keyframes slideUp {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}

@media (max-width: 480px) {
    .chart-header-row { flex-direction: column; align-items: flex-start; gap: 10px; }
    .date-controls { width: 100%; justify-content: space-between; }
}

/* --- 歷史快照 Modal 樣式 --- */
.modal-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.6); 
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 2000; 
}

.modal-content.history-modal { 
    background: white;
    padding: 20px;
    border-radius: 12px;
    width: 90%;
    max-width: 450px; 
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
}

.list-group {
    list-style: none;
    padding: 0;
    max-height: 300px; 
    overflow-y: auto;
}

.list-group-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px dashed #f0f0f0;
    font-size: 0.95rem;
}

.list-group-item:last-child {
    border-bottom: none;
}

/* 🌟 修正：讓日期和金額垂直排列在左側，解決擠壓問題 */
.list-left {
    display: flex;
    flex-direction: column;
    min-width: 50%; /* 給予左側空間 */
}

.list-group-item .date {
    font-weight: bold;
    color: #8c7b75;
    margin-bottom: 4px; 
}

.list-group-item .balance {
    font-weight: 600;
    color: var(--text-primary);
}

/* 🌟 快照 Modal 內的 action buttons */
.list-actions-sm {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-shrink: 0; /* 確保按鈕不被壓縮 */
}

.text-btn.edit-sm, .text-btn.delete-sm {
    font-size: 0.8rem;
    padding: 2px 4px;
    text-decoration: underline; 
    font-weight: 500;
    background: none;
    border: none;
    cursor: pointer;
    transition: color 0.2s;
}

.text-btn.edit-sm {
    color: #a98467; /* 編輯色 */
}

.text-btn.delete-sm {
    color: #e5989b; /* 刪除色 */
}
</style>