<template>
  <div class="dashboard-container">
    
    <div class="card-section">
      <div class="section-header">
        <h2>🏠 陪跑模擬器</h2>
      </div>
      <div class="data-box intro-card">
        <p class="intro-text">
          這是一個基於<strong>「3.5% 法則」</strong>的財務決策工具。
          請先確認上方的財務現狀，再調整下方的參數，系統將自動計算 40 年後的資產差距。
        </p>
      </div>
    </div>

    <div class="card-section">
      <div class="section-header"><h2>1. 您的財務現狀</h2></div>
      <div class="data-box status-bar">
        <div class="status-item">
          <label>可動用資金 (潛在頭期款)</label>
          <div class="value highlight">
            {{ formatCurrency(userData.liquidAssets) }}
          </div>
          <div class="hint-text" v-if="userData.liquidAssets > 0">
            約可買 <strong>{{ formatCurrency(userData.liquidAssets * 5) }}</strong> 的房
          </div>
        </div>
        
        <div class="divider-vertical"></div>

        <div class="status-item">
          <label>每月平均結餘</label>
          <div class="value" :class="userData.avgSavings > 0 ? 'text-income' : 'text-expense'">
            {{ formatCurrency(userData.avgSavings) }}
          </div>
          <div class="hint-text">儲蓄能力</div>
        </div>

        <div class="divider-vertical"></div>

        <div class="status-item">
          <label>每月平均收入</label>
          <div class="value">{{ formatCurrency(userData.avgIncome) }}</div>
          <div class="hint-text">收入水準</div>
        </div>
      </div>
    </div>

    <div class="two-col-grid">
      
      <div class="card-section">
        <div class="section-header"><h2>2. 買房參數 (新青安)</h2></div>
        <div class="data-box form-box">
          
          <div class="form-group">
            <label>房屋總價 (萬)</label>
            <input type="number" v-model.number="params.housePrice" class="input-std highlight-input" placeholder="例如: 2000">
          </div>

          <div class="form-row">
            <div class="form-group half">
              <label>貸款成數 (%)</label>
              <input type="number" v-model.number="params.loanRatio" class="input-std">
            </div>
            <div class="form-group half">
              <label>年限 (年)</label>
              <input type="number" v-model.number="params.loanYears" class="input-std">
            </div>
          </div>

          <div class="form-row">
            <div class="form-group half">
              <label>寬限期 (年)</label>
              <input type="number" v-model.number="params.gracePeriod" class="input-std">
            </div>
            <div class="form-group half">
              <label>利率 (%)</label>
              <input type="number" v-model.number="params.interestRate" class="input-std" step="0.01">
            </div>
          </div>

          <div class="cost-details-box">
             <div class="detail-title">購屋成本與還款試算</div>
             
             <div class="detail-row">
                <span class="d-label">頭期款 (自備款)</span>
                <span class="d-value">{{ formatCurrency(calculated.downPayment) }}</span>
             </div>
             <div class="detail-row">
                <span class="d-label">裝修與雜支 ({{ params.initialCostsRate }}%)</span>
                <span class="d-value">{{ formatCurrency(calculated.initialMisc) }}</span>
             </div>
             <div class="detail-row sub-total">
                <span class="d-label">🔥 總初始資金</span>
                <span class="d-value">{{ formatCurrency(calculated.totalInitialBuyCost) }}</span>
             </div>
             
             <div class="separator-dashed"></div>
             
             <div class="detail-row" v-if="params.gracePeriod > 0">
                <span class="d-label">前 {{ params.gracePeriod }} 年月繳 (寬限期)</span>
                <span class="d-value highlight-value">{{ formatCurrency(calculated.gracePayment) }}</span>
             </div>
             <div class="detail-row">
                <span class="d-label">{{ params.gracePeriod > 0 ? '寬限後' : '' }}月繳 (本金+利息)</span>
                <span class="d-value highlight-value">{{ formatCurrency(calculated.fullPayment) }}</span>
             </div>
             
             <div class="detail-row">
                <span class="d-label">隱性月持有成本 (稅/維護)</span>
                <span class="d-value text-expense">+ {{ formatCurrency(calculated.monthlyHolding) }}</span>
             </div>
          </div>

        </div>
      </div>

      <div class="card-section">
        <div class="section-header"><h2>3. 租房與市場假設</h2></div>
        <div class="data-box form-box">
          
          <div class="form-group">
            <label>月租金 (元)</label>
            <input type="number" v-model.number="params.monthlyRent" class="input-std highlight-input">
          </div>

          <div class="form-group">
            <div class="label-row">
                <label>初始投資資金 (元)</label>
                <div class="sync-check">
                    <input type="checkbox" id="syncCap" v-model="params.autoSyncCapital">
                    <label for="syncCap">比照買房支出</label>
                </div>
            </div>
            <input 
                type="number" 
                v-model.number="params.rentInitialCapital" 
                class="input-std" 
                :disabled="params.autoSyncCapital"
                :class="{ 'disabled-input': params.autoSyncCapital }"
            >
            <p class="field-hint" v-if="params.autoSyncCapital">
                已自動設為「頭期款 + 雜支」，確保比較基準一致。
            </p>
          </div>

          <div class="form-row">
            <div class="form-group half">
              <label>股市年報酬 (%)</label>
              <input type="number" v-model.number="params.stockReturnRate" class="input-std" step="0.1">
            </div>
            <div class="form-group half">
              <label>房價年漲幅 (%)</label>
              <input type="number" v-model.number="params.houseAppreciation" class="input-std" step="0.1">
            </div>
          </div>

          <div class="cost-details-box invest-box">
             <div class="detail-title">投資試算 (機會成本)</div>
             <div class="detail-row">
                <span class="d-label">買房月支出 (寬限後+持有)</span>
                <span class="d-value text-gray-500">{{ formatCurrency(monthlyMortgage) }}</span>
             </div>
             <div class="detail-row">
                <span class="d-label">扣除房租成本</span>
                <span class="d-value">- {{ formatCurrency(params.monthlyRent) }}</span>
             </div>
             <div class="separator-dashed"></div>
             
             <div class="detail-row sub-total">
                <span class="d-label">🔥 每月投入股市</span>
                <span class="d-value highlight-value" :class="calculated.monthlyInvest > 0 ? 'text-income' : 'text-expense'">
                    {{ formatCurrency(calculated.monthlyInvest) }}
                </span>
             </div>

             <p class="field-hint text-income" v-if="calculated.monthlyInvest > 0">
                 租房較省！請將此差額紀律性投入 <strong>{{ params.stockReturnRate }}%</strong> 的標的。
             </p>
             <p class="field-hint text-expense" v-else>
                 ⚠️ 房租比買房還貴！每月需從本金扣除 {{ formatCurrency(Math.abs(calculated.monthlyInvest)) }} 才能維持生活。
             </p>
          </div>

        </div>
      </div>

    </div>

    <div class="two-col-grid results-grid">
      
      <div class="card-section">
        <div class="section-header"><h2>4. 40年資產模擬圖表</h2></div>
        <div class="data-box chart-card">
          <div class="chart-container">
             <Line :data="chartData" :options="chartOptions" />
          </div>
        </div>
      </div>

      <div class="card-section">
        <div class="section-header"><h2>5. AI 財務建議</h2></div>
        <div class="data-box advice-card">
          
          <div class="result-highlight">
             <div class="result-title">3.5% 法則檢測</div>
             <div class="result-value" :class="result.rentRatioVal < 3.5 ? 'text-income' : 'text-expense'">
               年租金為房價 {{ result.rentRatio }}%
             </div>
          </div>

          <div class="advice-content">
             <p class="advice-text" v-if="result.rentRatioVal < 3.5">
               ✅ <strong>租房買股勝出！</strong><br>
               目前的租金成本相對低廉。若您能維持紀律，將 <strong>{{ formatCurrency(params.rentInitialCapital) }}</strong> 的本金與每月價差投入 <strong>{{ params.stockReturnRate }}%</strong> 的標的，40 年後資產將高於買房。
             </p>
             <p class="advice-text" v-else>
               🏠 <strong>買房自住勝出！</strong><br>
               目前的租金成本過高（或房價相對低）。在這種情況下，買房不僅能強迫儲蓄，資產累積速度也可能超過租房投資。
             </p>

             <div class="alert-box" v-if="monthlyMortgage > userData.avgSavings + params.monthlyRent">
               ⚠️ <strong>現金流警告</strong><br>
               寬限期後，每月需支出約 <strong>{{ formatCurrency(monthlyMortgage) }}</strong> (含稅/維護)，這已超過您目前的「月結餘 + 房租」，可能會造成生活拮据！
             </div>
             
             <div class="safe-box" v-else>
               👌 <strong>現金流安全</strong><br>
               以您目前的儲蓄能力，負擔寬限期後的房貸應該游刃有餘。
             </div>
          </div>

        </div>
      </div>

    </div>

  </div>
