<template>
  <div class="crypto-container">
    
    <div class="dashboard-header">
      <div class="header-content">
        <div class="subtitle">Total Balance (Est.)</div>
        <div class="main-balance">
          <span class="currency-symbol">$</span>
          {{ numberFormat(dashboard.totalUsd, 2) }}
          <span class="currency-code">USD</span>
        </div>
        
        <div class="stats-row">
          <div class="stat-item">
            <span class="label">本金 (TWD)</span>
            <span class="value">NT$ {{ numberFormat(dashboard.totalInvestedTwd, 0) }}</span>
          </div>
          <div class="vertical-line"></div>
          <div class="stat-item">
            <span class="label">未實現損益</span>
            <span class="value" :class="dashboard.pnl >= 0 ? 'text-profit' : 'text-loss'">
              {{ dashboard.pnl >= 0 ? '+' : '' }}{{ numberFormat(dashboard.pnl, 2) }} 
              <small>({{ numberFormat(dashboard.pnlPercent, 2) }}%)</small>
            </span>
          </div>
        </div>
      </div>
    </div>

    <div class="card-section chart-card wide-card">
        <div class="chart-header-row">
            <h3>資產成長趨勢 (USD)</h3>
            <div class="date-controls">
                <button @click="fetchHistory('1m')" class="filter-btn-sm" :class="{active: historyRange==='1m'}">1月</button>
                <button @click="fetchHistory('6m')" class="filter-btn-sm" :class="{active: historyRange==='6m'}">6月</button>
                <button @click="fetchHistory('1y')" class="filter-btn-sm" :class="{active: historyRange==='1y'}">1年</button>
            </div>
        </div>
        <div class="chart-box-lg">
            <canvas ref="historyChartCanvas"></canvas>
        </div>
        <p class="chart-hint-sm">* 趨勢圖依據您的交易與快照紀錄繪製。</p>
    </div>

    <div class="list-section">
      <div class="section-header">
        <h3>持倉資產</h3>
        <button class="add-btn" @click="openTransactionModal()">
          <span>+</span> 記一筆
        </button>
      </div>

      <div v-if="holdings.length === 0" class="empty-state">
        <p>尚未有交易紀錄</p>
        <p class="sub-text">點擊上方按鈕開始記錄您的第一筆交易。</p>
      </div>

      <div v-else class="coin-list">
        <div v-for="coin in holdings" :key="coin.symbol" class="account-card-style">
          
          <div class="card-left">
            <div class="acc-name">{{ coin.symbol }}</div>
            <div class="acc-meta">
              <span class="badge" :class="coin.symbol === 'USDT' ? 'badge-stable' : 'badge-crypto'">
                {{ coin.symbol === 'USDT' ? '穩定幣' : '投資' }}
              </span>
              <span class="currency">均價: ${{ numberFormat(coin.avgPrice, 2) }}</span>
            </div>
          </div>
          
          <div class="card-right">
            <div class="acc-balance" :class="coin.valueUsd >= 0 ? 'text-asset' : 'text-debt'">
              $ {{ numberFormat(coin.valueUsd, 2) }}
            </div>
            
            <div class="action-buttons">
              <button class="pill-btn update-crypto" @click.stop="openEditBalanceModal(coin)">
                  更新快照
              </button>
              
              <button class="text-btn view-history" @click="alert('歷史功能開發中...')">
                  歷史
              </button>
              
              <button class="text-btn delete" @click="alert('請透過「賣出」或「出金」將餘額歸零以移除此資產。')">
                  刪除
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div v-if="isModalOpen" class="modal-overlay" @click.self="closeModal">
      <div class="modal-content">
        <div class="modal-header">
          <h3>新增紀錄</h3>
          <button class="close-btn" @click="closeModal">×</button>
        </div>

        <div class="tabs">
          <button v-for="tab in tabs" :key="tab.id" class="tab-btn" :class="{ active: currentTab === tab.id }" @click="switchTab(tab.id)">{{ tab.name }}</button>
        </div>

        <form @submit.prevent="submitTransaction" class="tx-form">
          <div v-if="currentTab === 'fiat'">
            <div class="form-group"><label>動作方向</label><div class="radio-group"><label class="radio-label" :class="{ active: form.type === 'deposit' }"><input type="radio" v-model="form.type" value="deposit"> 入金 (TWD → U)</label><label class="radio-label" :class="{ active: form.type === 'withdraw' }"><input type="radio" v-model="form.type" value="withdraw"> 出金 (U → TWD)</label></div></div>
            <div class="form-row"><div class="form-group half"><label>台幣金額 (TWD)</label><input type="number" step="any" v-model.number="form.total" class="input-std" placeholder="例如 100000" required></div><div class="form-group half"><label>收到/轉出 (USDT)</label><input type="number" step="any" v-model.number="form.quantity" class="input-std" placeholder="例如 3150" required></div></div>
          </div>
          <div v-if="currentTab === 'trade'">
            <div class="form-group"><label>交易對 (Pair)</label><div class="input-group"><input type="text" v-model="form.baseCurrency" class="input-std uppercase" placeholder="BTC" style="flex:2" required><span class="separator">/</span><input type="text" v-model="form.quoteCurrency" class="input-std uppercase" placeholder="USDT" style="flex:1" readonly></div></div>
            <div class="form-group"><label>動作</label><div class="radio-group"><label class="radio-label buy" :class="{ active: form.type === 'buy' }"><input type="radio" v-model="form.type" value="buy"> 買入 (Buy)</label><label class="radio-label sell" :class="{ active: form.type === 'sell' }"><input type="radio" v-model="form.type" value="sell"> 賣出 (Sell)</label></div></div>
            <div class="form-row"><div class="form-group half"><label>成交價格 (Price)</label><input type="number" step="any" v-model.number="form.price" class="input-std" placeholder="單價" @input="calcTotal"></div><div class="form-group half"><label>數量 (Amount)</label><input type="number" step="any" v-model.number="form.quantity" class="input-std" placeholder="數量" @input="calcTotal"></div></div>
            <div class="form-group"><label>總金額 (Total USDT)</label><input type="number" step="any" v-model.number="form.total" class="input-std" placeholder="系統自動計算" @input="calcQuantity"></div>
          </div>
          <div v-if="currentTab === 'earn'">
            <div class="form-group"><label>幣種</label><input type="text" v-model="form.baseCurrency" class="input-std uppercase" placeholder="例如: ETH"></div><div class="form-group"><label>獲得數量</label><input type="number" step="any" v-model.number="form.quantity" class="input-std" placeholder="0.00"></div>
          </div>
          <div class="form-row mt-4"><div class="form-group half"><label>手續費 (Fee)</label><input type="number" step="any" v-model.number="form.fee" class="input-std" placeholder="0"></div><div class="form-group half"><label>日期</label><input type="date" v-model="form.date" class="input-std" required></div></div>
          
          <button type="submit" class="save-btn main-action">{{ submitButtonText }}</button>
        </form>
      </div>
    </div>

    <div v-if="isEditBalanceOpen" class="modal-overlay" @click.self="closeEditModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>更新快照: {{ editBalanceForm.symbol }}</h3>
                <button class="close-btn" @click="closeEditModal">×</button>
            </div>
            <p class="hint-text">請輸入該資產在指定日期的實際餘額，系統將自動補齊差額記錄。</p>
            
            <form @submit.prevent="submitBalanceAdjustment">
                <div class="form-group mt-4">
                    <label>快照日期</label>
                    <input type="date" v-model="editBalanceForm.date" class="input-std" required>
                </div>
                <div class="form-group">
                    <label>目前紀錄餘額: {{ numberFormat(editBalanceForm.current, 6) }}</label>
                    <label class="mt-2" style="color:#2A9D8F; font-weight:bold;">實際正確餘額:</label>
                    <input type="number" step="any" v-model.number="editBalanceForm.newBalance" class="input-std" required>
                </div>
                <button type="submit" class="save-btn update-crypto">確認更新</button>
            </form>
        </div>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, reactive, onMounted } from 'vue';
