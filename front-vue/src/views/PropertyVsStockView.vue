<template>
  <div class="dashboard-container">
    
    <div class="card-section">
      <div class="section-header">
        <h2>陪跑模擬器</h2>
      </div>
      <div class="data-box intro-card">
        <p class="intro-text">
          這是一個基於<strong>「3.5% 法則」</strong>的財務決策工具。
          設定起始日並<strong>儲存策略</strong>後，系統將自動彙整您的記帳資料，追蹤資產累積是否符合預期。
        </p>
      </div>
    </div>

    <div class="card-section">
      <div class="section-header"><h2>1. 您的財務現狀</h2></div>
      <div class="data-box status-bar">
        <div class="status-item">
          <label>可動用資金 (實際資產)</label>
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
                <span class="d-label">總初始資金</span>
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

          <div class="form-row">
             <div class="form-group half">
                <label>策略起始年月</label>
                <input type="month" v-model="params.strategyStartDate" class="input-std">
             </div>
             <div class="form-group half">
                <div class="label-row">
                    <label>初始資金</label>
                    <div class="sync-check">
                        <input type="checkbox" id="syncCap" v-model="params.autoSyncCapital">
                        <label for="syncCap">比照買房</label>
                    </div>
                </div>
                <input 
                    type="number" 
                    v-model.number="params.rentInitialCapital" 
                    class="input-std" 
                    :disabled="params.autoSyncCapital"
                    :class="{ 'disabled-input': params.autoSyncCapital }"
                >
             </div>
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
             <div class="detail-title">投資試算 (每月投入)</div>
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
                <span class="d-label">每月投入股市</span>
                <span class="d-value highlight-value" :class="calculated.monthlyInvest > 0 ? 'text-income' : 'text-expense'">
                    {{ formatCurrency(calculated.monthlyInvest) }}
                </span>
             </div>
             <p class="field-hint text-income" v-if="calculated.monthlyInvest > 0">
                 租房較省！請將此差額定期投入 <strong>{{ params.stockReturnRate }}%</strong> 的標的。
             </p>
             <p class="field-hint text-expense" v-else>
                 ⚠️ 房租比買房還貴！每月需從本金扣除 {{ formatCurrency(Math.abs(calculated.monthlyInvest)) }}。
             </p>
          </div>

        </div>
      </div>

    </div>

    <div class="card-section dashboard-spacer" v-if="progressData.hasStarted">
       <div class="section-header"><h2>策略執行儀表板</h2></div>
       <div class="data-box progress-card">
          
          <div class="progress-header">
             <div class="p-item text-left">
                <span class="p-label">已執行時間</span>
                <span class="p-val">{{ progressData.durationText }}</span>
             </div>
             
             <div class="p-item text-center">
                <span class="p-label">目前投資總本金</span>
                <div class="big-stat-wrapper">
                    <div class="big-stat-value text-dark">
                        {{ formatCurrency(params.actualCost) }}
                    </div>
                    <div class="stat-badge" v-if="progressData.ledgerAdded > 0">
                        含記帳投入 {{ formatCurrency(progressData.ledgerAdded) }}
                    </div>
                </div>
             </div>

             <div class="p-item text-right">
                <span class="p-label">目前實際資產</span>
                <span class="big-stat-value text-blue">{{ formatCurrency(userData.liquidAssets) }}</span>
                <div class="mini-target-text">目標 {{ formatCurrency(progressData.targetAsset) }}</div>
             </div>
          </div>

          <div class="progress-bar-container">
             <div class="progress-track">
                <div class="progress-fill target-fill" style="width: 100%; background: #eee;"></div>
                <div class="progress-fill" 
                     :style="{ width: Math.min(progressData.percent, 100) + '%' }"
                     :class="progressData.isAhead ? 'bg-success' : 'bg-warning'">
                </div>
             </div>
             <div class="progress-labels">
                <span>0</span>
                <span class="current-marker" :style="{ left: Math.min(progressData.percent, 100) + '%' }">
                    {{ progressData.percent }}%
                </span>
             </div>
          </div>

          <div class="analysis-grid">
             
             <div class="analysis-box">
                <div class="ab-header">
                    <span class="ab-title">儲蓄紀律檢視</span>
                </div>
                <div class="ab-content">
                    <div class="ab-row">
                        <span>理論應投入本金</span>
                        <span class="font-mono">{{ formatCurrency(progressData.theoreticalPrincipal) }}</span>
                    </div>
                    <div class="ab-row">
                        <span>實際投入本金</span>
                        <span class="font-mono font-bold" :class="progressData.savingGap >= 0 ? 'text-income' : 'text-expense'">
                            {{ formatCurrency(params.actualCost) }}
                        </span>
                    </div>
                </div>
                <div class="ab-footer">
                   <span class="status-badge" :class="progressData.savingGap >= 0 ? 'badge-success' : 'badge-danger'">
                       {{ progressData.savingGap >= 0 ? '超額儲蓄' : '少存了' }} {{ formatCurrency(Math.abs(progressData.savingGap)) }}
                   </span>
                </div>
             </div>

             <div class="analysis-box">
                <div class="ab-header">
                    <span class="ab-title">投資績效檢視</span>
                </div>
                <div class="ab-content">
                    <div class="ab-row">
                        <span>預期獲利 ({{ params.stockReturnRate }}%)</span>
                        <span class="font-mono">{{ formatCurrency(progressData.targetAsset - progressData.theoreticalPrincipal) }}</span>
                    </div>
                    <div class="ab-row">
                        <span>實際獲利 ({{ progressData.actualRoi }}%)</span>
                        <span class="font-mono font-bold" :class="progressData.actualProfit >= 0 ? 'text-income' : 'text-expense'">
                            {{ formatCurrency(progressData.actualProfit) }}
                        </span>
                    </div>
                </div>
                <div class="ab-footer">
                   <span class="status-badge" :class="progressData.roiGap >= 0 ? 'badge-success' : 'badge-danger'">
                       {{ progressData.roiGap >= 0 ? '績效優於預期' : '績效落後預期' }}
                   </span>
                </div>
             </div>
          </div>

       </div>
    </div>

    <div class="two-col-grid results-grid">
      
      <div class="card-section">
        <div class="section-header"><h2>40年資產模擬圖表</h2></div>
        <div class="data-box chart-card">
          <div class="chart-container">
             <Line :data="chartData" :options="chartOptions" />
          </div>
        </div>
      </div>

      <div class="card-section">
        <div class="section-header"><h2>AI 財務建議</h2></div>
        <div class="data-box advice-card">
          
          <div class="result-highlight">
             <div class="result-title">3.5% 法則檢測</div>
             <div class="result-value" :class="result.rentRatioVal < 3.5 ? 'text-income' : 'text-expense'">
               年租金為房價 {{ result.rentRatio }}%
             </div>
          </div>

          <div class="advice-content">
             <p class="advice-text" v-if="result.rentRatioVal < 3.5">
               <strong>租房買股勝出！</strong><br>
               目前租金成本低。若維持紀律，將 <strong>{{ formatCurrency(params.rentInitialCapital) }}</strong> 本金與每月價差投入 <strong>{{ params.stockReturnRate }}%</strong> 標的，40 年後資產將高於買房。
             </p>
             <p class="advice-text" v-else>
               <strong>買房自住勝出！</strong><br>
               目前租金成本過高。買房不僅能強迫儲蓄，資產累積速度也可能超過租房投資。
             </p>

             <div class="alert-box" v-if="monthlyMortgage > userData.avgSavings + params.monthlyRent">
               <strong>現金流警告</strong><br>
               寬限期後，月支出約 <strong>{{ formatCurrency(monthlyMortgage) }}</strong>，已超過您目前的「月結餘 + 房租」，生活恐拮据！
             </div>
             
             <div class="safe-box" v-else>
               <strong>現金流安全</strong><br>
               以您目前的儲蓄能力，負擔寬限期後的房貸應該游刃有餘。
             </div>
          </div>

        </div>
      </div>

    </div>

    <div class="card-section">
      <div class="data-box action-card">
        <div class="action-text">
          <h3>儲存此策略</h3>
          <p>系統將以<strong>「{{ params.strategyStartDate }}」</strong>為起點，自動彙整您記帳中的「投資」支出，並與您的實際資產進行比對。</p>
        </div>
        <button class="btn-save" @click="saveStrategy" :disabled="loading">
          {{ loading ? '處理中...' : '確認並開始陪跑' }}
        </button>
      </div>
    </div>

  </div>
