<template>
  <div class="crypto-container">
    
    <div class="crypto-tabs">
      <button :class="{active: view === 'portfolio'}" @click="switchView('portfolio')">現貨資產</button>
      <button :class="{active: view === 'rebalance'}" @click="switchView('rebalance')">再平衡</button>
      <button :class="{active: view === 'futures'}" @click="switchView('futures')">合約戰績</button>
    </div>

    <div v-if="view === 'portfolio'" class="fade-in">
      <div class="dashboard-header">
        <div class="header-content">
          <div class="subtitle">Total Balance (Est.)</div>
          <div class="main-balance">
            <span class="currency-symbol">$</span>
            {{ numberFormat(dashboard.totalUsd, 2) }}
            <span class="currency-code">USD</span>
          </div>
          
          <div class="stats-row three-col">
            <div class="stat-item">
              <span class="label">未實現損益 (Unrealized)</span>
              <span class="value" :class="dashboard.unrealizedPnl >= 0 ? 'text-profit' : 'text-loss'">
                {{ dashboard.unrealizedPnl >= 0 ? '+' : '' }}{{ numberFormat(dashboard.unrealizedPnl, 2) }}
              </span>
            </div>
            <div class="vertical-line"></div>
            <div class="stat-item">
              <span class="label">已實現損益 (Realized)</span>
              <span class="value" :class="dashboard.realizedPnl >= 0 ? 'text-profit' : 'text-loss'">
                {{ dashboard.realizedPnl >= 0 ? '+' : '' }}{{ numberFormat(dashboard.realizedPnl, 2) }}
              </span>
            </div>
            <div class="vertical-line"></div>
            <div class="stat-item">
              <span class="label">未實現 ROI</span>
              <span class="value" :class="dashboard.pnlPercent >= 0 ? 'text-profit' : 'text-loss'">
                {{ numberFormat(dashboard.pnlPercent, 2) }}%
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
        <div v-for="(coin, index) in holdings" :key="index" class="account-card-style">
          <div class="card-left">
            <div class="acc-name">
              {{ coin.symbol }} 
              <span class="account-tag" v-if="coin.type === 'account'">{{ coin.name }}</span>
            </div>
            <div class="acc-meta">
              <span class="badge" :class="coin.symbol === 'USDT' ? 'badge-stable' : 'badge-crypto'">
                {{ coin.symbol === 'USDT' ? '穩定幣' : '投資' }}
              </span>
              <span class="currency" v-if="coin.type === 'trade'">均價: ${{ numberFormat(coin.avgPrice, 2) }}</span>
              <span class="currency" v-else>來自帳戶</span>
            </div>
          </div>
          
          <div class="card-right">
            <div class="acc-balance" :class="coin.valueUsd >= 0 ? 'text-asset' : 'text-debt'">
              $ {{ numberFormat(coin.valueUsd, 2) }}
            </div>
            <div v-if="coin.type === 'trade'" class="pnl-text-sm" :class="coin.pnl >= 0 ? 'text-profit-sm' : 'text-loss-sm'">
              {{ coin.pnl >= 0 ? '+' : '' }}{{ numberFormat(coin.pnl, 2) }}
            </div>
            <div class="action-buttons">
              <button class="pill-btn update-crypto" @click.stop="openEditBalanceModal(coin)">
                  更新快照
              </button>
            </div>
          </div>
        </div>
      </div>
      </div>

      <div class="list-section mt-4">
      <div class="section-header">
        <h3>近期交易紀錄</h3>
      </div>

      <div v-if="recentTransactions.length === 0" class="empty-state">
        <p>尚無交易紀錄</p>
      </div>

      <div v-else class="coin-list">
        <div v-for="tx in recentTransactions" :key="tx.id" class="account-card-style tx-card">
            <div class="card-left">
              <div class="acc-name">
                  {{ tx.base_currency || 'USDT' }}
              </div>
              
              <div class="acc-meta">
                  <span class="badge" :class="getTxBadgeClass(tx.type)">
                    {{ getTxTypeName(tx.type) }}
                  </span>
                  <span class="currency date-text">{{ tx.transaction_date ? tx.transaction_date.substring(0, 10) : '' }}</span>
              </div>
            </div>

            <div class="card-right">
              <div class="acc-balance large-balance" :class="['buy','deposit','earn','adjustment'].includes(tx.type) ? 'text-profit' : 'text-loss'">
                  {{ ['buy','deposit','earn','adjustment'].includes(tx.type) ? '+' : '-' }} 
                  {{ numberFormat(tx.quantity, 4) }}
              </div>
              
              <div class="action-buttons-text">
                  <button class="text-link edit" @click="openEditTxModal(tx)">編輯</button>
                  <button class="text-link delete" @click="deleteTx(tx.id)">刪除</button>
              </div>
            </div>
        </div>
      </div>
    </div>

      <div v-if="isModalOpen" class="modal-overlay" @click.self="closeModal">
        <div class="modal-content">
          <div class="modal-header">
            <h3>{{ isEditingTransaction ? '編輯紀錄' : '新增現貨紀錄' }}</h3>
            <button class="close-btn" @click="closeModal">×</button>
          </div>

          <div class="tabs" v-if="!isEditingTransaction">
            <button v-for="tab in tabs" :key="tab.id" class="tab-btn" :class="{ active: currentTab === tab.id }" @click="switchTab(tab.id)">{{ tab.name }}</button>
          </div>

          <form @submit.prevent="submitTransaction" class="tx-form">
            <div v-if="['deposit', 'withdraw'].includes(form.type)">
              <div class="form-group"><label>動作方向</label><div class="radio-group"><label class="radio-label" :class="{ active: form.type === 'deposit' }"><input type="radio" v-model="form.type" value="deposit"> 入金 (TWD → U)</label><label class="radio-label" :class="{ active: form.type === 'withdraw' }"><input type="radio" v-model="form.type" value="withdraw"> 出金 (U → TWD)</label></div></div>
              <div class="form-row"><div class="form-group half"><label>台幣金額 (TWD)</label><input type="number" step="any" v-model.number="form.total" class="input-std" placeholder="例如 100000" required></div><div class="form-group half"><label>數量 (USDT)</label><input type="number" step="any" v-model.number="form.quantity" class="input-std" placeholder="例如 3150" required></div></div>
            </div>

            <div v-if="['buy', 'sell'].includes(form.type)">
              <div class="form-group"><label>交易對 (Pair)</label><div class="input-group"><input type="text" v-model="form.baseCurrency" class="input-std uppercase" placeholder="BTC" style="flex:2" required><span class="separator">/</span><input type="text" v-model="form.quoteCurrency" class="input-std uppercase" placeholder="USDT" style="flex:1" readonly></div></div>
              <div class="form-group"><label>動作</label><div class="radio-group"><label class="radio-label buy" :class="{ active: form.type === 'buy' }"><input type="radio" v-model="form.type" value="buy"> 買入 (Buy)</label><label class="radio-label sell" :class="{ active: form.type === 'sell' }"><input type="radio" v-model="form.type" value="sell"> 賣出 (Sell)</label></div></div>
              <div class="form-row"><div class="form-group half"><label>成交價格 (Price)</label><input type="number" step="any" v-model.number="form.price" class="input-std" placeholder="單價" @input="calcTotal"></div><div class="form-group half"><label>數量 (Amount)</label><input type="number" step="any" v-model.number="form.quantity" class="input-std" placeholder="數量" @input="calcTotal"></div></div>
              <div class="form-group"><label>總金額 (Total USDT)</label><input type="number" step="any" v-model.number="form.total" class="input-std" placeholder="系統自動計算" @input="calcQuantity"></div>
            </div>

            <div v-if="['earn', 'adjustment'].includes(form.type)">
              <div class="form-group"><label>類型</label><select v-model="form.type" class="input-std"><option value="earn">理財收益 (Earn)</option><option value="adjustment">餘額調整 (Adjustment)</option></select></div>
              <div class="form-group"><label>幣種</label><input type="text" v-model="form.baseCurrency" class="input-std uppercase" placeholder="例如: ETH"></div><div class="form-group"><label>數量</label><input type="number" step="any" v-model.number="form.quantity" class="input-std" placeholder="0.00"></div>
            </div>

            <div class="form-row mt-4"><div class="form-group half"><label>手續費 (Fee)</label><input type="number" step="any" v-model.number="form.fee" class="input-std" placeholder="0"></div><div class="form-group half"><label>日期</label><input type="date" v-model="form.date" class="input-std" required></div></div>
            
            <button type="submit" class="save-btn main-action">{{ isEditingTransaction ? '儲存修改' : submitButtonText }}</button>
          </form>
        </div>
      </div>

    </div>

    <div v-if="view === 'rebalance'" class="rebalance-panel fade-in">
      <div class="card-section">
        <div class="section-header"><h3>現金水位監控</h3></div>
        
        <div class="data-box rebalance-card">
          <div class="progress-bar-container">
             <div class="bar-fill" :style="{width: Math.min(rebalanceData.currentUsdtRatio, 100) + '%'}"></div>
             <div class="target-line" :style="{left: rebalanceData.targetRatio + '%'}">
                <span class="target-label">目標 {{ rebalanceData.targetRatio }}%</span>
             </div>
          </div>
          
          <div class="ratio-text">
             目前現金比例: <span class="highlight">{{ numberFormat(rebalanceData.currentUsdtRatio, 1) }}%</span> 
          </div>
          
          <div class="advice-box" :class="rebalanceData.action">
             <div class="advice-icon">
                {{ rebalanceData.action === 'BUY' ? '🟢' : (rebalanceData.action === 'SELL' ? '🔴' : '⚪') }}
             </div>
             <div class="advice-content">
                <h4>{{ rebalanceData.action === 'BUY' ? '建議買入' : (rebalanceData.action === 'SELL' ? '建議賣出' : '持有觀望') }}</h4>
                <p>{{ rebalanceData.message }}</p>
             </div>
          </div>

          <button class="setting-btn" @click="openTargetModal">⚙️ 設定目標比例</button>
        </div>
      </div>
    </div>

    <div v-if="view === 'futures'" class="futures-panel fade-in">
       <div class="stats-grid">
          <div class="stat-box">
             <span class="label">勝率 (Win Rate)</span>
             <span class="val win-rate">{{ futuresStats.win_rate }}%</span>
          </div>
          <div class="stat-box">
             <span class="label">總損益 (PnL)</span>
             <span class="val" :class="futuresStats.total_pnl > 0 ? 'text-profit' : 'text-loss'">
                ${{ numberFormat(futuresStats.total_pnl, 2) }}
             </span>
          </div>
          <div class="stat-box">
             <span class="label">平均 ROI</span>
             <span class="val" :class="futuresStats.avg_roi > 0 ? 'text-profit' : 'text-loss'">
                {{ numberFormat(futuresStats.avg_roi, 2) }}%
             </span>
          </div>
          <div class="stat-box">
             <span class="label">總交易次數</span>
             <span class="val">{{ futuresStats.total_trades }}</span>
          </div>
       </div>

       <div class="list-section">
          <div class="section-header">
            <h3>近期交易</h3>
            <button class="add-btn" @click="alert('功能開發中，請期待下個版本！')"><span>+</span> 記一筆</button>
          </div>
          <div v-if="!futuresStats.history || futuresStats.history.length === 0" class="empty-state">
             <p>尚無合約交易紀錄</p>
          </div>
          <div v-else class="coin-list">
             <div v-for="trade in futuresStats.history" :key="trade.id" class="account-card-style">
                <div class="card-left">
                   <div class="acc-name">{{ trade.symbol }} <span class="leverage">x{{ trade.leverage }}</span></div>
                   <div class="acc-meta">
                      <span class="badge" :class="trade.side === 'LONG' ? 'badge-long' : 'badge-short'">{{ trade.side }}</span>
                      <span class="currency">{{ trade.close_date ? trade.close_date.substring(5,10) : 'Open' }}</span>
                   </div>
                </div>
                <div class="card-right">
                   <div class="acc-balance" :class="trade.pnl > 0 ? 'text-profit' : 'text-loss'">
                      {{ trade.pnl > 0 ? '+' : '' }}{{ numberFormat(trade.pnl, 2) }}
                   </div>
                   <div class="pnl-text-sm" :class="trade.roi_percent > 0 ? 'text-profit-sm' : 'text-loss-sm'">
                      {{ trade.roi_percent }}%
                   </div>
                </div>
             </div>
          </div>
       </div>
    </div>

    <div v-if="isTargetModalOpen" class="modal-overlay" @click.self="isTargetModalOpen = false">
        <div class="modal-content small-modal">
            <div class="modal-header">
                <h3>設定現金目標比例</h3>
                <button class="close-btn" @click="isTargetModalOpen = false">×</button>
            </div>
            <div class="modal-body">
                <p class="hint-text">請設定您希望保留的 USDT 現金比例 (0% - 100%)。</p>
                <div class="input-with-suffix">
                    <input type="number" v-model.number="tempTargetRatio" class="input-std" min="0" max="100">
                    <span class="suffix">%</span>
                </div>
                <div class="slider-wrapper">
                    <input type="range" v-model.number="tempTargetRatio" min="0" max="100" class="range-slider">
                </div>
                <button class="save-btn main-action" @click="saveTargetRatio" :disabled="saving">
                    {{ saving ? '儲存中...' : '儲存設定' }}
                </button>
            </div>
        </div>
    </div>

    <div v-if="isModalOpen" class="modal-overlay" @click.self="closeModal">
      <div class="modal-content">
        <div class="modal-header">
          <h3>新增現貨紀錄</h3>
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

