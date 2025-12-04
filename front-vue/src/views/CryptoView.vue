<template>
  <div class="crypto-container">
    
    <div class="dashboard-header">
      <div class="header-top">
        <h2>加密資產看板</h2>
        <div class="currency-toggle" @click="toggleCurrency">
          <span :class="{ active: displayCurrency === 'TWD' }">TWD</span>
          <span :class="{ active: displayCurrency === 'USD' }">USD</span>
        </div>
      </div>
      
      <div class="summary-card" :class="totalPnL >= 0 ? 'bg-profit' : 'bg-loss'">
        <div class="label">總估值 ({{ displayCurrency }})</div>
        <div class="main-val">{{ currencySign }} {{ numberFormat(currentTotalVal, 0) }}</div>
        
        <div class="pnl-row">
          <div class="pnl-item">
            <span class="sub-label">總成本</span>
            <span class="sub-val">{{ currencySign }} {{ numberFormat(currentTotalCost, 0) }}</span>
          </div>
          <div class="vertical-divider"></div>
          <div class="pnl-item">
            <span class="sub-label">未實現損益</span>
            <span class="sub-val" :class="totalPnL >= 0 ? 'text-up' : 'text-down'">
              {{ totalPnL >= 0 ? '+' : '' }}{{ numberFormat(totalPnL, 0) }} 
              <small>({{ numberFormat(totalRoi, 2) }}%)</small>
            </span>
          </div>
        </div>
      </div>
    </div>

    <div class="list-section">
      <div class="section-title">
        <h3>持倉績效</h3>
        <button class="add-btn" @click="openModal()">+ 記帳</button>
      </div>

      <div v-for="coin in cryptoList" :key="coin.currency" class="coin-card" @click="openModal(coin)">
        <div class="card-left">
          <div class="coin-name">
            <span class="coin-icon" :class="coin.currency.toLowerCase()">{{ coin.currency.substring(0,1) }}</span>
            {{ coin.currency }}
          </div>
          <div class="coin-balance">持有: {{ numberFormat(coin.balance, 4) }}</div>
        </div>
        
        <div class="card-right">
          <div class="curr-val">{{ currencySign }} {{ numberFormat(getDisplayValue(coin), 0) }}</div>
          <div class="pnl-tag" :class="coin.roi >= 0 ? 'tag-up' : 'tag-down'">
            {{ coin.roi >= 0 ? '▲' : '▼' }} {{ numberFormat(Math.abs(coin.roi), 2) }}%
          </div>
        </div>
      </div>
    </div>

    <div v-if="isModalOpen" class="modal-overlay" @click.self="closeModal">
      <div class="modal-content">
        <h3>{{ isEdit ? '更新持倉' : '新增資產' }}</h3>
        <form @submit.prevent="saveAsset">
          <div class="form-group">
            <label>幣種 (例如 BTC)</label>
            <input v-model="form.currency" class="input-std" :disabled="isEdit" @input="form.currency = form.currency.toUpperCase()">
          </div>
          <div class="form-group">
            <label>持有數量 (Balance)</label>
            <input type="number" v-model="form.balance" step="any" class="input-std" required>
          </div>
          <div class="form-group">
            <label>總投入成本 (TWD)</label>
            <input type="number" v-model="form.cost" step="0.01" class="input-std" placeholder="你總共花了多少台幣買這些幣？">
            <p class="hint">輸入 0 代表不計算成本 (例如空投)</p>
          </div>
          <button type="submit" class="save-btn">儲存</button>
        </form>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { fetchWithLiffToken, numberFormat } from '@/utils/api';

const displayCurrency = ref('TWD'); // 'TWD' or 'USD'
const loading = ref(false);
const accounts = ref([]); // 原始帳戶資料
const usdTwdRate = ref(32); // 匯率

// 狀態與 Modal
const isModalOpen = ref(false);
const isEdit = ref(false);
const form = ref({ currency: '', balance: 0, cost: 0, name: 'CryptoWallet' });

// 1. 計算屬性：轉換原始資料為績效列表
const cryptoList = computed(() => {
  // 過濾出 Crypto (這裡假設非 TWD/USD 等法幣即為 Crypto，或根據 API 回傳的類型)
  const fiats = ['TWD', 'USD', 'JPY', 'EUR'];
  
  return accounts.value.filter(acc => !fiats.includes(acc.currency_unit)).map(acc => {
    // 這裡需要後端 API 支援回傳 cost_basis，若無則預設 0
    const cost = parseFloat(acc.cost_basis || 0); 
    const balance = parseFloat(acc.balance);
    
    // 假設我們有從 asset_summary 拿到現價 (此處簡化，實際建議由後端算好單價傳過來)
    // 這裡先模擬： Value (TWD) / Balance = 單價
    // 實際上您應該修改 getAccounts API 回傳 TWD 估值，或者在前端呼叫 CoinGecko
    const currentValTwd = acc.estimated_twd_value || (balance * 0); // 需後端配合
    
    const pnl = currentValTwd - cost;
    const roi = cost > 0 ? (pnl / cost) * 100 : 0;

    return {
        ...acc,
        currency: acc.currency_unit,
        balance: balance,
        costTwd: cost,
        valTwd: currentValTwd,
        pnlTwd: pnl,
        roi: roi
    };
  });
});

// 2. 切換匯率顯示邏輯
const currencySign = computed(() => displayCurrency.value === 'TWD' ? 'NT$' : '$');
const toggleCurrency = () => { displayCurrency.value = displayCurrency.value === 'TWD' ? 'USD' : 'TWD'; }

// 3. 計算總值
const currentTotalVal = computed(() => {
    // 實作加總邏輯，並根據匯率轉換
    return 0; // 範例
});
// ... 類似邏輯計算 cost, pnl ...

// ... API 呼叫 (saveAsset, fetchAccounts) ...
</script>

<style scoped>
/* 樣式重點：
   1. 雙本位切換按鈕要顯眼
   2. 盈虧顏色要直觀 (綠漲紅跌)
   3. 成本輸入框要有提示
*/
.bg-profit { background: linear-gradient(135deg, #134e5e 0%, #71b280 100%); color: white; }
.bg-loss { background: linear-gradient(135deg, #4b1212 0%, #9e2a2a 100%); color: white; }
.text-up { color: #76ff03; }
.text-down { color: #ff8a80; }
/* ... 其他 CSS 沿用您現有的文青風 ... */
</style>