</template>

<script setup>
import { reactive, computed, onMounted, ref, watchEffect } from 'vue';
import { fetchWithLiffToken, numberFormat } from '@/utils/api';
import 'chart.js/auto';
import { Line } from 'vue-chartjs';

const loading = ref(false);
const userData = reactive({ liquidAssets: 0, avgSavings: 0, avgIncome: 0 });

const todayStr = new Date().toISOString().slice(0, 7); 

const params = reactive({
  housePrice: 2000, loanRatio: 80, loanYears: 40, gracePeriod: 5, interestRate: 1.775,
  monthlyRent: 35000, stockReturnRate: 8, houseAppreciation: 4,
  initialCostsRate: 10, holdingCostRate: 0.8,
  
  rentInitialCapital: 0,
  autoSyncCapital: true,
  strategyStartDate: todayStr,
  actualCost: 0, 
  ledgerAdded: 0 
});

// 載入資料 (包含策略與記帳數據)
const loadData = async () => {
  loading.value = true;
  try {
    const API_URL = import.meta.env.VITE_API_BASE_URL || window.API_BASE_URL || 'https://finbot.tw/api.php';
    const response = await fetchWithLiffToken(`${API_URL}?action=get_pacing_status&strategy_type=rent_vs_buy`);
    
    if (response && response.ok) {
      const json = await response.json();
      const result = json.data;
      
      if (result) {
        userData.liquidAssets = result.liquid_assets || 0;
        userData.avgSavings = result.avg_monthly_savings || 0;
        userData.avgIncome = result.avg_monthly_income || 0;
      }

      if (json.mode === 'dashboard' && result.strategy) {
        const savedParams = result.strategy.params;
        const savedStrategy = result.strategy;

        Object.assign(params, savedParams);
        
        if (result.progress && result.progress.added_principal_from_ledger !== undefined) {
            params.ledgerAdded = parseFloat(result.progress.added_principal_from_ledger);
            params.actualCost = parseFloat(savedStrategy.initial_capital) + params.ledgerAdded;
        } else {
            params.actualCost = parseFloat(savedStrategy.initial_capital);
        }
      }
    }
  } catch (error) { 
    console.error("無法取得資料:", error); 
  } finally { 
    loading.value = false; 
  }
};

