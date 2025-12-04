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
            <span class="label">總投入本金 (TWD)</span>
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

    <div class="list-section">
      <div class="section-header">
        <h3>持倉資產</h3>
        <button class="add-btn" @click="openTransactionModal()">
          <span>+</span> 記一筆
        </button>
      </div>

      <div v-if="holdings.length === 0" class="empty-state">
        <p>尚未有交易紀錄</p>
        <p class="sub-text">點擊右上方按鈕開始記錄您的第一筆入金或交易。</p>
      </div>

      <div v-else class="coin-list">
        <div v-for="coin in holdings" :key="coin.symbol" class="coin-card">
          <div class="card-top">
            <div class="coin-left">
              <div class="coin-icon">{{ coin.symbol.substring(0,1) }}</div>
              <div class="coin-name">
                <span class="symbol">{{ coin.symbol }}</span>
                <span class="amount">{{ numberFormat(coin.balance, 4) }}</span>
              </div>
            </div>
            <div class="coin-right">
              <div class="coin-value">$ {{ numberFormat(coin.valueUsd, 2) }}</div>
              <div class="coin-pnl" :class="coin.pnl >= 0 ? 'text-profit' : 'text-loss'">
                {{ coin.pnl >= 0 ? '+' : '' }}{{ numberFormat(coin.pnlPercent, 2) }}%
              </div>
            </div>
          </div>
          <div class="card-bottom">
            <div class="detail-item">
              <span class="label">均價</span>
              <span class="val">$ {{ numberFormat(coin.avgPrice, 2) }}</span>
            </div>
            <div class="detail-item">
              <span class="label">現價</span>
              <span class="val">$ {{ numberFormat(coin.currentPrice, 2) }}</span>
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
          <button 
            v-for="tab in tabs" 
            :key="tab.id" 
            class="tab-btn" 
            :class="{ active: currentTab === tab.id }"
            @click="switchTab(tab.id)"
          >
            {{ tab.name }}
          </button>
        </div>

        <form @submit.prevent="submitTransaction" class="tx-form">
          
          <div v-if="currentTab === 'fiat'">
            <div class="form-group">
              <label>動作方向</label>
              <div class="radio-group">
                <label class="radio-label" :class="{ active: form.type === 'deposit' }">
                  <input type="radio" v-model="form.type" value="deposit"> 入金 (TWD → U)
                </label>
                <label class="radio-label" :class="{ active: form.type === 'withdraw' }">
                  <input type="radio" v-model="form.type" value="withdraw"> 出金 (U → TWD)
                </label>
              </div>
            </div>
            
            <div class="form-row">
              <div class="form-group half">
                <label>台幣金額 (TWD)</label>
                <input type="number" step="any" v-model.number="form.total" class="input-std" placeholder="例如 100000" @input="calcFiatRate">
              </div>
              <div class="form-group half">
                <label>收到/轉出 (USDT)</label>
                <input type="number" step="any" v-model.number="form.quantity" class="input-std" placeholder="例如 3150" @input="calcFiatRate">
              </div>
            </div>
            
            <div class="info-box" v-if="form.total && form.quantity">
              <span class="label">換算匯率:</span>
              <span class="value">1 USDT ≈ <strong>{{ fiatRateDisplay }}</strong> TWD</span>
            </div>
          </div>

          <div v-if="currentTab === 'trade'">
            <div class="form-group">
              <label>交易對 (Pair)</label>
              <div class="input-group">
                <input type="text" v-model="form.baseCurrency" class="input-std uppercase" placeholder="BTC" style="flex:2">
                <span class="separator">/</span>
                <input type="text" v-model="form.quoteCurrency" class="input-std uppercase" placeholder="USDT" style="flex:1" readonly>
              </div>
            </div>

            <div class="form-group">
              <label>動作</label>
              <div class="radio-group">
                <label class="radio-label buy" :class="{ active: form.type === 'buy' }">
                  <input type="radio" v-model="form.type" value="buy"> 買入 (Buy)
                </label>
                <label class="radio-label sell" :class="{ active: form.type === 'sell' }">
                  <input type="radio" v-model="form.type" value="sell"> 賣出 (Sell)
                </label>
              </div>
            </div>

            <div class="form-row">
              <div class="form-group half">
                <label>成交價格 (Price)</label>
                <input type="number" step="any" v-model.number="form.price" class="input-std" placeholder="單價" @input="calcTotal">
              </div>
              <div class="form-group half">
                <label>數量 (Amount)</label>
                <input type="number" step="any" v-model.number="form.quantity" class="input-std" placeholder="數量" @input="calcTotal">
              </div>
            </div>

            <div class="form-group">
              <label>總金額 (Total USDT)</label>
              <input type="number" step="any" v-model.number="form.total" class="input-std" placeholder="系統自動計算" @input="calcQuantity">
            </div>
          </div>

          <div v-if="currentTab === 'earn'">
            <div class="form-group">
              <label>幣種</label>
              <input type="text" v-model="form.baseCurrency" class="input-std uppercase" placeholder="例如: ETH (Staking)">
            </div>
            <div class="form-group">
              <label>獲得數量</label>
              <input type="number" step="any" v-model.number="form.quantity" class="input-std" placeholder="0.00">
            </div>
            <p class="hint">理財收益或空投的成本將視為 0，這會降低您的持倉均價。</p>
          </div>

          <div class="form-row mt-4">
            <div class="form-group half">
              <label>手續費 (Fee)</label>
              <input type="number" step="any" v-model.number="form.fee" class="input-std" placeholder="0">
            </div>
            <div class="form-group half">
              <label>日期</label>
              <input type="date" v-model="form.date" class="input-std">
            </div>
          </div>

          <div class="form-group">
            <label>備註</label>
            <input type="text" v-model="form.note" class="input-std" placeholder="例如: 幣安 DCA, Max 入金">
          </div>

          <button type="submit" class="save-btn" :class="currentTab">
            {{ submitButtonText }}
          </button>

        </form>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, reactive, onMounted } from 'vue';