// 狀態管理
const view = ref('portfolio');
const dashboard = ref({ totalUsd: 0, totalInvestedTwd: 0, unrealizedPnl: 0, realizedPnl: 0, pnlPercent: 0 });
const holdings = ref([]);
const rebalanceData = ref({ currentUsdtRatio: 0, targetRatio: 10, action: 'HOLD', message: '載入中...' });
const futuresStats = ref({ win_rate: 0, total_pnl: 0, avg_roi: 0, total_trades: 0, history: [] });
const usdTwdRate = ref(32);
const loading = ref(false);

const recentTransactions = ref([]); // 近期交易

const historyChartCanvas = ref(null);
let historyChart = null;
const historyRange = ref('1y');

const isModalOpen = ref(false);
const isEditBalanceOpen = ref(false);
const isTargetModalOpen = ref(false);
const currentTab = ref('trade');
const tabs = [{ id: 'fiat', name: '出入金' }, { id: 'trade', name: '交易' }, { id: 'earn', name: '理財' }];

const form = reactive({ type: 'buy', baseCurrency: '', quoteCurrency: 'USDT', price: null, quantity: null, total: null, fee: null, date: new Date().toISOString().substring(0, 10), note: '' });
const editBalanceForm = reactive({ symbol: '', current: 0, newBalance: 0, date: new Date().toISOString().substring(0, 10) });
const tempTargetRatio = ref(10);
const saving = ref(false);
const isEditAccountOpen = ref(false);

