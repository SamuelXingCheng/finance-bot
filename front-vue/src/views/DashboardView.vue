<template>
  <div class="space-y-6">
    <div class="section-title">
      <h2 class="text-xl font-semibold text-amber-700">手動新增交易</h2>
    </div>
    <div class="data-box bg-white p-4 rounded-lg shadow-md border border-stone-200">
      <form id="add-transaction-form" class="space-y-3" @submit.prevent="handleTransactionSubmit">
        <p class="text-sm font-medium text-gray-700 mb-1">類型:</p>
        <div class="flex items-center space-x-4">
          <input type="radio" id="expense" v-model="transactionForm.type" value="expense" required class="form-radio text-amber-700"><label for="expense" class="ml-1 text-red-600">支出</label>
          <input type="radio" id="income" v-model="transactionForm.type" value="income" required class="form-radio text-amber-700"><label for="income" class="ml-1 text-green-600">收入</label>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">金額:</label>
          <input type="number" v-model.number="transactionForm.amount" required min="0.01" step="0.01" class="w-full p-2 border border-stone-300 rounded-md">
        </div>
        
        <button type="submit" class="w-full py-2 bg-amber-700 text-white font-semibold rounded-md hover:bg-amber-800 transition duration-150">新增交易</button>
      </form>
      <div id="form-message" class="mt-4 font-bold text-center" :class="messageClass">{{ formMessage }}</div>
    </div>
    
    <div class="section-title">
      <h2 class="text-xl font-semibold text-amber-700">淨資產總覽</h2>
    </div>
    <div v-if="assetLoading" class="text-center text-gray-500 py-4">載入中...</div>
    <div v-else-if="assetError" class="text-center text-red-600 py-4">{{ assetError }}</div>
    <div v-else id="asset-summary" class="data-box bg-white p-4 rounded-lg shadow-md border border-stone-200">
        <p class="text-gray-500">全球淨值 (TWD):</p>
        <span :class="['net-worth', globalNetWorth >= 0 ? 'text-green-600' : 'text-red-600']">{{ numberFormat(globalNetWorth, 2) }}</span>
        </div>

    <div class="section-title">
      <h2 class="text-xl font-semibold text-amber-700">本月支出報表</h2>
    </div>
    <div id="expense-breakdown" class="data-box bg-white p-4 rounded-lg shadow-md border border-stone-200">
        <div id="chart-container" class="max-w-md mx-auto">
            <canvas ref="expenseChartCanvas"></canvas>
        </div>
        <p class="text-center font-medium mt-4 text-red-600">本月總支出: <span class="text-xl font-bold">{{ numberFormat(totalExpense, 2) }}</span></p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import { fetchWithLiffToken, numberFormat, generateColors } from '@/utils/api';
import Chart from 'chart.js/auto'; // 引入 Chart.js

// 狀態管理
const assetData = ref({});
const assetLoading = ref(true);
const assetError = ref('');
const totalExpense = ref(0);
const expenseBreakdown = ref({});
const chartInstance = ref(null);
const expenseChartCanvas = ref(null);
const formMessage = ref('');
const messageClass = ref('');

// 表單數據 (需補齊所有欄位)
const transactionForm = ref({
  type: 'expense',
  amount: null,
  date: new Date().toISOString().substring(0, 10), // YYYY-MM-DD
  description: '',
  category: 'Miscellaneous',
  currency: 'TWD',
});

// 計算屬性
const globalNetWorth = computed(() => assetData.value.global_twd_net_worth || 0);

// --- 數據載入函式 ---

async function fetchAssetSummary() {
    assetLoading.value = true;
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=asset_summary`);
    if (response) {
        const result = await response.json();
        if (result.status === 'success') {
            assetData.value = result.data;
        } else {
            assetError.value = result.message;
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
            expenseBreakdown.value = result.data.breakdown;
            renderChart();
        } else {
            console.error(result.message);
        }
    }
}

// --- 圖表渲染 ---

function renderChart() {
  if (chartInstance.value) {
    chartInstance.value.destroy();
  }

  const labels = Object.keys(expenseBreakdown.value);
  const dataValues = Object.values(expenseBreakdown.value).map(v => parseFloat(v));

  if (labels.length === 0 || totalExpense.value <= 0) return;

  chartInstance.value = new Chart(expenseChartCanvas.value, {
    type: 'pie',
    data: {
      labels: labels,
      datasets: [{
        data: dataValues,
        backgroundColor: generateColors(labels.length),
        hoverOffset: 8,
      }],
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'right' },
            title: { display: false }
        }
    },
  });
}

// --- 交易提交處理 ---

async function handleTransactionSubmit() {
  formMessage.value = '處理中...';
  messageClass.value = 'text-gray-500';

  const dataToSend = { ...transactionForm.value };

  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=add_transaction`, {
    method: 'POST',
    body: JSON.stringify(dataToSend)
  });

  if (response) {
    const result = await response.json();
    if (result.status === 'success') {
      formMessage.value = '🎉 ' + result.message;
      messageClass.value = 'text-green-600';
      // 刷新數據
      fetchAssetSummary();
      fetchExpenseData();
    } else {
      formMessage.value = '❌ ' + (result.message || '新增失敗');
      messageClass.value = 'text-red-600';
    }
  }
}

// 當組件掛載時，開始載入數據
onMounted(() => {
    fetchAssetSummary();
    fetchExpenseData();
});

// 當外部通知刷新時 (來自 AccountManagerView)，重新載入
// 假設 AccountManagerView 會發射一個 'refreshDashboard' 事件
// 由於 Vue Router 尚未設定，這個邏輯需要外部元件直接調用
// watch(() => props.refreshTrigger, () => {
//     fetchAssetSummary();
//     fetchExpenseData();
// });
</script>