</template>

<script setup>
import { reactive, computed, onMounted, ref, watchEffect } from 'vue';
import { fetchWithLiffToken, numberFormat } from '@/utils/api';
import 'chart.js/auto';
import { Line } from 'vue-chartjs';

// --- 狀態 ---
const loading = ref(false);
const userData = reactive({
  liquidAssets: 0,
  avgSavings: 0,
  avgIncome: 0
});

// 計算機參數
const params = reactive({
  housePrice: 2000, 
  loanRatio: 80,    
  loanYears: 40,
  gracePeriod: 5,
  interestRate: 1.775,
  monthlyRent: 35000,   
  stockReturnRate: 8,
  houseAppreciation: 4,
  initialCostsRate: 10, // 雜支 10%
  holdingCostRate: 0.8, // 稅+維護 0.8%
  
  rentInitialCapital: 0,
  autoSyncCapital: true 
});

// --- API 獲取真實資料 ---
onMounted(async () => {
  loading.value = true;
  try {
    const API_URL = import.meta.env.VITE_API_BASE_URL || window.API_BASE_URL || 'https://finbot.tw/api.php';
    const url = `${API_URL}?action=financial_snapshot`;

    const response = await fetchWithLiffToken(url);
    if (response && response.ok) {
      const json = await response.json();
      const result = json.data; 
      if (result) {
        userData.liquidAssets = result.liquid_assets || 0;
        userData.avgSavings = result.avg_monthly_savings || 0;
        userData.avgIncome = result.avg_monthly_income || 0;
      }
    }
  } catch (error) {
    console.error("無法取得財務快照:", error);
  } finally {
    loading.value = false;
  }
});