import { fetchWithLiffToken, numberFormat } from '@/utils/api';

// --- 資料狀態 ---
const dashboard = ref({
  totalUsd: 0,
  totalInvestedTwd: 0,
  pnl: 0,
  pnlPercent: 0
});

const holdings = ref([]);
const usdTwdRate = ref(32);
const loading = ref(false);

// --- UI 狀態 ---
const isModalOpen = ref(false);
const currentTab = ref('trade'); // trade, fiat, earn
const tabs = [
  { id: 'fiat', name: '出入金 (TWD)' },
  { id: 'trade', name: '交易 (Trade)' },
  { id: 'earn', name: '理財 (Earn)' }
];

// --- 表單資料 ---
const form = reactive({
  type: 'buy', 
  baseCurrency: '',
  quoteCurrency: 'USDT',
  price: null,
  quantity: null,
  total: null,
  fee: null,
  date: new Date().toISOString().substring(0, 10),
  note: ''
});

// --- 計算屬性 ---
const fiatRateDisplay = computed(() => {
  if (form.total && form.quantity && form.quantity > 0) {
    return (form.total / form.quantity).toFixed(2);
  }
  return '0.00';
});

const submitButtonText = computed(() => {
  if (currentTab.value === 'fiat') return form.type === 'deposit' ? '確認入金' : '確認出金';
  if (currentTab.value === 'trade') return form.type === 'buy' ? '確認買入' : '確認賣出';
  return '新增紀錄';
});

// --- API 串接 ---