const submitButtonText = computed(() => {
  if (currentTab.value === 'fiat') return form.type === 'deposit' ? '確認入金' : '確認出金';
  if (currentTab.value === 'trade') return form.type === 'buy' ? '確認買入' : '確認賣出';
  return '新增紀錄';
});

function switchView(target) {
    view.value = target;
    if (target === 'portfolio') {
        fetchCryptoData();
        fetchRecentTransactions(); // 切換回來時刷新列表
        setTimeout(() => fetchHistory(historyRange.value), 100);
    } else if (target === 'rebalance') {
        fetchRebalance();
    } else if (target === 'futures') {
        fetchFutures();
    }
}

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

// 撈取最近交易
async function fetchRecentTransactions() {
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=get_crypto_transactions&limit=20`);
    if (response && response.ok) {
        const res = await response.json();
        if (res.status === 'success') {
            recentTransactions.value = res.data;
        }
    }
}

async function fetchHistory(range = '1y') {
    historyRange.value = range;
    if (!historyChartCanvas.value) return; 
    
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=get_crypto_history&range=${range}`);
    if (response && response.ok) {
        const result = await response.json();
        if (result.status === 'success') {
            renderChart(result.data);
        }
    }
}

// 🟢 [新增] 輔助函式：取得交易類型名稱
function getTxTypeName(type) {
    const map = {
        'buy': '買入', 'sell': '賣出',
        'deposit': '入金', 'withdraw': '出金',
        'earn': '收益', 'adjustment': '調整'
    };
    return map[type] || type;
}