// --- 計算邏輯：中間值 ---
const calculated = computed(() => {
    const hp = params.housePrice * 10000;
    const dp = hp * (1 - params.loanRatio / 100);
    const misc = hp * (params.initialCostsRate / 100);
    const loanAmount = hp - dp;
    
    // 月還款試算
    const monthlyRate = params.interestRate / 100 / 12;
    // 寬限期月付 (僅利息)
    const gracePayment = loanAmount * monthlyRate;
    // 寬限後月付 (本息均攤)
    const payPeriods = (params.loanYears - params.gracePeriod) * 12;
    const fullPayment = (loanAmount * monthlyRate * Math.pow(1 + monthlyRate, payPeriods)) / (Math.pow(1 + monthlyRate, payPeriods) - 1);

    // 月持有成本
    const monthlyHolding = (hp * params.holdingCostRate / 100) / 12;

    // 買房總月支出 (寬限後)
    const monthlyBuyTotal = fullPayment + monthlyHolding;

    // 每月可投入股市 (買房總支出 - 房租)
    const monthlyInvest = monthlyBuyTotal - params.monthlyRent;

    return {
        downPayment: dp,
        initialMisc: misc,
        totalInitialBuyCost: dp + misc,
        loanAmount: loanAmount,
        monthlyHolding: monthlyHolding,
        gracePayment: gracePayment,
        fullPayment: fullPayment,
        monthlyBuyTotal: monthlyBuyTotal,
        monthlyInvest: monthlyInvest
    };
});

// 自動同步邏輯
watchEffect(() => {
    if (params.autoSyncCapital) {
        params.rentInitialCapital = calculated.value.totalInitialBuyCost;
    }
});

// 預估買房後的「長期」月支出 (給警告框用)
const monthlyMortgage = computed(() => calculated.value.monthlyBuyTotal);