// 1. 讀取數據
async function fetchCryptoData() {
  loading.value = true;
  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=get_crypto_summary`);
  
  if (response && response.ok) {
    const result = await response.json();
    if (result.status === 'success') {
      dashboard.value = result.data.dashboard;
      holdings.value = result.data.holdings;
      if (result.data.usdTwdRate) {
        usdTwdRate.value = result.data.usdTwdRate;
      }
    }
  }
  loading.value = false;
}

// 2. 送出交易
async function submitTransaction() {
  const payload = { ...form };
  
  if (currentTab.value === 'fiat') {
    // 出入金: 價格 = 匯率
    payload.price = form.quantity > 0 ? (form.total / form.quantity) : 0;
    payload.baseCurrency = 'USDT';
    payload.quoteCurrency = 'TWD';
  } else if (currentTab.value === 'trade') {
    payload.baseCurrency = form.baseCurrency.toUpperCase();
    payload.quoteCurrency = form.quoteCurrency.toUpperCase();
  } else {
    payload.baseCurrency = form.baseCurrency.toUpperCase();
  }

  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=add_crypto_transaction`, {
    method: 'POST',
    body: JSON.stringify(payload)
  });

  if (response && response.ok) {
    const result = await response.json();
    if (result.status === 'success') {
      closeModal();
      fetchCryptoData();
      alert('✅ 紀錄成功！');
    } else {
      alert('❌ 失敗：' + result.message);
    }
  } else {
    alert('網路錯誤，請稍後再試');
  }
}

// --- 輔助邏輯 ---
function openTransactionModal() { resetForm(); isModalOpen.value = true; }
function closeModal() { isModalOpen.value = false; }

function switchTab(tabId) {
  currentTab.value = tabId;
  resetForm();
  if (tabId === 'fiat') {
    form.type = 'deposit';
    form.baseCurrency = 'USDT';
    form.quoteCurrency = 'TWD';
  } else if (tabId === 'trade') {
    form.type = 'buy';
    form.baseCurrency = '';
    form.quoteCurrency = 'USDT';
  } else if (tabId === 'earn') {
    form.type = 'earn';
  }
}

function resetForm() {
  form.price = null; form.quantity = null; form.total = null; form.fee = null; form.note = '';
  form.date = new Date().toISOString().substring(0, 10);
}

function calcTotal() {
  if (form.price && form.quantity) {
    form.total = parseFloat((form.price * form.quantity).toFixed(4));
  }
}
function calcQuantity() {
  if (form.total && form.price && form.price > 0) {
    form.quantity = parseFloat((form.total / form.price).toFixed(6));
  }
}
function calcFiatRate() {}

onMounted(() => {
  fetchCryptoData();
});
</script>