// 🟢 [新增] 輔助函式：取得標籤樣式 class
function getTxBadgeClass(type) {
    if (['buy', 'deposit', 'earn'].includes(type)) return 'badge-success';
    if (['sell', 'withdraw'].includes(type)) return 'badge-danger';
    return 'badge-neutral';
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
                pointRadius: 3, // 保持點點顯示
                pointHoverRadius: 6, // 滑鼠移上去時點點變大
                pointBackgroundColor: '#ffffff', // 點點中間白色
                pointBorderColor: primaryColor,  // 點點邊框顏色
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            // 🟢 [新增] 互動模式設定：讓滑鼠不用精準指到點也能觸發
            interaction: {
                mode: 'index',   // 只要滑鼠在該 X 軸的區間內就觸發
                intersect: false, // 不需要游標真的碰到點
            },
            plugins: { 
                legend: { display: false },
                // 🟢 [關鍵修改] 關閉原本印在圖上的數字
                datalabels: { 
                    display: false 
                },
                // 🟢 [優化] Tooltip 提示框設定
                tooltip: { 
                    enabled: true,
                    backgroundColor: 'rgba(255, 255, 255, 0.9)', // 背景改白
                    titleColor: '#333', // 標題深色
                    bodyColor: '#2A9D8F', // 數值顏色
                    borderColor: '#ddd',
                    borderWidth: 1,
                    padding: 10,
                    displayColors: false, // 不顯示前面的小色塊
                    callbacks: { 
                        // 設定標題顯示日期
                        title: (tooltipItems) => {
                            return tooltipItems[0].label;
                        },
                        // 設定數值格式 (保留 1 位小數)
                        label: (ctx) => {
                            return `USD $ ${numberFormat(ctx.raw, 1)}`; 
                        } 
                    } 
                },
            },
            scales: {
                x: { 
                    grid: { display: false }, 
                    ticks: { maxTicksLimit: 6 } 
                },
                y: { 
                    beginAtZero: false, 
                    grid: { color: '#f0f0f0' }, 
                    ticks: { callback: (val) => '$' + numberFormat(val, 1) } 
                }
            }
        }
    });
}