onMounted(() => {
  loadData();
});

// 儲存策略
const saveStrategy = async () => {
    try {
        loading.value = true;
        const payload = {
            type: 'rent_vs_buy',
            start_date: params.strategyStartDate,
            initial_capital: params.rentInitialCapital,
            monthly_invest_target: calculated.value.monthlyInvest,
            params: params 
        };

        const API_URL = import.meta.env.VITE_API_BASE_URL || window.API_BASE_URL || 'https://finbot.tw/api.php';
        
        const response = await fetchWithLiffToken(`${API_URL}?action=save_strategy`, {
            method: 'POST',
            body: JSON.stringify(payload)
        });

        if (response && response.ok) {
            alert('策略已儲存！開始為您追蹤進度。');
            await loadData(); 
        } else {
            alert('儲存失敗，請稍後再試');
        }
    } catch (e) {
        console.error(e);
        alert('系統發生錯誤');
    } finally {
        loading.value = false;
    }
};

const calculated = computed(() => {
    const hp = params.housePrice * 10000;
    const dp = hp * (1 - params.loanRatio / 100);
    const misc = hp * (params.initialCostsRate / 100);
    const loanAmount = hp - dp;
    const monthlyRate = params.interestRate / 100 / 12;
    const gracePayment = loanAmount * monthlyRate;
    const payPeriods = (params.loanYears - params.gracePeriod) * 12;
    const fullPayment = (loanAmount * monthlyRate * Math.pow(1 + monthlyRate, payPeriods)) / (Math.pow(1 + monthlyRate, payPeriods) - 1);
    const monthlyHolding = (hp * params.holdingCostRate / 100) / 12;
    const monthlyBuyTotal = fullPayment + monthlyHolding;
    const monthlyInvest = monthlyBuyTotal - params.monthlyRent; 

    return {
        downPayment: dp, initialMisc: misc, totalInitialBuyCost: dp + misc,
        loanAmount, monthlyHolding, gracePayment, fullPayment,
        monthlyBuyTotal, monthlyInvest
    };
});

watchEffect(() => {
    if (params.autoSyncCapital) params.rentInitialCapital = calculated.value.totalInitialBuyCost;
});

const monthlyMortgage = computed(() => calculated.value.monthlyBuyTotal);