<style scoped>
/* (樣式保持不變，直接沿用即可) */
.crypto-container { padding-bottom: 40px; color: #5d5d5d; }

.dashboard-header {
  background: white;
  margin: 0 0 20px 0;
  padding: 24px 20px;
  border-bottom: 1px solid #f0ebe5;
}
.subtitle { font-size: 0.85rem; color: #8c7b75; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 1px; }
.main-balance { font-size: 2.2rem; font-weight: 700; color: #333; margin-bottom: 20px; }
.currency-symbol { font-size: 1.2rem; vertical-align: top; color: #888; margin-right: 2px; }
.currency-code { font-size: 0.9rem; color: #aaa; font-weight: 400; margin-left: 4px; }

.stats-row { display: flex; justify-content: space-between; background: #fdfcfb; padding: 12px; border-radius: 12px; border: 1px solid #f0f0f0; }
.stat-item { flex: 1; display: flex; flex-direction: column; align-items: center; }
.vertical-line { width: 1px; background: #eee; margin: 0 10px; }
.stat-item .label { font-size: 0.75rem; color: #999; margin-bottom: 4px; }
.stat-item .value { font-size: 0.95rem; font-weight: 600; color: #555; }

.list-section { padding: 0 16px; }
.section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.section-header h3 { margin: 0; color: #8c7b75; font-size: 1.1rem; }
.add-btn { background-color: #d4a373; color: white; border: none; padding: 6px 14px; border-radius: 20px; font-size: 0.9rem; cursor: pointer; display: flex; align-items: center; gap: 4px; box-shadow: 0 2px 5px rgba(212, 163, 115, 0.3); }

.empty-state { text-align: center; padding: 40px 20px; background: white; border-radius: 16px; border: 1px dashed #ddd; color: #aaa; }
.sub-text { font-size: 0.8rem; margin-top: 8px; }

.coin-list { display: flex; flex-direction: column; gap: 12px; }
.coin-card { background: white; border-radius: 16px; padding: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); border: 1px solid #f0ebe5; }
.card-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.coin-left { display: flex; align-items: center; gap: 10px; }
.coin-icon { width: 36px; height: 36px; background: #f5f5f5; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #555; }
.coin-name { display: flex; flex-direction: column; }
.coin-name .symbol { font-weight: 700; font-size: 1rem; color: #333; }
.coin-name .amount { font-size: 0.8rem; color: #999; }
.coin-right { text-align: right; }
.coin-value { font-weight: 600; font-size: 1rem; }
.coin-pnl { font-size: 0.8rem; font-weight: 500; margin-top: 2px; }

.card-bottom { display: flex; justify-content: space-between; border-top: 1px dashed #eee; padding-top: 10px; }
.detail-item { font-size: 0.8rem; color: #888; }
.detail-item .val { color: #555; margin-left: 4px; font-weight: 500; }

.modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; display: flex; justify-content: center; align-items: flex-end; }
.modal-content { background: white; width: 100%; max-width: 500px; border-radius: 20px 20px 0 0; padding: 24px; animation: slideUp 0.3s ease-out; max-height: 90vh; overflow-y: auto; }
@media (min-width: 600px) { .modal-overlay { align-items: center; } .modal-content { border-radius: 16px; width: 420px; } }

.modal-header { display: flex; justify-content: space-between; margin-bottom: 20px; }
.modal-header h3 { margin: 0; color: #333; }
.close-btn { background: none; border: none; font-size: 1.5rem; color: #999; cursor: pointer; }

.tabs { display: flex; background: #f2f2f2; padding: 4px; border-radius: 12px; margin-bottom: 20px; }
.tab-btn { flex: 1; border: none; background: transparent; padding: 8px; font-size: 0.9rem; color: #777; cursor: pointer; border-radius: 10px; transition: all 0.2s; }
.tab-btn.active { background: white; color: #333; font-weight: 600; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }

.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 0.85rem; color: #888; margin-bottom: 6px; }
.form-row { display: flex; gap: 12px; }
.half { flex: 1; width: 50%; }
.input-std { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 10px; font-size: 1rem; box-sizing: border-box; background: #f9f9f9; transition: all 0.2s; }
.input-std:focus { border-color: #d4a373; background: white; outline: none; }
.uppercase { text-transform: uppercase; }

.input-group { display: flex; align-items: center; gap: 8px; }
.separator { color: #aaa; font-weight: bold; }

.radio-group { display: flex; gap: 10px; }
.radio-label { flex: 1; text-align: center; padding: 10px; border: 1px solid #eee; border-radius: 10px; cursor: pointer; font-size: 0.9rem; color: #666; transition: all 0.2s; background: #fafafa; }
.radio-label input { display: none; }
.radio-label.active { border-color: #d4a373; color: #d4a373; background: #fff8f0; font-weight: 600; }
.radio-label.buy.active { border-color: #2A9D8F; color: #2A9D8F; background: #e6fcf5; }
.radio-label.sell.active { border-color: #e5989b; color: #c44536; background: #fff5f5; }

.info-box { background: #f0f7ff; padding: 10px; border-radius: 8px; font-size: 0.85rem; color: #336699; display: flex; justify-content: space-between; margin-top: -8px; margin-bottom: 16px; }
.hint { font-size: 0.8rem; color: #999; margin-top: -10px; margin-bottom: 16px; }
.mt-4 { margin-top: 16px; }

.save-btn { width: 100%; padding: 14px; background: #d4a373; color: white; border: none; border-radius: 12px; font-size: 1rem; font-weight: 600; cursor: pointer; margin-top: 10px; }
.save-btn.fiat { background: #333; }
.save-btn.trade { background: #2A9D8F; }

.text-profit { color: #2A9D8F; }
.text-loss { color: #e5989b; }

@keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }
</style>