// 核心模擬 (生成圖表數據)
const simulation = computed(() => {
  const labels = [];
  const dataStock = [];
  const dataHouse = [];
  
  const rentRatioVal = (params.monthlyRent * 12 / (params.housePrice * 10000)) * 100;

  // 1. 租房組設定
  let stockAssets = params.rentInitialCapital;
  let currentRent = params.monthlyRent;
  
  // 2. 買房組設定
  let currentHousePrice = params.housePrice * 10000;
  let loanBalance = calculated.value.loanAmount;
  
  // 房貸參數
  const monthlyRate = params.interestRate / 100 / 12;
  const graceMonths = params.gracePeriod * 12;
  const payPeriods = (params.loanYears - params.gracePeriod) * 12;
  const pmt = calculated.value.fullPayment; 

  for (let m = 1; m <= 40 * 12; m++) {
    // A. 買房組總支出 (房貸 + 持有成本)
    let houseExpense = 0;
    if (m <= graceMonths) {
      houseExpense = calculated.value.gracePayment; // 只繳息
    } else {
      houseExpense = pmt; // 本息均攤
      // 扣本金
      const interest = loanBalance * monthlyRate;
      loanBalance -= (pmt - interest);
    }
    // 加上隱性持有成本 (稅/維護)
    houseExpense += calculated.value.monthlyHolding;

    // B. 租房組總支出 (租金)
    const rentExpense = currentRent;

    // C. 投資差額 (買房支出 - 租房支出)
    const investDiff = houseExpense - rentExpense;
    stockAssets = stockAssets * (1 + params.stockReturnRate / 100 / 12) + investDiff;

    // D. 房價增值
    currentHousePrice *= (1 + params.houseAppreciation / 100 / 12);
    
    // E. 租金成長
    if (m % 12 === 0) {
      currentRent *= 1.01; 
      
      labels.push(`第${m/12}年`);
      dataStock.push(Math.round(stockAssets / 10000)); 
      dataHouse.push(Math.round((currentHousePrice - loanBalance) / 10000)); 
    }
  }

  return { 
    labels, 
    dataStock, 
    dataHouse,
    rentRatioVal: rentRatioVal, 
    rentRatio: rentRatioVal.toFixed(2)
  };
});

const result = simulation; 

const chartData = computed(() => ({
  labels: result.value.labels,
  datasets: [
    { 
      label: '租房買股淨值', 
      borderColor: '#3b82f6', 
      backgroundColor: '#3b82f6', 
      data: result.value.dataStock,
      pointRadius: 0, 
      borderWidth: 2
    },
    { 
      label: '買房自住淨值', 
      borderColor: '#ef4444', 
      backgroundColor: '#ef4444', 
      data: result.value.dataHouse,
      pointRadius: 0,
      borderWidth: 2
    }
  ]
}));

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: 'index', intersect: false },
  plugins: {
      // 🟢 [新增] 強制關閉這個圖表上的數字標籤 (避免 Dashboard 的設定影響這裡)
    datalabels: {
      display: false
    },
    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
    tooltip: {
      callbacks: {
        label: function(context) {
            let label = context.dataset.label || '';
            if (label) label += ': ';
            if (context.parsed.y !== null) {
                label += '$' + numberFormat(context.parsed.y * 10000, 0);
            }
            return label;
        }
      }
    }
  },
  scales: {
    x: { grid: { display: false }, ticks: { font: { size: 10 }, color: '#999' } },
    y: { 
        grid: { color: '#f0f0f0' },
        ticks: { callback: (val) => val + '萬', font: { size: 10 }, color: '#999' },
        border: { display: false }
    }
  }
};

const formatCurrency = (val) => {
  return '$' + numberFormat(val, 0); 
};
</script>

<style scoped>
.dashboard-container {
  width: 100%;
  max-width: 100%;
  margin: 0 auto;
  color: var(--text-primary);
  padding-bottom: 30px;
}

.card-section { margin-bottom: 20px; }
.section-header h2 {
  font-size: 1.1rem;
  font-weight: 600;
  color: #8c7b75;
  margin-bottom: 12px;
  margin-left: 4px;
}

.data-box {
  background-color: var(--bg-card);
  border-radius: var(--border-radius);
  padding: 20px;
  box-shadow: var(--shadow-soft);
  border: 1px solid #f0ebe5;
}

.intro-text {
  font-size: 0.9rem;
  color: #666;
  line-height: 1.6;
  margin: 0;
}
.intro-text strong { color: var(--color-primary); }