const progressData = computed(() => {
    if (!params.strategyStartDate) return { hasStarted: false };

    const start = new Date(params.strategyStartDate);
    const now = new Date();
    let monthsDiff = (now.getFullYear() - start.getFullYear()) * 12 + (now.getMonth() - start.getMonth());
    
    if (monthsDiff < 0) monthsDiff = 0;

    let theoreticalPrincipal = params.rentInitialCapital; 
    let targetAsset = params.rentInitialCapital;        
    
    const monthlyInvest = calculated.value.monthlyInvest;

    if (monthsDiff >= 1) {
        for (let m = 1; m <= monthsDiff; m++) {
            theoreticalPrincipal += monthlyInvest;
            targetAsset = targetAsset * (1 + params.stockReturnRate / 100 / 12) + monthlyInvest;
        }
    }

    const actualAsset = userData.liquidAssets;
    const actualPrincipal = params.actualCost > 0 ? params.actualCost : theoreticalPrincipal;

    const savingGap = actualPrincipal - theoreticalPrincipal; 
    const actualProfit = actualAsset - actualPrincipal;
    const actualRoi = actualPrincipal > 0 ? ((actualProfit / actualPrincipal) * 100).toFixed(2) : 0;
    
    const theoreticalProfit = targetAsset - theoreticalPrincipal;
    const roiGap = actualProfit - theoreticalProfit;
    
    const percent = targetAsset > 0 ? Math.round((actualAsset / targetAsset) * 100) : 0;

    let durationText = "";
    if (monthsDiff === 0) {
        durationText = "剛開始 (第 1 個月)";
    } else {
        const years = Math.floor(monthsDiff / 12);
        const months = monthsDiff % 12;
        durationText = years > 0 ? `${years}年 ${months}個月` : `${months}個月`;
    }

    return {
        hasStarted: true, 
        durationText,
        targetAsset,
        theoreticalPrincipal,
        savingGap,
        actualProfit,
        actualRoi,
        roiGap,
        percent,
        ledgerAdded: params.ledgerAdded,
        isAhead: actualAsset >= targetAsset
    };
});

const simulation = computed(() => {
  const labels = [];
  const dataStock = [];
  const dataHouse = [];
  const rentRatioVal = (params.monthlyRent * 12 / (params.housePrice * 10000)) * 100;

  let stockAssets = params.rentInitialCapital;
  let currentRent = params.monthlyRent;
  let currentHousePrice = params.housePrice * 10000;
  let loanBalance = calculated.value.loanAmount;
  
  const monthlyRate = params.interestRate / 100 / 12;
  const graceMonths = params.gracePeriod * 12;
  const pmt = calculated.value.fullPayment; 

  for (let m = 1; m <= 40 * 12; m++) {
    let houseExpense = 0;
    if (m <= graceMonths) {
      houseExpense = calculated.value.gracePayment; 
    } else {
      houseExpense = pmt; 
      const interest = loanBalance * monthlyRate;
      loanBalance -= (pmt - interest);
    }
    houseExpense += calculated.value.monthlyHolding;
    
    const rentExpense = currentRent;
    const investDiff = houseExpense - rentExpense;
    stockAssets = stockAssets * (1 + params.stockReturnRate / 100 / 12) + investDiff;
    currentHousePrice *= (1 + params.houseAppreciation / 100 / 12);
    
    if (m % 12 === 0) {
      currentRent *= 1.01; 
      labels.push(`第${m/12}年`);
      dataStock.push(Math.round(stockAssets / 10000)); 
      dataHouse.push(Math.round((currentHousePrice - loanBalance) / 10000)); 
    }
  }
  return { labels, dataStock, dataHouse, rentRatioVal, rentRatio: rentRatioVal.toFixed(2) };
});

const result = simulation; 
const chartData = computed(() => ({
  labels: result.value.labels,
  datasets: [
    { label: '租房買股淨值', borderColor: '#3b82f6', backgroundColor: '#3b82f6', data: result.value.dataStock, pointRadius: 0, borderWidth: 2 },
    { label: '買房自住淨值', borderColor: '#ef4444', backgroundColor: '#ef4444', data: result.value.dataHouse, pointRadius: 0, borderWidth: 2 }
  ]
}));

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: 'index', intersect: false },
  plugins: {
    datalabels: { display: false },
    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
    tooltip: {
      callbacks: {
        label: function(context) {
            let label = context.dataset.label || '';
            if (label) label += ': ';
            if (context.parsed.y !== null) label += '$' + numberFormat(context.parsed.y * 10000, 0);
            return label;
        }
      }
    }
  },
  scales: {
    x: { grid: { display: false }, ticks: { font: { size: 10 }, color: '#999', maxRotation: 0, autoSkip: true } },
    y: { grid: { color: '#f0f0f0' }, ticks: { callback: (val) => val + '萬', font: { size: 10 }, color: '#999' }, border: { display: false } }
  }
};