async function fetchRebalance() {
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=get_rebalancing_advice`);
    
    if (response && response.ok) {
        const result = await response.json();
        if (result.status === 'success') {
            // 更新再平衡資料
            rebalanceData.value = {
                currentUsdtRatio: parseFloat(result.data.current_usdt_ratio || 0),
                targetRatio: parseFloat(result.data.target_ratio || 10), // 注意：若後端沒回傳值，這裡會變回 10
                action: result.data.action || 'HOLD',
                message: result.data.message || '目前配置平衡。'
            };
        }
    }
}

async function fetchFutures() {
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=get_futures_stats`);
    if (response && response.ok) {
        const result = await response.json();
        if (result.status === 'success') {
            futuresStats.value = result.data;
        }
    }
}

function openTargetModal() {
    tempTargetRatio.value = rebalanceData.value.targetRatio;
    isTargetModalOpen.value = true;
}

// 🟢 [修正] 儲存目標比例後，前端先更新變數 (Optimistic Update)
async function saveTargetRatio() {
    if (tempTargetRatio.value < 0 || tempTargetRatio.value > 100) {
        alert("比例必須在 0 ~ 100 之間");
        return;
    }
    saving.value = true;
    
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=update_crypto_target`, {
        method: 'POST',
        body: JSON.stringify({ ratio: tempTargetRatio.value })
    });

    if (response && response.ok) {
        const res = await response.json();
        if (res.status === 'success') {
            // 🟢 這裡：先直接更新前端顯示，不要等 fetchRebalance
            rebalanceData.value.targetRatio = tempTargetRatio.value; 
            
            isTargetModalOpen.value = false;
            fetchRebalance(); // 背景再去抓最新的 (作為雙重確認)
            alert("設定已更新");
        } else {
            alert(res.message);
        }
    }
    saving.value = false;
}

function openTransactionModal() {
    if (!liff.isLoggedIn()) { liff.login({ redirectUri: window.location.href }); return; }
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

function openEditBalanceModal(coin) {
    editBalanceForm.symbol = coin.symbol;
    editBalanceForm.current = coin.balance;
    editBalanceForm.newBalance = coin.balance; 
    editBalanceForm.date = new Date().toISOString().substring(0, 10); 
    
    // 辨識來源
    editBalanceForm.type = coin.type; 
    editBalanceForm.name = coin.name; // 用於 API 識別

    isEditBalanceOpen.value = true;
}

function closeEditModal() { isEditBalanceOpen.value = false; }

async function submitBalanceAdjustment() {
    // 1. 處理靜態帳戶 (type === 'account')
    if (editBalanceForm.type === 'account') {
        if (!confirm(`確定要更新帳戶 [${editBalanceForm.name}] 的餘額為 ${editBalanceForm.newBalance} 嗎？`)) return;
        
        // 呼叫 save_account API (復用 AccountManagerView 的邏輯)
        const payload = {
            name: editBalanceForm.name,
            balance: editBalanceForm.newBalance,
            type: 'Investment', // 或根據幣種自動判斷
            currency: editBalanceForm.symbol,
            date: editBalanceForm.date,
            ledger_id: props.ledgerId // 確保帶上當前帳本 ID
        };

        const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=save_account`, {
            method: 'POST',
            body: JSON.stringify(payload)
        });

        if (response && response.ok) {
            const res = await response.json();
            if (res.status === 'success') {
                closeEditModal();
                fetchCryptoData(); // 重新整理列表
                alert('帳戶快照已更新！');
            } else { alert('失敗：' + res.message); }
        }
        return;
    }

    // 2. 處理交易推算帳戶 (type === 'trade') - 維持原有補差額邏輯
    if (!confirm(`確定要校正 ${editBalanceForm.symbol} (Trading) 的餘額嗎？系統將自動新增一筆校正交易。`)) return;
    
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=adjust_crypto_balance`, {
        method: 'POST',
        body: JSON.stringify({ 
            symbol: editBalanceForm.symbol, 
            new_balance: parseFloat(editBalanceForm.newBalance),
            date: editBalanceForm.date
        })
    });
    if (response && response.ok) {
        const res = await response.json();
        if (res.status === 'success') {
            closeEditModal();
            fetchCryptoData(); 
            fetchHistory(historyRange.value); 
            fetchRecentTransactions(); 
            alert('快照已更新！');
        } else { alert('失敗：' + res.message); }
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
        closeModal(); fetchCryptoData(); fetchHistory(); 
        fetchRecentTransactions(); // 刷新交易列表
        alert('紀錄成功');
    } else { alert('失敗：' + res.message); }
  } else { alert('網路錯誤'); }
}

onMounted(() => { 
    fetchCryptoData();
    setTimeout(() => fetchHistory(), 100);
    fetchRecentTransactions();
});
</script>

<style scoped>
/* 樣式區 (保持不變) */
:root { --text-primary: #5d5d5d; --color-primary: #d4a373; --color-teal: #2A9D8F; --color-danger: #e5989b; }

.crypto-container {
    max-width: 800px;
    margin: 0 auto;
    padding-bottom: 80px;
    color: var(--text-primary);
}

.fade-in { animation: fadeIn 0.3s ease; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

.modal-overlay {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.6); z-index: 2000;
    display: flex; justify-content: center; align-items: center;
    padding: 20px; backdrop-filter: blur(2px);
}

.modal-content {
    background: white; width: 100%; max-width: 400px;
    border-radius: 20px; padding: 24px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    max-height: 85vh; overflow-y: auto;
    animation: popIn 0.3s cubic-bezier(0.18, 0.89, 0.32, 1.28);
    -ms-overflow-style: none; scrollbar-width: none;
}
.modal-content::-webkit-scrollbar { display: none; }
.modal-content.small-modal { max-width: 320px; }

.input-with-suffix { position: relative; display: flex; align-items: center; margin-bottom: 20px; }
.input-with-suffix .input-std { padding-right: 40px; text-align: center; font-size: 1.5rem; font-weight: bold; color: #2A9D8F; width: 100%; border: 1px solid #ddd; border-radius: 12px; padding: 12px; }
.suffix { position: absolute; right: 20px; color: #888; font-weight: bold; }
.range-slider { width: 100%; margin-bottom: 20px; accent-color: #2A9D8F; height: 6px; cursor: pointer; }

.hint-text { font-size: 0.9rem; color: #666; margin-bottom: 20px; text-align: center; line-height: 1.5; }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.modal-header h3 { margin: 0; font-size: 1.2rem; color: #333; }
.close-btn { background: none; border: none; font-size: 1.8rem; color: #aaa; cursor: pointer; line-height: 1; }

@keyframes popIn { 0% { opacity: 0; transform: scale(0.9); } 100% { opacity: 1; transform: scale(1); } }

.crypto-tabs { display: flex; gap: 8px; padding: 10px 16px; background: #fff; border-bottom: 1px solid #f0f0f0; margin-bottom: 10px; overflow-x: auto; white-space: nowrap; }
.crypto-tabs button { flex: 1; padding: 8px 12px; border-radius: 20px; border: 1px solid #eee; background: #f9f9f9; color: #888; font-weight: 500; font-size: 0.9rem; transition: all 0.2s; cursor: pointer; }
.crypto-tabs button.active { background: #2A9D8F; color: white; border-color: #2A9D8F; box-shadow: 0 2px 6px rgba(42, 157, 143, 0.3); }

.dashboard-header { background: white; margin-bottom: 16px; padding: 20px; border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
.subtitle { font-size: 0.8rem; color: #aaa; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
.main-balance { font-size: 2rem; font-weight: 800; color: #333; margin-bottom: 16px; }
.currency-symbol { font-size: 1.1rem; color: #888; margin-right: 2px; }
.currency-code { font-size: 0.9rem; color: #aaa; font-weight: 400; margin-left: 4px; }

/* 3欄佈局樣式 */
.stats-row.three-col {
    display: flex;
    justify-content: space-between;
    background: #f8f9fa;
    padding: 12px;
    border-radius: 12px;
}
.stats-row { display: flex; background: #f8f9fa; padding: 12px; border-radius: 12px; }
.stat-item { flex: 1; text-align: center; }
.stat-item .label { font-size: 0.75rem; color: #999; display: block; margin-bottom: 2px; }
.stat-item .value { font-size: 0.95rem; font-weight: 600; color: #555; }
.vertical-line { width: 1px; background: #eee; margin: 0 10px; }
.text-profit { color: #2A9D8F; } .text-loss { color: #e5989b; }

.chart-card { background: white; padding: 16px; margin: 0 16px 16px; border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
.chart-header-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.chart-header-row h3 { font-size: 1rem; margin: 0; color: #666; }
.chart-box-lg { width: 100%; height: 220px; position: relative; }
.date-controls button { margin-left: 4px; border: none; background: none; font-size: 0.8rem; color: #999; cursor: pointer; }
.date-controls button.active { color: #2A9D8F; font-weight: bold; }

.list-section { padding: 0 16px; }
.section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.section-header h3 { font-size: 1.1rem; color: #555; margin: 0; }
.add-btn { background: #d4a373; color: white; border: none; padding: 6px 14px; border-radius: 20px; font-size: 0.85rem; font-weight: bold; box-shadow: 0 2px 6px rgba(212, 163, 115, 0.3); cursor: pointer; }

/* 🟢 [修改] 列表卡片樣式優化，統一風格 */
.coin-list { display: flex; flex-direction: column; gap: 12px; }

.account-card-style {
    background: white;
    padding: 16px 20px; /* 增加內距 */
    border-radius: 16px; /* 圓角加大 */
    box-shadow: 0 2px 10px rgba(0,0,0,0.03); /* 柔和陰影 */
    border: 1px solid #f0f0f0; /* 極淡邊框 */
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: transform 0.1s;
}

.card-left { display: flex; flex-direction: column; gap: 6px; }

/* 🟢 [修改] 標題字體加大 */
.acc-name {
    font-size: 1.1rem; 
    font-weight: 700; 
    color: #333;
    letter-spacing: 0.5px;
}

.acc-meta { display: flex; align-items: center; gap: 8px; }

/* 🟢 [修改] 標籤樣式調整 */
.badge { font-size: 0.75rem; padding: 3px 8px; border-radius: 6px; font-weight: 600; }

/* 交易類型標籤配色 */
.badge-success { background-color: #e9edc9; color: #556b2f; } /* 抹茶綠 */
.badge-danger { background-color: #ffedea; color: #c44536; }  /* 淡紅 */
.badge-neutral { background-color: #f3f4f6; color: #6b7280; } /* 灰 */

/* 原有的標籤樣式保留 */
.badge-crypto { background: #e6fcf5; color: #2A9D8F; }
.badge-stable { background: #f0f0f0; color: #666; }
.badge-long { background: #e6fcf5; color: #2A9D8F; }
.badge-short { background: #fff5f5; color: #e5989b; }

.currency { font-size: 0.7rem; color: #aaa; }
.date-text { font-size: 0.85rem; color: #999; letter-spacing: 0.5px; } /* 新增日期樣式 */

.card-right { 
    text-align: right; 
    display: flex; 
    flex-direction: column; 
    align-items: flex-end; 
    gap: 4px; 
}

.acc-balance { font-weight: 700; font-size: 1rem; text-align: right; }

/* 🟢 [新增] 大字號金額樣式 */
.large-balance {
    font-size: 1.2rem;
    font-weight: 800;
    color: #333;
    font-family: 'Helvetica Neue', Arial, sans-serif;
}

.pill-btn { font-size: 0.75rem; padding: 4px 10px; border-radius: 10px; border: none; cursor: pointer; margin-top: 4px; }
.pill-btn.update-crypto { background: #f0f0f0; color: #666; }

/* 🟢 [新增] 文字按鈕區塊 */
.action-buttons-text {
    display: flex;
    gap: 12px; /* 按鈕間距 */
    margin-top: 4px;
}

/* 🟢 [新增] 文字連結按鈕樣式 */
.text-link {
    background: none;
    border: none;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    padding: 0;
    transition: opacity 0.2s;
    color: #888; /* 預設灰色 */
}
.text-link:hover { opacity: 0.7; text-decoration: underline; }
.text-link.delete { color: #e5989b; } /* 刪除用淺紅色 */

.rebalance-card { background: white; padding: 20px; border-radius: 16px; margin: 0 16px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); text-align: center; }
.progress-bar-container { position: relative; height: 16px; background: #eee; border-radius: 10px; margin: 20px 0; overflow: visible; }
.bar-fill { height: 100%; background: linear-gradient(90deg, #4facfe 0%, #00f2fe 100%); border-radius: 10px; transition: width 0.5s ease; }
.target-line { position: absolute; top: -5px; bottom: -5px; width: 2px; background: #333; z-index: 2; }
.target-label { position: absolute; top: -20px; left: 50%; transform: translateX(-50%); font-size: 0.7rem; color: #333; white-space: nowrap; font-weight: bold; }
.ratio-text { font-size: 1rem; color: #555; margin-bottom: 20px; }
.highlight { font-weight: bold; color: #0077b6; font-size: 1.2rem; }
.advice-box { display: flex; align-items: flex-start; text-align: left; background: #f9f9f9; padding: 15px; border-radius: 12px; border-left: 4px solid #ccc; margin-bottom: 20px; }
.advice-box.BUY { border-left-color: #2A9D8F; background: #f0fdf9; }
.advice-box.SELL { border-left-color: #e5989b; background: #fff5f5; }
.advice-icon { font-size: 1.5rem; margin-right: 12px; }
.advice-content h4 { margin: 0 0 4px 0; font-size: 1rem; color: #333; }
.advice-content p { margin: 0; font-size: 0.9rem; color: #666; line-height: 1.4; }
.setting-btn { background: #f0f0f0; border: none; padding: 10px 20px; border-radius: 30px; color: #555; font-size: 0.9rem; cursor: pointer; transition: background 0.2s; margin-top: 10px; width: 100%; font-weight: 500;}
.setting-btn:hover { background: #e0e0e0; }

.stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding: 0 16px; margin-bottom: 20px; }
.stat-box { background: white; padding: 15px; border-radius: 12px; text-align: center; box-shadow: 0 2px 6px rgba(0,0,0,0.03); }
.stat-box .label { display: block; font-size: 0.75rem; color: #999; margin-bottom: 4px; }
.stat-box .val { font-size: 1.1rem; font-weight: 700; color: #555; }
.stat-box .win-rate { color: #d4a373; font-size: 1.3rem; }
.pnl-text-sm { font-size: 0.75rem; font-weight: 500; margin-top: 2px; }
.text-profit-sm { color: #2A9D8F; } .text-loss-sm { color: #e5989b; }
.leverage { font-size: 0.7rem; background: #eee; padding: 1px 4px; border-radius: 4px; color: #666; margin-left: 4px; }

.tabs { display: flex; background: #f2f2f2; padding: 4px; border-radius: 12px; margin-bottom: 20px; }
.tab-btn { flex: 1; border: none; background: transparent; padding: 8px; font-size: 0.9rem; color: #777; cursor: pointer; border-radius: 10px; }
.tab-btn.active { background: white; color: #333; font-weight: 600; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 0.85rem; color: #888; margin-bottom: 6px; }
.input-std { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 10px; font-size: 1rem; background: #f9f9f9; box-sizing: border-box; }
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
.save-btn.update-crypto { background-color: #2A9D8F; } 
.save-btn:disabled { opacity: 0.6; }
.form-row { display: flex; gap: 12px; } .half { flex: 1; }
.mt-2 { margin-top: 8px; } .mt-4 { margin-top: 16px; }
.account-tag { font-size: 0.75rem; background-color: #f0f0f0; color: #666; padding: 2px 6px; border-radius: 4px; margin-left: 6px; font-weight: normal; }
</style>