import { fetchWithLiffToken, numberFormat } from '@/utils/api';
import Chart from 'chart.js/auto';
import liff from '@line/liff';

const dashboard = ref({ totalUsd: 0, totalInvestedTwd: 0, pnl: 0, pnlPercent: 0 });
const holdings = ref([]);
const usdTwdRate = ref(32);
const loading = ref(false);

const historyChartCanvas = ref(null);
let historyChart = null;
const historyRange = ref('1y');

const isModalOpen = ref(false);
const isEditBalanceOpen = ref(false);
const currentTab = ref('trade');
const tabs = [{ id: 'fiat', name: '出入金' }, { id: 'trade', name: '交易' }, { id: 'earn', name: '理財' }];

const form = reactive({ type: 'buy', baseCurrency: '', quoteCurrency: 'USDT', price: null, quantity: null, total: null, fee: null, date: new Date().toISOString().substring(0, 10), note: '' });
const editBalanceForm = reactive({ symbol: '', current: 0, newBalance: 0, date: new Date().toISOString().substring(0, 10) });

const submitButtonText = computed(() => {
  if (currentTab.value === 'fiat') return form.type === 'deposit' ? '確認入金' : '確認出金';
  if (currentTab.value === 'trade') return form.type === 'buy' ? '確認買入' : '確認賣出';
  return '新增紀錄';
});