const formatCurrency = (val) => '$' + numberFormat(val, 0); 
</script>

<style scoped>
.dashboard-container { width: 100%; max-width: 100%; margin: 0 auto; color: var(--text-primary); padding-bottom: 30px; }
.card-section { margin-bottom: 20px; }
.section-header h2 { font-size: 1.1rem; font-weight: 600; color: #8c7b75; margin-bottom: 12px; margin-left: 4px; }
.data-box { background-color: var(--bg-card); border-radius: var(--border-radius); padding: 20px; box-shadow: var(--shadow-soft); border: 1px solid #f0ebe5; }
.intro-text { font-size: 0.9rem; color: #666; line-height: 1.6; margin: 0; }
.intro-text strong { color: var(--color-primary); }

.status-bar { display: flex; justify-content: space-around; align-items: flex-start; flex-wrap: wrap; gap: 15px; }
.status-item { flex: 1; min-width: 120px; text-align: center; }
.status-item label { font-size: 0.85rem; color: #999; display: block; margin-bottom: 6px; }
.status-item .value { font-size: 1.5rem; font-weight: 700; color: var(--text-primary); font-family: "Helvetica Neue", sans-serif; letter-spacing: 0.5px; }
.status-item .value.highlight { color: #3b82f6; }
.text-income { color: #10b981; }
.text-expense { color: #ef4444; }
.status-item .hint-text { font-size: 0.8rem; color: #aaa; margin-top: 4px; }
.status-item .hint-text strong { color: #d4a373; }
.divider-vertical { width: 1px; height: 40px; background-color: #f0ebe5; margin-top: 10px; }

.two-col-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
.form-box { padding: 20px; height: 100%; box-sizing: border-box; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 0.85rem; color: #999; margin-bottom: 6px; }
.input-std { width: 100%; padding: 10px 12px; border: 1px solid #e0e0e0; border-radius: 10px; font-size: 1rem; color: #333; outline: none; background: #f9f9f9; box-sizing: border-box; transition: all 0.2s; }
.input-std:focus { border-color: var(--color-primary); background: white; }
.input-std.disabled-input { background: #eee; color: #888; cursor: not-allowed; }
.highlight-input { background-color: #fffbf5; border-color: #d4a373; }
.form-row { display: flex; gap: 12px; }
.half { flex: 1; width: 50%; }
.separator-dashed { height: 1px; border-top: 1px dashed #eee; margin: 16px 0; }

.cost-details-box { background: #f7f9fc; border-radius: 8px; padding: 12px; margin-top: 20px; font-size: 0.9rem; }
.invest-box { background: #f0f7f0; border: 1px solid #e0f2e0; }
.detail-title { font-weight: bold; color: #555; margin-bottom: 10px; font-size: 0.9rem; }
.detail-row { display: flex; justify-content: space-between; margin-bottom: 6px; color: #666; }
.d-label { font-size: 0.85rem; }
.d-value { font-weight: 500; font-family: monospace; font-size: 0.95rem; }
.sub-total { margin-top: 8px; padding-top: 8px; border-top: 1px solid #e0e0e0; font-weight: bold; color: #3b82f6; }
.highlight-value { color: #e5989b; font-weight: 700; }
.text-gray-500 { color: #888; }
.label-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
.sync-check { display: flex; align-items: center; gap: 4px; font-size: 0.8rem; color: #666; cursor: pointer; }
.field-hint { font-size: 0.75rem; color: #aaa; margin-top: 4px; }

/* Dashboard Styles Modified */
.dashboard-spacer { margin-top: 40px; } /* 增加上邊距防止重疊 */

.progress-card { padding: 24px; }
.progress-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 20px; gap: 10px; }
.p-item { flex: 1; }
.p-label { font-size: 0.8rem; color: #999; margin-bottom: 6px; display: block; text-transform: uppercase; letter-spacing: 0.5px; }
.p-val { font-size: 1rem; font-weight: bold; color: #555; }
.text-left { text-align: left; }
.text-center { text-align: center; }
.text-right { text-align: right; }

.big-stat-wrapper { display: flex; flex-direction: column; align-items: center; }
.big-stat-value { font-size: 1.8rem; font-weight: 800; line-height: 1.2; font-family: "Helvetica Neue", sans-serif; letter-spacing: -0.5px; }
.text-dark { color: #2c3e50; }
.text-blue { color: #3b82f6; }
.stat-badge { background: #f3f4f6; color: #6b7280; font-size: 0.75rem; padding: 3px 8px; border-radius: 12px; margin-top: 5px; font-weight: 500; }
.mini-target-text { font-size: 0.75rem; color: #9ca3af; margin-top: 4px; }

.progress-bar-container { position: relative; height: 36px; margin: 30px 0; } /* 增加高度與間距 */
.progress-track { 
    width: 100%; height: 12px; background: #e5e7eb; border-radius: 6px; 
    overflow: hidden; position: relative; top: 12px; 
}
.progress-fill { height: 100%; position: absolute; left: 0; top: 0; transition: width 0.5s ease; }
.bg-success { background: #10b981; }
.bg-warning { background: #f59e0b; }
.progress-labels { position: relative; height: 100%; width: 100%; }
.current-marker { 
    position: absolute; transform: translateX(-50%); top: -5px; 
    background: #3b82f6; color: white; padding: 4px 10px; border-radius: 20px; 
    font-size: 0.8rem; font-weight: bold; white-space: nowrap; 
    box-shadow: 0 2px 5px rgba(59, 130, 246, 0.4); z-index: 10;
}
.current-marker::after {
    content: ''; position: absolute; bottom: -5px; left: 50%; transform: translateX(-50%);
    border-width: 5px 5px 0; border-style: solid; border-color: #3b82f6 transparent transparent transparent;
}

.analysis-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 25px; }
.analysis-box { background: #ffffff; padding: 15px; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
.ab-header { margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #f3f4f6; }
.ab-title { font-size: 0.9rem; font-weight: 600; color: #4b5563; }
.ab-content { margin-bottom: 12px; }
.ab-row { display: flex; justify-content: space-between; font-size: 0.85rem; margin-bottom: 6px; color: #6b7280; }
.ab-footer { text-align: right; }
.font-mono { font-family: monospace; }
.font-bold { font-weight: 700; }

.status-badge { display: inline-block; font-size: 0.75rem; padding: 4px 10px; border-radius: 6px; font-weight: 600; }
.badge-success { background: #d1fae5; color: #065f46; }
.badge-danger { background: #fee2e2; color: #991b1b; }

.chart-card { height: 100%; min-height: 350px; }
.chart-container { height: 300px; width: 100%; }
.advice-card { height: 100%; display: flex; flex-direction: column; }
.result-highlight { text-align: center; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px dashed #f0ebe5; }
.result-title { font-size: 0.9rem; color: #999; margin-bottom: 4px; }
.result-value { font-size: 1.4rem; font-weight: 800; }
.advice-content { flex: 1; display: flex; flex-direction: column; gap: 15px; }
.advice-text { font-size: 0.95rem; color: #555; line-height: 1.6; margin: 0; }
.alert-box { background-color: #fff0f0; color: #d67a7a; padding: 12px; border-radius: 8px; font-size: 0.9rem; border: 1px solid #fecaca; line-height: 1.5; }
.safe-box { background-color: #f0fdf4; color: #15803d; padding: 12px; border-radius: 8px; font-size: 0.9rem; border: 1px solid #bbf7d0; }

.action-card { display: flex; justify-content: space-between; align-items: center; background: #fffbeb; border: 1px solid #fcd34d; }
.action-text h3 { font-size: 1rem; color: #92400e; margin: 0 0 4px 0; }
.action-text p { font-size: 0.85rem; color: #b45309; margin: 0; }
.btn-save { background-color: #d97706; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: background 0.2s; white-space: nowrap; margin-left: 15px; }
.btn-save:hover { background-color: #b45309; }
.btn-save:disabled { background-color: #ccc; cursor: not-allowed; }

@media (max-width: 768px) {
  .two-col-grid { grid-template-columns: 1fr; }
  .status-bar { gap: 20px; }
  .divider-vertical { display: none; }
  .status-item { width: 45%; }
  .progress-header { flex-direction: column; align-items: stretch; gap: 25px; text-align: center; }
  .p-item.text-left, .p-item.text-right, .p-item.text-center { text-align: center; }
  .big-stat-wrapper { margin: 10px 0; }
  .analysis-grid { grid-template-columns: 1fr; }
  .action-card { flex-direction: column; text-align: center; gap: 15px; }
  .btn-save { width: 100%; margin-left: 0; }
}
</style>