/* 第一列：財務現狀 */
.status-bar {
  display: flex;
  justify-content: space-around;
  align-items: flex-start;
  flex-wrap: wrap;
  gap: 15px;
}
.status-item {
  flex: 1;
  min-width: 120px;
  text-align: center;
}
.status-item label {
  font-size: 0.85rem;
  color: #999;
  display: block;
  margin-bottom: 6px;
}
.status-item .value {
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--text-primary);
  font-family: "Helvetica Neue", sans-serif;
  letter-spacing: 0.5px;
}
.status-item .value.highlight { color: #3b82f6; }
.text-income { color: #8fbc8f; }
.text-expense { color: #e5989b; }
.status-item .hint-text {
  font-size: 0.8rem;
  color: #aaa;
  margin-top: 4px;
}
.status-item .hint-text strong { color: #d4a373; }
.divider-vertical {
  width: 1px;
  height: 40px;
  background-color: #f0ebe5;
  margin-top: 10px;
}

/* Grid 系統 */
.two-col-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
  margin-bottom: 20px;
}
.form-box {
  padding: 20px;
  height: 100%;
  box-sizing: border-box;
}

/* 表單元件 */
.form-group { margin-bottom: 16px; }
.form-group label {
  display: block;
  font-size: 0.85rem;
  color: #999;
  margin-bottom: 6px;
}
.input-std {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid #e0e0e0;
  border-radius: 10px;
  font-size: 1rem;
  color: #333;
  outline: none;
  background: #f9f9f9;
  box-sizing: border-box;
  transition: all 0.2s;
}
.input-std:focus {
  border-color: var(--color-primary);
  background: white;
}
.input-std.disabled-input {
  background: #eee;
  color: #888;
  cursor: not-allowed;
}
.highlight-input {
  background-color: #fffbf5;
  border-color: #d4a373;
}
.form-row { display: flex; gap: 12px; }
.half { flex: 1; width: 50%; }

.separator-dashed {
  height: 1px;
  border-top: 1px dashed #eee;
  margin: 16px 0;
}

/* 成本細節區塊 */
.cost-details-box {
  background: #f7f9fc;
  border-radius: 8px;
  padding: 12px;
  margin-top: 20px;
  font-size: 0.9rem;
}
/* 投資試算專用色 */
.invest-box {
    background: #f0f7f0;
    border: 1px solid #e0f2e0;
}

.detail-title {
  font-weight: bold;
  color: #555;
  margin-bottom: 10px;
  font-size: 0.9rem;
}
.detail-row {
  display: flex;
  justify-content: space-between;
  margin-bottom: 6px;
  color: #666;
}
.d-label { font-size: 0.85rem; }
.d-value { font-weight: 500; font-family: monospace; font-size: 0.95rem; }
.sub-total {
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px solid #e0e0e0;
  font-weight: bold;
  color: #3b82f6;
}
.highlight-value {
    color: #e5989b; /* 強調還款金額 */
    font-weight: 700;
}
.text-gray-500 { color: #888; }

/* 租房初始資金設定 */
.label-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
}
.sync-check {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 0.8rem;
    color: #666;
    cursor: pointer;
}
.field-hint {
    font-size: 0.75rem;
    color: #aaa;
    margin-top: 4px;
}
.mini-info {
  font-size: 0.8rem;
  color: #aaa;
  margin-top: 10px;
  background: #fcfcfc;
  padding: 8px;
  border-radius: 6px;
}

/* 圖表與建議 */
.chart-card { height: 100%; min-height: 350px; }
.chart-container { height: 300px; width: 100%; }
.advice-card { height: 100%; display: flex; flex-direction: column; }

.result-highlight {
  text-align: center;
  margin-bottom: 15px;
  padding-bottom: 15px;
  border-bottom: 1px dashed #f0ebe5;
}
.result-title { font-size: 0.9rem; color: #999; margin-bottom: 4px; }
.result-value { font-size: 1.4rem; font-weight: 800; }

.advice-content {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 15px;
}
.advice-text {
  font-size: 0.95rem;
  color: #555;
  line-height: 1.6;
  margin: 0;
}

.alert-box {
  background-color: #fff0f0;
  color: #d67a7a;
  padding: 12px;
  border-radius: 8px;
  font-size: 0.9rem;
  border: 1px solid #fecaca;
  line-height: 1.5;
}
.safe-box {
  background-color: #f0fdf4;
  color: #15803d;
  padding: 12px;
  border-radius: 8px;
  font-size: 0.9rem;
  border: 1px solid #bbf7d0;
}

/* 手機版適配 */
@media (max-width: 768px) {
  .two-col-grid { grid-template-columns: 1fr; }
  .status-bar { gap: 20px; }
  .divider-vertical { display: none; }
  .status-item { width: 45%; }
}
</style>