async function fetchCryptoData() {
  loading.value = true;
  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=get_crypto_summary`);
  if (response && response.ok) {
    const result = await response.json();
    if (result.status === 'success') {
      dashboard.value = result.data.dashboard;
      holdings.value = result.data.holdings;
      if (result.data.usdTwdRate) usdTwdRate.value = result.data.usdTwdRate;
    }
  }
  loading.value = false;
}

async function fetchHistory(range = '1y') {
    historyRange.value = range;
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=get_crypto_history&range=${range}`);
    if (response && response.ok) {
        const result = await response.json();
        if (result.status === 'success') {
            renderChart(result.data);
        }
    }
}

function renderChart(chartData) {
    if (historyChart) historyChart.destroy();
    if (!historyChartCanvas.value) return;

    const ctx = historyChartCanvas.value.getContext('2d');
    const gradient = ctx.createLinearGradient(0, 0, 0, 400);
    const primaryColor = '#2A9D8F'; 
    gradient.addColorStop(0, primaryColor + '4D'); 
    gradient.addColorStop(1, primaryColor + '00'); 

    historyChart = new Chart(historyChartCanvas.value, {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [{
                label: '總資產 (USD)',
                data: chartData.data,
                borderColor: primaryColor, 
                backgroundColor: gradient,
                borderWidth: 2,
                fill: true,
                pointRadius: 0, // 🌟 隱藏數據點
                pointHoverRadius: 6,
                tension: 0.4 // 🌟 平滑曲線
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { 
                legend: { display: false },
                tooltip: { callbacks: { label: (ctx) => `市值: $ ${numberFormat(ctx.raw, 2)}` } },
            },
            scales: {
                x: { grid: { display: false }, ticks: { maxTicksLimit: 6 } },
                y: { beginAtZero: false, grid: { color: '#f0f0f0' }, ticks: { callback: (val) => '$' + numberFormat(val, 0) } }
            }
        }
    });
}

function openEditBalanceModal(coin) {
    editBalanceForm.symbol = coin.symbol;
    editBalanceForm.current = coin.balance;
    editBalanceForm.newBalance = coin.balance; 
    editBalanceForm.date = new Date().toISOString().substring(0, 10); 
    isEditBalanceOpen.value = true;
}
function closeEditModal() { isEditBalanceOpen.value = false; }

async function submitBalanceAdjustment() {
    if (!confirm(`確定要更新 ${editBalanceForm.symbol} 的快照嗎？`)) return;
    
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=adjust_crypto_balance`, {
        method: 'POST',
        body: JSON.stringify({ 
            symbol: editBalanceForm.symbol, 
            new_balance: parseFloat(editBalanceForm.newBalance),
            date: editBalanceForm.date // 傳送日期
        })
    });

    if (response && response.ok) {
        const res = await response.json();
        if (res.status === 'success') {
            closeEditModal();
            fetchCryptoData(); 
            fetchHistory(historyRange.value); 
            alert('快照已更新！');
        } else {
            alert('失敗：' + res.message);
        }
    }
}

async function submitTransaction() {
  const payload = { ...form };
  if (currentTab.value === 'fiat') {
    payload.price = form.quantity > 0 ? (form.total / form.quantity) : 0;
    payload.baseCurrency = 'USDT'; payload.quoteCurrency = 'TWD';
  } else if (currentTab.value === 'trade') {
    payload.baseCurrency = form.baseCurrency.toUpperCase(); payload.quoteCurrency = form.quoteCurrency.toUpperCase();
  } else { payload.baseCurrency = form.baseCurrency.toUpperCase(); }

  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=add_crypto_transaction`, { method: 'POST', body: JSON.stringify(payload) });
  if (response && response.ok) {
    const res = await response.json();
    if (res.status === 'success') {
        closeModal(); fetchCryptoData(); fetchHistory(); alert('紀錄成功');
    } else { alert('失敗：' + res.message); }
  } else { alert('網路錯誤'); }
}

function openTransactionModal() {
    // 🟢 新增：檢查登入
    if (!liff.isLoggedIn()) {
        liff.login({ redirectUri: window.location.href });
        return;
    }
    
    resetForm(); 
    isModalOpen.value = true; 
}
function closeModal() { isModalOpen.value = false; }
function switchTab(tabId) { 
    currentTab.value = tabId; resetForm(); 
    if (tabId === 'fiat') { form.type = 'deposit'; form.baseCurrency = 'USDT'; form.quoteCurrency = 'TWD'; }
    else if (tabId === 'trade') { form.type = 'buy'; form.baseCurrency = ''; form.quoteCurrency = 'USDT'; }
    else { form.type = 'earn'; }
}
function resetForm() { form.price = null; form.quantity = null; form.total = null; form.fee = null; form.note = ''; form.date = new Date().toISOString().substring(0, 10); }
function calcTotal() { if (form.price && form.quantity) form.total = parseFloat((form.price * form.quantity).toFixed(4)); }
function calcQuantity() { if (form.total && form.price > 0) form.quantity = parseFloat((form.total / form.price).toFixed(6)); }
function alert(msg) { window.alert(msg); } 

onMounted(() => { fetchCryptoData(); fetchHistory(); });
</script>

<style scoped>
/* 🎨 風格統一 CSS */
:root {
    --text-primary: #5d5d5d;  
    --text-secondary: #8c8c8c;
    --text-accent: #a98467;
    --color-primary: #d4a373; 
    --color-teal: #2A9D8F;    
    --color-danger: #e5989b; 
    --bg-card: #ffffff;
    --shadow-soft: 0 4px 20px rgba(212, 163, 115, 0.15);
}

.crypto-container { padding-bottom: 40px; color: var(--text-primary); font-family: inherit; letter-spacing: 0.03em; }

/* 儀表板 */
.dashboard-header { background: white; margin: 0 0 20px 0; padding: 24px 20px; border-bottom: 1px solid #f0ebe5; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
.subtitle { font-size: 0.85rem; color: #8c7b75; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 1px; }
.main-balance { font-size: 2.2rem; font-weight: 700; color: #333; margin-bottom: 20px; }
.currency-symbol { font-size: 1.2rem; vertical-align: top; color: #888; margin-right: 2px; }
.currency-code { font-size: 0.9rem; color: #aaa; font-weight: 400; margin-left: 4px; }
.stats-row { display: flex; justify-content: space-between; background: #fdfcfb; padding: 12px; border-radius: 12px; border: 1px solid #f0f0f0; }
.vertical-line { width: 1px; background: #eee; margin: 0 10px; }
.stat-item { flex: 1; display: flex; flex-direction: column; align-items: center; }
.stat-item .label { font-size: 0.75rem; color: #999; margin-bottom: 4px; }
.stat-item .value { font-size: 0.95rem; font-weight: 600; color: #555; }
.text-profit { color: #2A9D8F; } .text-loss { color: #e5989b; }

/* 圖表 */
.card-section { margin-bottom: 20px; padding: 0 16px; }
.chart-card { background: white; padding: 16px; border-radius: 16px; border: 1px solid #f0ebe5; box-shadow: var(--shadow-soft); }
.chart-header-row { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-bottom: 15px; }
.chart-header-row h3 { font-size: 1.1rem; font-weight: 600; color: #8c7b75; margin: 0; white-space: nowrap; }
.chart-box-lg { width: 100%; height: 250px; position: relative; }
.date-controls { display: flex; align-items: center; gap: 8px; background: #f7f5f0; padding: 4px 8px; border-radius: 20px; }
.filter-btn-sm { background: none; border: none; padding: 4px 8px; border-radius: 16px; font-size: 0.8rem; color: #a98467; cursor: pointer; transition: all 0.2s; }
.filter-btn-sm.active { background: #2A9D8F; color: white; font-weight: bold; }
.chart-hint-sm { font-size: 0.75rem; color: #aaa; text-align: center; margin-top: 8px; }

/* 列表區塊 */
.list-section { padding: 0 16px; }
.section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.section-header h3 { font-size: 1.1rem; font-weight: 600; color: #8c7b75; margin: 0; }
.add-btn { background-color: #d4a373; color: white; border: none; padding: 8px 16px; border-radius: 20px; font-size: 0.9rem; cursor: pointer; display: flex; align-items: center; gap: 4px; box-shadow: 0 4px 10px rgba(212, 163, 115, 0.3); }
.empty-state { text-align: center; padding: 40px 20px; background: white; border-radius: 16px; border: 1px dashed #ddd; color: #aaa; }

/* 🌟 持倉列表 (復刻帳戶卡片樣式) */
.coin-list { display: flex; flex-direction: column; gap: 12px; }
.account-card-style { background: white; padding: 16px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); display: flex; justify-content: space-between; align-items: center; border: 1px solid #f0ebe5; }
.card-left { flex: 1; padding-right: 10px; }
.acc-name { font-weight: 600; font-size: 1rem; color: #5d5d5d; }
.acc-meta { display: flex; align-items: center; gap: 8px; margin-top: 4px; }
.currency { font-size: 0.75rem; color: #8c8c8c; }
.badge { font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; font-weight: 500; }
.badge-crypto { background: #E6F5F5; color: #2A9D8F; }
.badge-stable { background: #f0f0f0; color: #666; }
.card-right { text-align: right; display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
.acc-balance { font-size: 1rem; font-weight: 700; letter-spacing: 0.5px; }
.text-asset { color: #5d5d5d; } .text-debt { color: #e5989b; } 

/* 按鈕群組 */
.action-buttons { display: flex; gap: 8px; margin-top: 6px; align-items: center;}
.pill-btn { padding: 4px 10px; border-radius: 12px; font-size: 0.8rem; font-weight: 500; white-space: nowrap; border: none; cursor: pointer; }
.pill-btn.update-crypto { background-color: #2A9D8F; color: white; box-shadow: 0 2px 5px rgba(42, 157, 143, 0.3); }
.pill-btn.update-crypto:hover { background-color: #258a7d; }

/* 歷史/刪除按鈕 */
.text-btn { background: transparent; border: none; cursor: pointer; font-size: 0.85rem; padding: 2px 4px; transition: opacity 0.2s; text-decoration: underline; }
.text-btn:hover { opacity: 0.7; }
.text-btn.view-history { color: #8c8c8c; }
.text-btn.delete { color: #e5989b; }

.pnl-text-sm { font-size: 0.75rem; font-weight: 500; white-space: nowrap; }
.text-profit-sm { color: #2A9D8F; } .text-loss-sm { color: #e5989b; }

/* Modal */
.modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; justify-content: center; align-items: flex-end; }
@media (min-width: 600px) { .modal-overlay { align-items: center; } .modal-content { border-radius: 16px; width: 420px; } }
.modal-content { background: white; width: 100%; max-width: 500px; border-radius: 20px 20px 0 0; padding: 24px; max-height: 90vh; overflow-y: auto; box-shadow: 0 -4px 20px rgba(0,0,0,0.1); }
.modal-header { display: flex; justify-content: space-between; margin-bottom: 20px; }
.modal-header h3 { margin: 0; font-size: 1.1rem; color: #555; }
.close-btn { background: none; border: none; font-size: 1.5rem; color: #999; cursor: pointer; }

/* Form & Inputs */
.tabs { display: flex; background: #f2f2f2; padding: 4px; border-radius: 12px; margin-bottom: 20px; }
.tab-btn { flex: 1; border: none; background: transparent; padding: 8px; font-size: 0.9rem; color: #777; cursor: pointer; border-radius: 10px; transition: all 0.2s;}
.tab-btn.active { background: white; color: #333; font-weight: 600; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 0.85rem; color: #888; margin-bottom: 6px; }
.input-std { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 10px; font-size: 1rem; background: #f9f9f9; box-sizing: border-box; transition: border 0.2s; }
.input-std:focus { border-color: #2A9D8F; background: white; outline: none; }
.input-group { display: flex; align-items: center; gap: 8px; }
.separator { color: #aaa; font-weight: bold; }
.uppercase { text-transform: uppercase; }
.radio-group { display: flex; gap: 10px; }
.radio-label { flex: 1; text-align: center; padding: 10px; border: 1px solid #eee; border-radius: 10px; cursor: pointer; font-size: 0.9rem; background: #fafafa; }
.radio-label.active { border-color: #d4a373; color: #d4a373; background: #fff8f0; font-weight: 600; }
.radio-label.buy.active { border-color: #2A9D8F; color: #2A9D8F; background: #e6fcf5; }
.radio-label.sell.active { border-color: #e5989b; color: #c44536; background: #fff5f5; }
.save-btn { width: 100%; padding: 14px; color: white; border: none; border-radius: 12px; font-size: 1rem; font-weight: 600; cursor: pointer; margin-top: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
.save-btn.main-action { background-color: #d4a373; } 
.save-btn.main-action:hover { background-color: #c19263; }
.save-btn.update-crypto { background-color: #2A9D8F; } 
.save-btn.update-crypto:hover { background-color: #258a7d; }
.form-row { display: flex; gap: 12px; } .half { flex: 1; }
.mt-2 { margin-top: 8px; } .mt-4 { margin-top: 16px; }
.hint-text { font-size: 0.85rem; color: #666; margin-bottom: 15px; line-height: 1.5; background: #f9f9f9; padding: 10px; border-radius: 8px; }
.hint { font-size: 0.8rem; color: #999; margin-top: -10px; margin-bottom: 16px; }
</style>