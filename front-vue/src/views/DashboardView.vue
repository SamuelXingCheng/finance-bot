<template>
  <div class="dashboard-container">
    
    <div class="card-section" v-if="!isPremium">
      <div class="data-box premium-box">
        <div class="premium-content">
          <div class="premium-header">
            <h2 class="premium-title">請一杯咖啡，升級 Premium 會員</h2>
            <span class="premium-badge">PRO</span>
          </div>
          <div class="premium-price">僅需 <span class="price-tag">$3 USD</span> (約 NT$95)</div>
          <p class="premium-desc">訂閱會員可立即解鎖無限制 AI 服務與進階報表。</p>
          <div class="payment-buttons">
            <button class="btn-pay btn-bmc" @click="openPaymentModal('bmc')">Apple Pay / 信用卡</button>
            <button class="btn-pay btn-crypto" @click="openPaymentModal('crypto')">加密貨幣支付</button>
          </div>
        </div>
      </div>
    </div>

    <div class="card-section" v-if="userBudget > 0">
      <div class="section-header"><h2>本月預算監控</h2></div>
      <div class="data-box budget-card">
        <div class="budget-info">
          <span class="budget-label">預算: NT$ {{ numberFormat(userBudget, 0) }}</span>
          <span class="budget-percent" :class="budgetStatusColor">{{ budgetPercent }}%</span>
        </div>
        <div class="progress-track">
          <div class="progress-fill"
               :class="budgetBarColor"
               :style="{ width: Math.min(budgetPercent, 100) + '%' }">
          </div>
        </div>
        <p class="budget-remaining">
          剩餘可支出: <span :class="{'text-danger': (userBudget - totalExpense) < 0}">NT$ {{ numberFormat(userBudget - totalExpense, 0) }}</span>
        </p>
      </div>
    </div>
    <div class="card-section">
      <div class="section-header"><h2>智慧記帳</h2></div>
      <div class="data-box upload-card">
        <div class="upload-area" @click="triggerFileInput" :class="{ analyzing: isAnalyzing }">
          <input 
            type="file" 
            ref="fileInput" 
            class="hidden-input" 
            accept="image/*,application/pdf" 
            @change="handleFileChange"
          >
          
          <div v-if="isAnalyzing" class="loading-content">
            <span class="loader"></span>
            <p>AI 正在分析單據...</p>
          </div>
          <div v-else class="upload-content">
            <!-- <span class="icon">📸</span> -->
            <p><strong>拍照或上傳單據</strong></p>
            <p class="sub">支援發票、收據、PDF 帳單</p>
          </div>
        </div>
      </div>
    </div>
    <!-- <div class="card-section">
      <div class="section-header"><h2>快速記帳</h2></div>
      <div class="data-box input-card">
        <form id="add-transaction-form" @submit.prevent="handleTransactionSubmit">
          <div class="form-group type-select">
            <label>類型</label>
            <div class="radio-group">
              <label class="radio-label" :class="{ active: transactionForm.type === 'expense' }">
                <input type="radio" v-model="transactionForm.type" value="expense"><span>支出</span>
              </label>
              <label class="radio-label" :class="{ active: transactionForm.type === 'income' }">
                <input type="radio" v-model="transactionForm.type" value="income"><span>收入</span>
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
                <input type="text" v-model="transactionForm.currency" class="input-minimal" placeholder="代碼" required @input="forceUppercase">
                <button type="button" class="back-btn" @click="resetCurrency">↩</button>
              </div>
              <select v-else v-model="currencySelectValue" class="input-minimal" @change="handleCurrencyChange">
                <option value="TWD">新台幣 (TWD)</option>
                <option value="USD">美元 (USD)</option>
                <option value="JPY">日圓 (JPY)</option>
                <option value="CNY">人民幣 (CNY)</option>
                <option value="USDT">泰達幣 (USDT)</option>
                <option value="CUSTOM">自行輸入...</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label>日期</label>
            <input type="date" v-model="transactionForm.date" required class="input-minimal">
          </div>
          <div class="form-group">
            <label>項目說明 <span class="text-xs text-gray-400">(可使用 #標籤)</span></label>
            <input type="text" v-model="transactionForm.description" required placeholder="例如：拿鐵 #早餐" class="input-minimal">
          </div>
          <div class="form-group">
            <label>分類</label>
            <div class="select-wrapper">
              <select v-model="selectCategoryMode" class="input-minimal" @change="handleCategoryChange">
                <option v-for="(name, key) in categoryMap" :key="key" :value="key">{{ name }}</option>
                <option value="CUSTOM_INPUT">➕ 自訂類別...</option>
              </select>
            </div>

            <div v-if="isCustomCategory" class="mt-2">
              <input 
                type="text" 
                v-model="transactionForm.category" 
                placeholder="請輸入新類別 (例如: 公關費)" 
                class="input-minimal"
                required
              >
            </div>
          </div>
          <button type="submit" class="submit-btn">新增紀錄</button>
        </form>
        <transition name="fade">
          <div v-if="formMessage" id="form-message" :class="messageClass">{{ formMessage }}</div>
        </transition>
      </div>
    </div> -->
    
    <div class="card-section">
      <div class="section-header"><h2>本月收支分佈</h2></div>
      <div id="expense-breakdown" class="data-box chart-card">
          <div class="stats-row">
            <div class="stat-item cursor-pointer" :class="{ 'active-stat': currentChartType === 'income' }" @click="toggleChart('income')">
              <span class="label">總收入</span><span class="value text-income">NT$ {{ numberFormat(totalIncome, 2) }}</span>
            </div>
            <div class="vertical-line"></div>
            <div class="stat-item cursor-pointer" :class="{ 'active-stat': currentChartType === 'expense' }" @click="toggleChart('expense')">
              <span class="label">總支出</span><span class="value text-expense">NT$ {{ numberFormat(totalExpense, 2) }}</span>
            </div>
          </div>
          <div id="chart-container">
              <div v-if="(currentChartType === 'expense' && totalExpense <= 0) || (currentChartType === 'income' && totalIncome <= 0)" class="no-data-msg">本月尚無紀錄</div>
              <canvas v-else ref="expenseChartCanvas"></canvas>
          </div>
      </div>
    </div>

    <div class="card-section">
      <div class="section-header"><h2>歷史分類趨勢</h2></div>
      <div class="data-box chart-card">
        
        <div class="trend-controls mb-4">
            <div class="trend-type-toggle">
                <button 
                    class="toggle-btn" 
                    :class="{ active: trendChartType === 'expense' }" 
                    @click="changeTrendType('expense')">支出</button>
                <button 
                    class="toggle-btn" 
                    :class="{ active: trendChartType === 'income' }" 
                    @click="changeTrendType('income')">收入</button>
            </div>

            <div class="date-range-inputs">
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

    <div class="card-section">
      <div class="section-header"><h2>近期收支明細</h2></div>
      <div class="data-box tx-list-wrapper"> 
          <div class="list-controls-row">
            <div class="search-wrapper">
              <div class="filter-scroll-view">
                <button class="filter-chip add-btn-chip" @click="openAddModal">
                  新增
                </button>
                <button 
                  class="filter-chip" 
                  :class="{ active: searchQuery === '' }" 
                  @click="searchQuery = ''"
                >
                  全部
                </button>

                <button 
                  class="filter-chip" 
                  :class="{ active: searchQuery === 'TYPE_EXPENSE' }" 
                  @click="searchQuery = 'TYPE_EXPENSE'"
                >
                  支出
                </button>
                <button 
                  class="filter-chip" 
                  :class="{ active: searchQuery === 'TYPE_INCOME' }" 
                  @click="searchQuery = 'TYPE_INCOME'"
                >
                  收入
                </button>

                <div class="divider-vertical"></div>

                <button 
                  v-for="item in dynamicFilterCategories" 
                  :key="item.key" 
                  class="filter-chip"
                  :class="{ active: searchQuery === item.key }"
                  @click="searchQuery = item.key"
                >
                  {{ item.name }}
                </button>
              </div>
              <div v-if="isAddModalOpen" class="modal-overlay" @click.self="isAddModalOpen = false">
                <div class="modal-content">
                  <div class="modal-header">
                    <h3>新增紀錄 / 類別</h3>
                    <button class="close-btn" @click="isAddModalOpen = false">×</button>
                  </div>
                  
                  <form @submit.prevent="handleTransactionSubmit">
                      <div class="form-group type-select">
                          <div class="radio-group">
                              <label class="radio-label" :class="{ active: transactionForm.type === 'expense' }">
                                <input type="radio" v-model="transactionForm.type" value="expense">支出
                              </label>
                              <label class="radio-label" :class="{ active: transactionForm.type === 'income' }">
                                <input type="radio" v-model="transactionForm.type" value="income">收入
                              </label>
                          </div>
                      </div>

                      <div class="form-row">
                          <input type="number" v-model.number="transactionForm.amount" placeholder="金額" required class="input-std half" step="0.01">
                          <input type="text" v-model="transactionForm.currency" placeholder="幣種" class="input-std half" required>
                      </div>

                      <div class="form-group mt-2">
                          <input type="date" v-model="transactionForm.date" required class="input-std">
                      </div>

                      <div class="form-group">
                          <input type="text" v-model="transactionForm.description" placeholder="項目說明 (例如：第一筆創業基金)" required class="input-std">
                      </div>

                      <div class="form-group">
                          <label class="sub-label">分類</label>
                          <select v-model="selectCategoryMode" class="input-std" @change="handleCategoryChange">
                              <option v-for="(name, key) in categoryMap" :key="key" :value="key">{{ name }}</option>
                              <option value="CUSTOM_INPUT">➕ 自訂新類別...</option>
                          </select>
                      </div>
                      
                      <div class="form-group" v-if="isCustomCategory">
                          <input 
                            type="text" 
                            v-model="transactionForm.category" 
                            placeholder="請輸入新類別名稱 (例如: 創業)" 
                            class="input-std highlight-input"
                            required
                          >
                      </div>

                      <button type="submit" class="save-btn">新增確認</button>
                  </form>
                </div>
              </div>
            </div>
            
            <div class="controls-right">
               <div class="view-toggle">
                  <button @click="viewMode = 'list'" :class="['toggle-btn', viewMode==='list'?'active':'']">列表</button>
                  <button @click="viewMode = 'calendar'" :class="['toggle-btn', viewMode==='calendar'?'active':'']">日曆</button>
               </div>
               <div class="month-selector-group">
                <button class="month-btn prev" @click="shiftMonth(-1)">
                  &lsaquo;
                </button>
                
                <div class="month-display-wrapper">
                  <input 
                    type="month" 
                    v-model="currentListMonth" 
                    class="month-input-hidden"
                  >
                  <span class="month-label">{{ displayMonthText }}</span>
                </div>

                <button class="month-btn next" @click="shiftMonth(1)">
                  &rsaquo;
                </button>
              </div>
            </div>
          </div>

          <div v-if="txLoading" class="loading-box"><span class="loader"></span> 載入中...</div>
          
          <div v-else-if="filteredTransactions.length === 0" class="empty-msg">
             {{ transactions.length === 0 ? '本月尚無紀錄' : '查無符合搜尋條件的紀錄' }}
          </div>

          <div v-else-if="viewMode === 'list'" class="tx-grouped-list">
              <div v-for="dateGroup in groupedFilteredTransactions" :key="dateGroup.date" class="tx-date-group">
                  <div class="date-header">{{ dateGroup.displayDate }} {{ dateGroup.weekday }}</div>
                  <div v-for="catGroup in dateGroup.categories" :key="catGroup.categoryKey" class="tx-category-group">
                      <div class="category-subheader" :class="catGroup.items[0].type">{{ catGroup.categoryName }}</div>
                      <div v-for="tx in catGroup.items" :key="tx.id" class="tx-item-grouped">
                          <div class="tx-mid-grouped">
                            <div class="tx-desc" v-html="highlightTags(tx.description)"></div>
                          </div>
                          <div class="tx-right-grouped">
                              <div class="tx-amount" :class="tx.type === 'income' ? 'text-income' : 'text-expense'">
                                  {{ tx.type === 'income' ? '+' : '-' }} {{ numberFormat(tx.amount, 0) }}
                              </div>
                              <div class="tx-actions">
                                  <button class="text-btn edit" @click="openEditModal(tx)">編輯</button>
                                  <button class="text-btn delete" @click="handleDeleteTx(tx.id)">刪除</button>
                              </div>
                          </div>
                      </div>
                  </div>
              </div>
          </div>

          <div v-else class="calendar-grid">
            <div class="calendar-header-row">
              <div>日</div><div>一</div><div>二</div><div>三</div><div>四</div><div>五</div><div>六</div>
            </div>
            <div class="calendar-body">
              <div v-for="(cell, idx) in calendarDays" :key="idx" 
                   class="calendar-cell"
                   :class="{'empty': cell.empty, 'has-tx': !cell.empty && (cell.expense > 0 || cell.income > 0)}"
                   @click="!cell.empty && setSearchDate(cell.date)"
              >
                <span v-if="!cell.empty" class="cell-day">{{ cell.day }}</span>
                <div v-if="!cell.empty" class="cell-dots">
                  <span v-if="cell.expense > 0" class="dot-expense">-{{ formatCompactNumber(cell.expense) }}</span>
                  <span v-if="cell.income > 0" class="dot-income">+{{ formatCompactNumber(cell.income) }}</span>
                </div>
              </div>
            </div>
          </div>

      </div>
    </div>

    <div v-if="isEditModalOpen" class="modal-overlay" @click.self="closeModal">
      <div class="modal-content">
        <div class="modal-header"><h3>編輯紀錄</h3><button class="close-btn" @click="closeModal">×</button></div>
        <form @submit.prevent="handleUpdateTx">
            <div class="form-group type-select">
                <div class="radio-group">
                    <label class="radio-label" :class="{ active: editForm.type === 'expense' }"><input type="radio" v-model="editForm.type" value="expense">支出</label>
                    <label class="radio-label" :class="{ active: editForm.type === 'income' }"><input type="radio" v-model="editForm.type" value="income">收入</label>
                </div>
            </div>
            <div class="form-row">
                <input type="number" v-model.number="editForm.amount" required class="input-std half">
                <input type="text" v-model="editForm.currency" class="input-std half" required>
            </div>
            <div class="form-group mt-2">
                <input type="date" v-model="editForm.date" required class="input-std">
            </div>
            <div class="form-group">
                <input type="text" v-model="editForm.description" required class="input-std">
            </div>
            <div class="form-group">
                <div class="form-group">
                  <div class="form-group">
                      <label class="sub-label">分類</label>
                      <select v-model="editSelectCategoryMode" class="input-std" @change="handleEditCategoryChange">
                          <option v-for="item in dynamicFilterCategories" :key="item.key" :value="item.key">
                              {{ item.name }}
                          </option>
                          
                          <option disabled>──────────</option>

                          <option value="CUSTOM_INPUT">➕ 自訂新類別...</option>
                      </select>
                  </div>
                  
                  <div class="form-group" v-if="isEditCustomCategory">
                      <input 
                          type="text" 
                          v-model="editForm.category" 
                          placeholder="請輸入類別名稱" 
                          class="input-std highlight-input"
                          required
                      >
                  </div>
                </div>
                
                <div class="form-group" v-if="isEditCustomCategory">
                    <input 
                        type="text" 
                        v-model="editForm.category" 
                        placeholder="請輸入類別名稱" 
                        class="input-std highlight-input"
                        required
                    >
                </div>
            </div>
            <button type="submit" class="save-btn">儲存修改</button>
        </form>
      </div>
    </div>

    <div v-if="isPaymentModalOpen" class="modal-overlay" @click.self="isPaymentModalOpen = false">
      <div class="modal-content payment-modal">
        <div class="modal-header"><h3>綁定 Email</h3><button class="close-btn" @click="isPaymentModalOpen = false">×</button></div>
        <div class="modal-body">
            <p class="text-sm text-gray-600 mb-4">請輸入您付款時使用的 <strong>Email</strong>，系統將依此自動開通權限。</p>
            <input type="email" v-model="paymentEmail" placeholder="name@example.com" class="input-std mb-4">
            <button class="save-btn" @click="handleLinkAndPay" :disabled="isLinking">{{ isLinking ? '處理中...' : '綁定並前往付款' }}</button>
        </div>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, onMounted, nextTick, watch, computed } from 'vue';
import { fetchWithLiffToken, numberFormat } from '@/utils/api';
import Chart from 'chart.js/auto'; 
import ChartDataLabels from 'chartjs-plugin-datalabels';
import liff from '@line/liff';
Chart.register(ChartDataLabels);

// [新增] 1. 定義 props 接收 ledgerId
const props = defineProps(['ledgerId']);

// [新增] 2. 監聽 ledgerId 變化，自動刷新頁面數據
watch(() => props.ledgerId, (newVal) => {
    refreshAllData();
});

// --- 狀態管理 ---
const isPremium = ref(false); 
const totalExpense = ref(0);
const totalIncome = ref(0);
const expenseBreakdown = ref({});
const incomeBreakdown = ref({});
const currentChartType = ref('expense'); 
const expenseChartCanvas = ref(null);
let chartInstance = null;

// [新增] 預算與搜尋狀態
const userBudget = ref(0);
const searchQuery = ref('');
const viewMode = ref('list'); // 'list' or 'calendar'

const trendFilter = ref({
    // 🟢 修改：將 setFullYear 改為 setMonth，並減去 3
    start: new Date(new Date().setMonth(new Date().getMonth() - 3)).toISOString().substring(0, 10),
    
    end: new Date().toISOString().substring(0, 10)
});
const trendChartCanvas = ref(null);
let trendChart = null;

const formMessage = ref('');
const messageClass = ref('');
const transactionForm = ref({
  type: 'expense', amount: null, date: new Date().toISOString().substring(0, 10),
  description: '', category: 'Miscellaneous', currency: 'TWD',
});

const currencySelectValue = ref('TWD');
const isCustomCurrency = ref(false);

const transactions = ref([]);
const txLoading = ref(false);
const currentListMonth = ref(new Date().toISOString().substring(0, 7)); 

const isEditModalOpen = ref(false);
const editForm = ref({}); 

const isPaymentModalOpen = ref(false);
const isLinking = ref(false);
const paymentEmail = ref('');
const selectedPaymentMethod = ref('bmc'); 

const BMC_URL = 'https://buymeacoffee.com/finbot'; 
const NOWPAYMENTS_URL = 'https://nowpayments.io/donation/finbot2'; 

const fileInput = ref(null);
const isAnalyzing = ref(false);

const trendChartType = ref('expense'); // 預設看支出
const trendRawData = ref({});

const categoryMap = {
  'Food': '飲食', 'Transport': '交通', 'Entertainment': '娛樂', 'Shopping': '購物',
  'Bills': '帳單', 'Investment': '投資', 'Medical': '醫療', 'Education': '教育',
  'Miscellaneous': '其他', 'Salary': '薪水', 'Allowance': '津貼', 'Bonus': '獎金',
};
const palette = ['#D4A373', '#FAEDCD', '#CCD5AE', '#E9EDC9', '#A98467', '#ADC178', '#6C584C', '#B5838D', '#E5989B', '#FFB4A2'];

const isAddModalOpen = ref(false);

const selectCategoryMode = ref('Miscellaneous'); 
const isCustomCategory = ref(false);

// 🟢 [新增] 編輯視窗專用的自訂類別狀態
const editSelectCategoryMode = ref('Miscellaneous');
const isEditCustomCategory = ref(false);

function handleEditCategoryChange() {
  if (editSelectCategoryMode.value === 'CUSTOM_INPUT') {
    isEditCustomCategory.value = true;
    editForm.value.category = ''; // 清空讓用戶輸入
  } else {
    isEditCustomCategory.value = false;
    editForm.value.category = editSelectCategoryMode.value;
  }
}

// 🔴 請補上這一段 (處理下拉選單切換的函式)
function handleCategoryChange() {
  if (selectCategoryMode.value === 'CUSTOM_INPUT') {
    // 切換到自訂模式：顯示輸入框，並清空原本的值讓用戶輸入
    isCustomCategory.value = true;
    transactionForm.value.category = ''; 
  } else {
    // 切換回預設模式：隱藏輸入框，並將選單的值填入 Form
    isCustomCategory.value = false;
    transactionForm.value.category = selectCategoryMode.value;
  }
}

// 🟢 [新增] 開啟新增視窗的函式
function openAddModal() {
  // 1. 重置表單為預設值
  transactionForm.value = {
    type: 'expense',
    amount: '', // 留空讓使用者填
    date: new Date().toISOString().substring(0, 10), // 今天
    description: '',
    category: 'Miscellaneous',
    currency: 'TWD'
  };
  
  // 2. 重置類別選單狀態
  selectCategoryMode.value = 'Miscellaneous';
  isCustomCategory.value = false;
  
  // 3. 開啟視窗
  isAddModalOpen.value = true;
}

const dynamicFilterCategories = computed(() => {
  // 1. 先放入所有的預設固定類別
  const list = Object.keys(categoryMap).map(key => ({
    key: key,
    name: categoryMap[key]
  }));
  
  // 2. 掃描目前的交易紀錄，找出「自訂類別」
  const existingKeys = new Set(Object.keys(categoryMap));
  const customKeys = new Set();
  
  transactions.value.forEach(tx => {
    // 如果這筆交易的 category 不在預設清單內，就是自訂的
    if (tx.category && !existingKeys.has(tx.category)) {
      customKeys.add(tx.category);
    }
  });

  // 3. 將找到的自訂類別加入清單
  customKeys.forEach(cat => {
    list.push({ key: cat, name: cat }); // 自訂類別的顯示名稱就是它自己
  });

  return list;
});

// --- [新增] 計算屬性區 (Budget, Filter, Calendar) ---

// 1. 預算計算
const budgetPercent = computed(() => {
  if (userBudget.value <= 0) return 0;
  return Math.round((totalExpense.value / userBudget.value) * 100);
});

const budgetStatusColor = computed(() => {
  if (budgetPercent.value >= 100) return 'text-danger';
  if (budgetPercent.value >= 80) return 'text-warning';
  return 'text-success';
});

const budgetBarColor = computed(() => {
  if (budgetPercent.value >= 100) return 'bg-danger';
  if (budgetPercent.value >= 80) return 'bg-warning';
  return 'bg-success';
});

const filteredTransactions = computed(() => {
  const query = searchQuery.value;
  
  // 1. 如果沒選 (或是選全部)，回傳所有資料
  if (!query) return transactions.value;
  
  // 2. 篩選「僅顯示支出」
  if (query === 'TYPE_EXPENSE') {
    return transactions.value.filter(tx => tx.type === 'expense');
  }
  
  // 3. 篩選「僅顯示收入」
  if (query === 'TYPE_INCOME') {
    return transactions.value.filter(tx => tx.type === 'income');
  }

  // 4. 篩選「特定分類」 (例如：Food, Transport...)
  return transactions.value.filter(tx => tx.category === query);
});

// 3. 分組邏輯 (使用 filteredTransactions)
const groupedFilteredTransactions = computed(() => {
    if (filteredTransactions.value.length === 0) return [];
    const dateGroupMap = new Map();
    const weekdayNames = ['日', '一', '二', '三', '四', '五', '六'];
    
    filteredTransactions.value.forEach(tx => {
        const date = tx.transaction_date;
        const categoryKey = tx.category;
        
        if (!dateGroupMap.has(date)) {
            const dateObj = new Date(date);
            dateGroupMap.set(date, {
                categories: new Map(), 
                displayDate: date.substring(5),
                weekday: `(${weekdayNames[dateObj.getDay()]})`
            });
        }
        const dateGroup = dateGroupMap.get(date);
        
        if (!dateGroup.categories.has(categoryKey)) {
            dateGroup.categories.set(categoryKey, {
                categoryName: categoryMap[categoryKey] || categoryKey,
                categoryKey: categoryKey,
                items: []
            });
        }
        dateGroup.categories.get(categoryKey).items.push(tx);
    });

    const result = Array.from(dateGroupMap, ([date, data]) => ({
        date: date,
        displayDate: data.displayDate,
        weekday: data.weekday,
        categories: Array.from(data.categories.values())
    }));
    
    return result.sort((a, b) => new Date(b.date) - new Date(a.date));
});

// 4. 日曆數據生成
const calendarDays = computed(() => {
  const [year, month] = currentListMonth.value.split('-').map(Number);
  const daysInMonth = new Date(year, month, 0).getDate();
  const firstDayOfWeek = new Date(year, month - 1, 1).getDay(); // 0 (Sun) - 6 (Sat)
  
  const days = [];
  
  // 填補前面的空白
  for (let i = 0; i < firstDayOfWeek; i++) {
    days.push({ empty: true });
  }
  
  // 填入日期
  for (let d = 1; d <= daysInMonth; d++) {
    const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
    
    // 計算當日總額
    let dailyExpense = 0;
    let dailyIncome = 0;
    
    // 這裡使用原始 transactions 還是 filtered 看需求，通常日曆顯示全貌比較好，但如果想篩選特定項目看分佈，用 filtered
    const targetTx = searchQuery.value ? filteredTransactions.value : transactions.value;

    targetTx.forEach(tx => {
      if (tx.transaction_date === dateStr) {
        if (tx.type === 'expense') dailyExpense += parseFloat(tx.amount);
        else dailyIncome += parseFloat(tx.amount);
      }
    });

    days.push({
      empty: false,
      day: d,
      date: dateStr,
      expense: dailyExpense,
      income: dailyIncome
    });
  }
  return days;
});

const displayMonthText = computed(() => {
  if (!currentListMonth.value) return '';
  const [y, m] = currentListMonth.value.split('-');
  return `${y}年 ${m}月`;
});

// ★ 新增：切換月份函式 (-1 為上個月, 1 為下個月)
function shiftMonth(delta) {
  const [year, month] = currentListMonth.value.split('-').map(Number);
  
  // 計算新日期 (設為 1 號避免大小月問題)
  const date = new Date(year, month - 1 + delta, 1);
  
  // 轉回 YYYY-MM 格式
  const newY = date.getFullYear();
  const newM = String(date.getMonth() + 1).padStart(2, '0');
  
  currentListMonth.value = `${newY}-${newM}`;
  // 這裡不需要手動 call fetchTransactions，因為已經有 watch(currentListMonth) 了
}

// --- 方法 ---

function changeTrendType(type) {
    trendChartType.value = type;
    if (Object.keys(trendRawData.value).length > 0) {
        renderTrendChart(trendRawData.value);
    }
}


// 🟢 [新增] 觸發選擇檔案
function triggerFileInput() {
  if (isAnalyzing.value) return;
  fileInput.value.click();
}

// 🟢 [新增] 處理檔案上傳 (Mode: general)
async function handleFileChange(event) {
  const file = event.target.files[0];
  if (!file) return;

  // 簡單檢查大小 (10MB)
  if (file.size > 10 * 1024 * 1024) {
    alert('檔案過大，請上傳 10MB 以下的檔案');
    return;
  }

  isAnalyzing.value = true;

  try {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('mode', 'general'); // ★ 指定為一般記帳模式
    
    if (props.ledgerId) {
      formData.append('ledger_id', props.ledgerId);
    }

    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=analyze_file`, {
      method: 'POST',
      body: formData
    });

    if (response && response.ok) {
      const result = await response.json();
      if (result.status === 'success') {
        alert(result.message);
        refreshAllData(); // ★ 成功後刷新介面顯示新交易
      } else {
        alert('辨識失敗：' + result.message);
      }
    } else {
      alert('上傳失敗');
    }
  } catch (e) {
    console.error(e);
    alert('發生錯誤，請稍後再試');
  } finally {
    isAnalyzing.value = false;
    if (fileInput.value) fileInput.value.value = ''; // 清空 input
  }
}

function formatCompactNumber(num) {
  if (num >= 10000) return (num / 10000).toFixed(1) + 'w';
  if (num >= 1000) return (num / 1000).toFixed(1) + 'k';
  return Math.round(num);
}

// 點擊日曆日期篩選
function setSearchDate(dateStr) {
  // 將搜尋框設為該日期，觸發 filteredTransactions
  // 這裡我們需要調整搜尋邏輯以支援日期，或者簡單地：
  // 這裡為了簡單，我們不改 searchQuery，而是切換回列表並只顯示那天？
  // 更好的做法：搜尋框如果是空的，點擊日曆不動作或跳出當日明細 Modal。
  // 這裡實作：將日期填入搜尋框 (搜尋邏輯需支援日期字串匹配) -> filteredTransactions 已支援 text include，所以日期字串也會被匹配到
  searchQuery.value = dateStr;
  viewMode.value = 'list';
}

// 高亮標籤
function highlightTags(text) {
  if (!text) return '';
  // 將 #tag 替換為帶顏色的 span
  return text.replace(/(#[^\s]+)/g, '<span class="tag-highlight">$1</span>');
}

// [新增] 獲取用戶設定 (預算)
async function fetchUserStatus() {
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=get_user_status`);
    if (response && response.ok) {
        const res = await response.json();
        if (res.status === 'success') {
            userBudget.value = parseFloat(res.data.monthly_budget) || 0;
        }
    }
}

// [新增] 檢查週期性帳單 (觸發後端處理)
async function checkRecurring() {
    // 默默呼叫，不阻擋 UI
    fetchWithLiffToken(`${window.API_BASE_URL}?action=check_recurring`).catch(e => console.log('Recurring check skip'));
}

function handleCurrencyChange() {
    if (currencySelectValue.value === 'CUSTOM') {
        isCustomCurrency.value = true; transactionForm.value.currency = ''; 
    } else {
        isCustomCurrency.value = false; transactionForm.value.currency = currencySelectValue.value;
    }
}
function resetCurrency() {
    isCustomCurrency.value = false; currencySelectValue.value = 'TWD'; transactionForm.value.currency = 'TWD';
}
function forceUppercase() { transactionForm.value.currency = transactionForm.value.currency.toUpperCase(); }

function openPaymentModal(method) {
    selectedPaymentMethod.value = method;
    isPaymentModalOpen.value = true;
}

// [修正] 3. 獲取資產總覽時帶上 ledger_id
async function fetchAssetSummary() {
    let url = `${window.API_BASE_URL}?action=asset_summary`;
    if (props.ledgerId) url += `&ledger_id=${props.ledgerId}`;

    const response = await fetchWithLiffToken(url);
    if (response && response.ok) {
        const result = await response.json();
        if (result.status === 'success') {
            isPremium.value = result.data.is_premium || false;
        }
    }
}

// [修正] 4. 獲取交易列表時帶上 ledger_id
async function fetchTransactions() {
    if (transactions.value.length === 0) {
        txLoading.value = true;
    }
    const monthToSend = currentListMonth.value.substring(0, 7); 
    
    let url = `${window.API_BASE_URL}?action=get_transactions&month=${monthToSend}`;
    if (props.ledgerId) url += `&ledger_id=${props.ledgerId}`;

    const response = await fetchWithLiffToken(url);
    
    if (response && response.ok) {
        const result = await response.json();
        if (result.status === 'success') {
            transactions.value = result.data;
        }
    }
    txLoading.value = false;
}

async function handleDeleteTx(id) {
    if (!confirm("確定要刪除這筆紀錄嗎？")) return;
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=delete_transaction`, {
        method: 'POST', body: JSON.stringify({ id })
    });
    if (response && (await response.json()).status === 'success') {
        refreshAllData();
    }
}

function openEditModal(tx) {
    editForm.value = { 
        ...tx, 
        amount: parseFloat(tx.amount),
        date: tx.transaction_date // ★ 加上這行，日期就會自動帶入了
    };

    // 檢查這個類別是否已經在我們的「動態清單」中
    // (dynamicFilterCategories 包含預設類別 + 歷史紀錄中出現過的自訂類別)
    const isKnownCategory = dynamicFilterCategories.value.some(item => item.key === tx.category);

    if (isKnownCategory) {
        // 情況 A：是已知類別 (包含已存在的自訂類別，如 "公關費")
        // -> 直接在選單中選中它，不顯示輸入框
        editSelectCategoryMode.value = tx.category;
        isEditCustomCategory.value = false;
    } else {
        // 情況 B：完全未知的類別 (極少見，除非是剛匯入的資料)
        // -> 切換到自訂輸入模式
        editSelectCategoryMode.value = 'CUSTOM_INPUT';
        isEditCustomCategory.value = true;
    }

    isEditModalOpen.value = true;
}

function closeModal() { isEditModalOpen.value = false; }

async function handleUpdateTx() {
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=update_transaction`, {
        method: 'POST', body: JSON.stringify(editForm.value)
    });
    if (response && (await response.json()).status === 'success') {
        closeModal(); refreshAllData();
        alert("更新成功");
    } else { alert("更新失敗"); }
}

function refreshAllData() {
    fetchAssetSummary(); 
    fetchExpenseData();
    fetchTrendData();
    fetchTransactions(); 
    fetchUserStatus(); // 載入預算
}

watch(currentListMonth, (newMonth) => { 
    transactions.value = [];
    fetchTransactions(); 
});

// [修正] 5. 獲取圓餅圖數據時帶上 ledger_id
async function fetchExpenseData() {
    // 切換時先重置，避免混淆
    totalExpense.value = 0;
    totalIncome.value = 0;
    expenseBreakdown.value = {};
    incomeBreakdown.value = {};
    
    if (chartInstance) {
        chartInstance.destroy();
        chartInstance = null;
    }

    let url = `${window.API_BASE_URL}?action=monthly_expense_breakdown`;
    if (props.ledgerId) url += `&ledger_id=${props.ledgerId}`;

    const response = await fetchWithLiffToken(url);
    if (response && response.ok) {
        const result = await response.json();
        if (result.status === 'success') {
            totalExpense.value = result.data.total_expense;
            totalIncome.value = result.data.total_income || 0;
            expenseBreakdown.value = result.data.breakdown || {};
            incomeBreakdown.value = result.data.income_breakdown || {};
            await nextTick(); renderChart();
        }
    }
}

async function fetchTrendData() {
  const { start, end } = trendFilter.value;
  // 這裡維持使用 category 模式抓取資料
  let url = `${window.API_BASE_URL}?action=trend_data&start=${start}&end=${end}&mode=category`;
  if (props.ledgerId) url += `&ledger_id=${props.ledgerId}`;

  const response = await fetchWithLiffToken(url);
  if (response && response.ok) {
      const result = await response.json();
      if (result.status === 'success') {
          trendRawData.value = result.data; // ★ 存入暫存
          renderTrendChart(result.data);
      }
  }
}

function toggleChart(type) { currentChartType.value = type; nextTick(() => { renderChart(); }); }
function renderChart() {
  if (chartInstance) chartInstance.destroy();
  
  const sourceData = currentChartType.value === 'expense' ? expenseBreakdown.value : incomeBreakdown.value;
  const rawLabels = Object.keys(sourceData);
  
  // 檢查是否有資料
  if (rawLabels.length === 0) return;

  const labels = rawLabels.map(key => categoryMap[key] || key);
  const dataValues = Object.values(sourceData).map(v => parseFloat(v));
  
  if (!expenseChartCanvas.value) return;

  chartInstance = new Chart(expenseChartCanvas.value, {
    type: 'bar', // ★ 改為柱狀圖
    data: { 
        labels: labels, 
        datasets: [{ 
            data: dataValues, 
            backgroundColor: palette, // 維持原本的配色，每根柱子不同色
            borderRadius: 8,          // 圓角設計，比較好看
            borderSkipped: false,
            barPercentage: 0.6,       // 控制柱子粗細
        }] 
    },
    options: { 
        responsive: true,
        maintainAspectRatio: false,
        plugins: { 
            legend: { display: false }, // 柱狀圖不需要圖例 (X軸已有標籤)
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return 'NT$ ' + numberFormat(context.parsed.y, 0);
                    }
                }
            },
            datalabels: { 
                anchor: 'end', 
                align: 'top', 
                formatter: (value) => formatCompactNumber(value), // 顯示簡寫 (如 1.5k)
                color: '#888',
                font: { size: 11, weight: 'bold' },
                offset: 2
            } 
        },
        scales: {
            y: { 
                beginAtZero: true, 
                grid: { color: '#f0f0f0', drawBorder: false },
                ticks: { 
                    callback: (val) => formatCompactNumber(val),
                    font: { size: 10 },
                    color: '#aaa'
                },
                border: { display: false } // 隱藏 Y 軸線
            },
            x: { 
                grid: { display: false }, // 隱藏 X 軸網格
                ticks: { 
                    color: '#666',
                    font: { size: 11 }
                },
                border: { display: false }
            }
        },
        layout: {
            padding: { top: 20 } // 預留頂部空間給標籤，避免被切掉
        }
    }
  });
}

function renderTrendChart(data) {
    if (trendChart) trendChart.destroy();
    if (!trendChartCanvas.value) return;

    const months = Object.keys(data).sort(); // 所有月份
    if (months.length === 0) return;

    // 1. 整理所有出現過的分類，並計算總額 (用來抓出 Top 5)
    const categoryTotals = {};

    months.forEach(month => {
        const monthData = data[month]; // { Food: 100, Transport: 50... }
        Object.keys(monthData).forEach(catKey => {
            const amount = parseFloat(monthData[catKey] || 0);

            // 這裡要過濾：只計算當前選擇類型 (支出/收入) 的分類
            // 由於後端回傳的是 category name (例如 'Food'), 我們需要知道它是支出還是收入
            // 簡單做法：假設所有分類預設都是支出，除非特別標記。
            // 更好的做法：依賴前端 categoryMap 來判斷，或是後端回傳時多帶 type。
            // 這裡我們用一個簡單的邏輯：金額 > 0 的通常都算，但為了精準，
            // 您可能需要在 categoryMap 裡標記哪些是 income (如 Salary, Bonus, Allowance)。

            const incomeCategories = ['Salary', 'Allowance', 'Bonus', 'Investment'];
            const isIncomeCat = incomeCategories.includes(catKey);

            if (trendChartType.value === 'expense' && isIncomeCat) return;
            if (trendChartType.value === 'income' && !isIncomeCat) return;

            if (!categoryTotals[catKey]) categoryTotals[catKey] = 0;
            categoryTotals[catKey] += amount;
        });
    });

    // 2. 找出前 5 大分類
    const sortedCats = Object.keys(categoryTotals).sort((a, b) => categoryTotals[b] - categoryTotals[a]);
    const topCats = sortedCats.slice(0, 5); // 取前 5 名
    const hasOthers = sortedCats.length > 5;

    // 3. 建構 Datasets
    const datasets = topCats.map((catKey, index) => {
        return {
            label: categoryMap[catKey] || catKey,
            data: months.map(m => {
                // 同樣過濾 income/expense
                const val = data[m][catKey] || 0;
                return val;
            }),
            backgroundColor: palette[index % palette.length],
            stack: 'Stack 0', // 設定堆疊
        };
    });

    // 處理「其他」
    if (hasOthers) {
        datasets.push({
            label: '其他',
            data: months.map(m => {
                let otherSum = 0;
                Object.keys(data[m]).forEach(catKey => {
                    const incomeCategories = ['Salary', 'Allowance', 'Bonus', 'Investment'];
                    const isIncomeCat = incomeCategories.includes(catKey);
                    if (trendChartType.value === 'expense' && isIncomeCat) return;
                    if (trendChartType.value === 'income' && !isIncomeCat) return;

                    if (!topCats.includes(catKey)) {
                        otherSum += parseFloat(data[m][catKey] || 0);
                    }
                });
                return otherSum;
            }),
            backgroundColor: '#dcdcdc', // 灰色
            stack: 'Stack 0',
        });
    }

    // 4. 繪圖
    trendChart = new Chart(trendChartCanvas.value, {
        type: 'bar', // 改為長條圖
        data: { labels: months, datasets: datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false }, // 滑鼠移上去顯示當月所有數據
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) label += ': ';
                            if (context.parsed.y !== null) {
                                label += 'NT$ ' + numberFormat(context.parsed.y, 0);
                            }
                            return label;
                        }
                    }
                },
                datalabels: { display: false } // 趨勢圖通常不顯示詳細數字以免太亂
            },
            scales: {
                x: { stacked: true, grid: { display: false } }, // X 軸堆疊
                y: { 
                    stacked: true, // Y 軸堆疊
                    beginAtZero: true, 
                    grid: { color: '#f0f0f0' },
                    ticks: { callback: (val) => formatCompactNumber(val) } 
                }
            }
        }
    });
}

// [修正] 7. 新增記帳時，帶入 ledger_id
async function handleTransactionSubmit() {
  if (!liff.isLoggedIn()) {
      liff.login({ redirectUri: window.location.href });
      return;
  }

  // 1. 準備 Payload
  const payload = { ...transactionForm.value };
  
  // 如果有選擇帳本，就帶入 ID
  if (props.ledgerId) {
      payload.ledger_id = props.ledgerId;
  }

  try {
      const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=add_transaction`, {
        method: 'POST', body: JSON.stringify(payload)
      });
      
      // 先解析 JSON，方便後面取用 message
      const result = await response.json(); 

      if (response && result.status === 'success') {
          // 🟢 2. 成功後的處理
          
          // (A) 刷新頁面所有數據 (包含篩選列的新類別、交易列表)
          refreshAllData(); 
          
          // (B) 重置表單 (為了下次打開時是乾淨的)
          transactionForm.value = {
            type: 'expense', 
            amount: '', 
            date: new Date().toISOString().substring(0, 10),
            description: '', 
            category: 'Miscellaneous', 
            currency: 'TWD'
          };
          
          // (C) [關鍵修改] 關閉新增視窗
          isAddModalOpen.value = false;
          
          // (D) 顯示成功提示
          alert("新增成功！");
          
      } else {
          // 🔴 3. 失敗處理
          alert("新增失敗：" + (result.message || '未知錯誤'));
      }
  } catch (e) {
      console.error(e);
      alert("發生網路錯誤，請稍後再試。");
  }
}

async function handleFileUpload(event) {
  const file = event.target.files[0];
  if (!file) return;

  const formData = new FormData();
  formData.append('file', file);
  formData.append('mode', 'general'); // 🟢 明確指定模式
  
  if (props.ledgerId) formData.append('ledger_id', props.ledgerId);

  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=analyze_file`, {
      method: 'POST',
      body: formData
  });
  
  if (response && response.ok) {
        const result = await response.json();
        if (result.status === 'success') {
            alert(result.message);
            
            // 🟢 [重要] 務必呼叫這行，讓畫面更新，顯示剛記進去的帳
            refreshAllData(); 
            
        } else {
            alert('辨識失敗：' + result.message);
        }
    }

}

async function handleLinkAndPay() {
    if (!paymentEmail.value) { alert('請輸入 Email'); return; }
    
    isLinking.value = true;
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=link_bmc`, {
        method: 'POST',
        body: JSON.stringify({ email: paymentEmail.value })
    });
    
    if (response && response.ok) {
        const result = await response.json();
        if (result.status === 'success') {
            if (selectedPaymentMethod.value === 'crypto') {
                try {
                    const orderResponse = await fetchWithLiffToken(`${window.API_BASE_URL}?action=create_crypto_order`, {
                        method: 'POST',
                        body: JSON.stringify({ email: paymentEmail.value })
                    });
                    const orderResult = await orderResponse.json();
                    if (orderResult.status === 'success') {
                        isPaymentModalOpen.value = false;
                        window.open(orderResult.data.invoice_url, '_blank');
                        alert('已為您建立專屬訂單！\n請在跳出的頁面完成支付，系統確認後將自動開通權限。');
                    } else {
                        alert('建立訂單失敗：' + (orderResult.message || '未知錯誤'));
                    }
                } catch (e) {
                    console.error(e);
                    alert('建立訂單時發生網路錯誤，請稍後再試。');
                }
            } else {
                isPaymentModalOpen.value = false;
                window.open(BMC_URL, '_blank');
                alert('已跳轉至付款頁面，請務必填寫相同的 Email 以便系統自動開通！');
            }
        } else {
            alert(result.message);
        }
    } else {
        alert('API 連線失敗');
    }
    isLinking.value = false;
}

defineExpose({ refreshAllData });

onMounted(() => {
    refreshAllData();
    // [新增] 週期性帳單檢查
    checkRecurring();
});
</script>

<style scoped>
/* 樣式保持原樣 */
.dashboard-container { width: 100%; max-width: 100%; margin: 0 auto; color: #5d5d5d; font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; letter-spacing: 0.03em; overflow-x: hidden; padding-bottom: 30px; }
.card-section { margin-bottom: 20px; }
.section-header h2 { font-size: 1.1rem; font-weight: 600; color: #8c7b75; margin-bottom: 12px; margin-left: 4px; }
.data-box { background-color: #ffffff; border-radius: 16px; padding: 16px; box-shadow: 0 4px 20px rgba(220, 210, 200, 0.3); border: 1px solid #f0ebe5; }
.premium-box { background: linear-gradient(135deg, #fff8f0 0%, #fff 100%); border: 1px solid #eeddcc; position: relative; overflow: hidden; }
.premium-content { position: relative; z-index: 1; }
.premium-header { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
.premium-title { font-size: 1.1rem; font-weight: bold; color: #b45309; margin: 0; }
.premium-badge { font-size: 0.7rem; background: #b45309; color: white; padding: 2px 6px; border-radius: 4px; font-weight: bold; }
.premium-price { font-size: 1rem; color: #555; margin-bottom: 12px; font-weight: 500; }
.price-tag { color: #d97706; font-weight: bold; font-size: 1.1rem; }
.premium-desc { font-size: 0.9rem; color: #666; margin-bottom: 12px; line-height: 1.5; }
.payment-buttons { display: flex; gap: 10px; width: 100%; flex-wrap: wrap; }
.btn-pay { flex: 1; padding: 10px; border-radius: 12px; font-weight: bold; border: none; cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.1); transition: transform 0.1s; font-size: 0.9rem; min-width: 120px; }
.btn-pay:hover { transform: translateY(-1px); }
.btn-bmc { background-color: #FFDD00; color: #000; }
.btn-crypto { background-color: #3861FB; color: #fff; }
.payment-notice { background-color: #fff; border: 1px dashed #d4a373; border-radius: 8px; padding: 12px; margin-bottom: 16px; font-size: 0.85rem; color: #666; }
.payment-notice ul { padding-left: 0; list-style: none; margin: 6px 0 0 0; }
.payment-notice li { margin-bottom: 4px; }
.input-minimal { width: 100%; padding: 10px 0; border: none; border-bottom: 1px solid #e0e0e0; background: transparent; font-size: 16px; color: #333; border-radius: 0; transition: border-color 0.3s; box-sizing: border-box; }
.input-minimal:focus { outline: none; border-bottom: 1px solid #d4a373; }
.form-group { margin-bottom: 16px; } 
.form-group label { display: block; font-size: 0.85rem; color: #999; margin-bottom: 4px; }
.form-row { display: flex; gap: 12px; } 
.half { flex: 1; width: 50%; } 
.custom-currency-wrapper { display: flex; align-items: center; gap: 8px; width: 100%; }
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
.chart-card { display: flex; flex-direction: column; align-items: center; width: 100%; box-sizing: border-box; }
.stats-row { display: flex; justify-content: space-around; align-items: center; width: 100%; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px dashed #f0ebe5; }
.stat-item { text-align: center; flex: 1; padding: 6px; border-radius: 12px; transition: background-color 0.2s, transform 0.1s; }
.cursor-pointer { cursor: pointer; }
.active-stat { background-color: #f7f5f0; box-shadow: inset 0 2px 4px rgba(0,0,0,0.05); }
.vertical-line { width: 1px; height: 30px; background-color: #f0ebe5; }
.stat-item .label { display: block; font-size: 0.75rem; color: #999; margin-bottom: 2px; }
.stat-item .value { font-size: 1.1rem; font-weight: 700; letter-spacing: 0.5px; word-break: break-all; } 
.text-income { color: #8fbc8f; } 
.text-expense { color: #d67a7a; } 
#chart-container { width: 100%; max-width: 100%; height: 250px; position: relative; display: flex; justify-content: center; align-items: center; margin: 0 auto; }
.chart-box-lg { width: 100%; height: 250px; position: relative; }
.no-data-msg { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #aaa; font-size: 0.9rem; width: 100%; text-align: center; }
.chart-hint { font-size: 0.75rem; color: #aaa; margin-top: 10px; text-align: center; }
.date-controls { display: flex; align-items: center; gap: 8px; background: #f7f5f0; padding: 6px 12px; border-radius: 20px; width: 100%; justify-content: space-between; box-sizing: border-box; flex-wrap: wrap; }
.date-input { border: none; background: transparent; color: #666; font-size: 0.85rem; outline: none; width: 35%; min-width: 80px; }
.separator { color: #aaa; }
.filter-btn { background-color: #d4a373; color: white; border: none; padding: 4px 12px; border-radius: 12px; font-size: 0.8rem; cursor: pointer; transition: background 0.2s; white-space: nowrap;}
.filter-btn:hover { background-color: #c19263; }
.mb-4 { margin-bottom: 16px; }
.msg-processing { color: #999; margin-top: 15px; font-size: 0.9rem; text-align: center;}
.msg-success { background-color: #f0f7f0; color: #556b2f; padding: 10px; border-radius: 8px; margin-top: 15px; font-size: 0.9rem; text-align: center; }
.msg-error { background-color: #fff0f0; color: #d67a7a; padding: 10px; border-radius: 8px; margin-top: 15px; font-size: 0.9rem; text-align: center; }
.fade-enter-active, .fade-leave-active { transition: opacity 0.5s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.tx-list-wrapper { padding: 16px; } 
.list-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #f0ebe5; padding-bottom: 12px; }
.list-controls h3 { margin: 0; font-size: 1rem; color: #8c7b75; }
.month-selector { display: flex; align-items: center; }
.month-input-styled { border: 1px solid #ddd; padding: 4px 10px; border-radius: 20px; font-size: 0.9rem; color: #666; background: #f9f9f9; outline: none; box-sizing: border-box; }
.tx-grouped-list { display: flex; flex-direction: column; gap: 15px; } 
.tx-date-group { border: 1px solid #f0ebe5; border-radius: 10px; overflow: hidden; }
.date-header { background-color: #f7f5f0; color: #a98467; font-weight: bold; padding: 8px 12px; font-size: 0.9rem; border-bottom: 1px solid #f0ebe5; }
.tx-category-group { padding: 0 12px; }
.tx-date-group:last-child .tx-category-group:last-child { padding-bottom: 10px; }
.category-subheader { font-size: 0.8rem; font-weight: 600; margin-top: 10px; margin-bottom: 5px; padding: 2px 0; border-bottom: 1px dotted #eee; width: 100%; }
.category-subheader.expense { color: #d67a7a; } 
.category-subheader.income { color: #8fbc8f; } 
.tx-item-grouped { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px dashed #eee; font-size: 0.95rem; }
.tx-category-group .tx-item-grouped:last-child { border-bottom: none; }
.tx-list { display: none; } 
.tx-item { display: none; } 
.tx-left { display: none; }
.tx-cat-badge { display: none; } 
.tx-mid-grouped { flex: 1; padding-right: 10px; font-weight: 500; color: #444; word-break: break-all; }
.tx-right-grouped { text-align: right; display: flex; flex-direction: column; align-items: flex-end; gap: 4px; min-width: 90px; }
.modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 1000; display: flex; justify-content: center; align-items: center; padding: 20px; box-sizing: border-box;}
.modal-content { background: white; width: 100%; max-width: 400px; border-radius: 16px; padding: 20px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1); animation: slideUp 0.3s ease-out; }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.modal-header h3 { margin: 0; color: #8c7b75; font-size: 1.1rem; }
.close-btn { background: transparent; border: none; font-size: 1.5rem; color: #aaa; cursor: pointer; }
.input-std { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; font-size: 1rem; color: #333; outline: none; background: #f9f9f9; box-sizing: border-box; height: 44px; }
.input-std:focus { border-color: #d4a373; background: white; }
.save-btn { width: 100%; padding: 12px; background: #d4a373; color: white; border: none; border-radius: 10px; font-size: 1rem; font-weight: bold; cursor: pointer; margin-top: 10px; }
.mt-2 { margin-top: 12px; }
@keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
@media (max-width: 480px) {
    .chart-header-row { flex-direction: column; align-items: flex-start; gap: 10px; }
    .date-controls { width: 100%; justify-content: space-between; }
    .stat-item .value { font-size: 1rem; } 
}
.tx-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 6px; }
.text-btn { background: #ffffff; border: 1px solid #e0e0e0; border-radius: 20px; padding: 4px 10px; font-size: 0.75rem; cursor: pointer; transition: all 0.2s ease; font-weight: 500; color: #888; line-height: 1.2; }
.text-btn:hover { transform: translateY(-1px); box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
.text-btn:active { transform: scale(0.95); }
.text-btn.edit { border-color: #d4a373; color: #d4a373; }
.text-btn.edit:hover { background-color: #d4a373; color: white; }
.text-btn.delete { border-color: #e5989b; color: #e5989b; }
.text-btn.delete:hover { background-color: #e5989b; color: white; }

/* 🌟 [新增] 預算進度條與日曆樣式 */
.budget-card { padding: 16px; margin-bottom: 20px; background: #fff; }
.budget-info { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.budget-label { font-size: 0.9rem; color: #666; font-weight: 500; }
.budget-percent { font-size: 0.9rem; font-weight: 800; }
.progress-track { width: 100%; height: 10px; background: #f0f0f0; border-radius: 10px; overflow: hidden; }
.progress-fill { height: 100%; border-radius: 10px; transition: width 0.5s ease; }
.bg-success { background-color: #1DB446; }
.bg-warning { background-color: #f59e0b; }
.bg-danger { background-color: #ef4444; }
.text-success { color: #1DB446; }
.text-warning { color: #f59e0b; }
.text-danger { color: #ef4444; }
.budget-remaining { text-align: right; font-size: 0.8rem; color: #888; margin-top: 8px; font-weight: 500; }

.list-controls-row { display: flex; flex-direction: column; gap: 10px; margin-bottom: 12px; }
.search-wrapper { width: 100%; }
.search-input { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 20px; font-size: 0.9rem; outline: none; background: #f9f9f9; }
.search-input:focus { border-color: #d4a373; background: #fff; }
.controls-right { display: flex; justify-content: space-between; align-items: center; }
.view-toggle { background: #f0f0f0; border-radius: 20px; padding: 2px; display: flex; }
.toggle-btn { background: transparent; border: none; padding: 4px 12px; border-radius: 18px; font-size: 0.8rem; cursor: pointer; color: #888; font-weight: 500; transition: all 0.2s; }
.toggle-btn.active { background: #fff; color: #d4a373; box-shadow: 0 1px 3px rgba(0,0,0,0.1); font-weight: bold; }

.calendar-grid { margin-top: 10px; }
.calendar-header-row { display: grid; grid-template-columns: repeat(7, 1fr); text-align: center; font-size: 0.8rem; color: #888; font-weight: bold; padding-bottom: 8px; border-bottom: 1px solid #eee; margin-bottom: 8px; }
.calendar-body { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
.calendar-cell { min-height: 60px; border: 1px solid #f5f5f5; border-radius: 8px; padding: 4px; display: flex; flex-direction: column; align-items: center; justify-content: flex-start; position: relative; cursor: pointer; transition: background 0.2s; }
.calendar-cell.empty { background: transparent; border: none; cursor: default; }
.calendar-cell:not(.empty):hover { background: #fff8f0; border-color: #d4a373; }
.calendar-cell.has-tx { background: #fffdf9; border-color: #eee; }
.cell-day { font-size: 0.85rem; font-weight: 600; color: #555; }
.cell-dots { display: flex; flex-direction: column; gap: 2px; margin-top: 4px; align-items: center; width: 100%; }
.dot-expense { font-size: 0.6rem; color: #d67a7a; background: #fff0f0; padding: 1px 3px; border-radius: 4px; white-space: nowrap; max-width: 100%; overflow: hidden; text-overflow: ellipsis; }
.dot-income { font-size: 0.6rem; color: #8fbc8f; background: #f0f7f0; padding: 1px 3px; border-radius: 4px; white-space: nowrap; max-width: 100%; overflow: hidden; text-overflow: ellipsis; }
:deep(.tag-highlight) { color: #2A9D8F; font-weight: bold; background: #e6fcf5; padding: 0 2px; border-radius: 4px; }
/* 🟢 [新增] 上傳卡片樣式 */
.upload-card {
  padding: 0;
  overflow: hidden;
  border: 2px dashed #d4a373;
  background-color: #fffbf5;
  cursor: pointer;
  transition: all 0.2s;
}
.upload-card:hover {
  background-color: #fff8f0;
  border-color: #b08d65;
}
.upload-area {
  padding: 24px;
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 120px;
}
.hidden-input { display: none; }
.icon { font-size: 2rem; margin-bottom: 8px; }
.upload-content p { margin: 0; color: #5d5d5d; }
.upload-content .sub { font-size: 0.8rem; color: #999; margin-top: 4px; }
.analyzing { pointer-events: none; opacity: 0.7; }
.loading-content { color: #d4a373; font-weight: bold; }

.trend-controls {
  display: flex;
  flex-direction: column; /* 手機版垂直排列 */
  gap: 12px;
  background: #f7f5f0;
  padding: 12px;
  border-radius: 16px;
  width: 100%;
  box-sizing: border-box;
}

.trend-type-toggle {
  display: flex;
  background: #e0e0e0;
  padding: 4px;
  border-radius: 20px;
  width: fit-content;
  margin: 0 auto; /* 置中 */
}

.toggle-btn {
  padding: 6px 20px;
  border: none;
  background: transparent;
  color: #666;
  font-weight: 600;
  font-size: 0.9rem;
  border-radius: 16px;
  cursor: pointer;
  transition: all 0.2s;
}

.toggle-btn.active {
  background: white;
  color: #d4a373;
  box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.date-range-inputs {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.date-input {
  background: white;
  border: 1px solid #ddd;
  padding: 4px 8px;
  border-radius: 8px;
  font-size: 0.85rem;
  width: 120px;
}

/* 電腦版調整 */
@media (min-width: 600px) {
  .trend-controls {
    flex-direction: row;
    justify-content: space-between;
    align-items: center;
  }
  .trend-type-toggle { margin: 0; }
  #chart-container {
    height: 350px; /* 電腦版高一點，看起來更舒適 */
  }
}
/* 針對下拉選單的優化 */
.custom-select {
  appearance: none; /* 移除預設醜醜的箭頭 (部分瀏覽器有效) */
  -webkit-appearance: none;
  cursor: pointer;
  background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
  background-repeat: no-repeat;
  background-position: right 12px center;
  background-size: 16px;
  padding-right: 32px; /* 預留空間給箭頭 */
}

/* 如果是深色模式或選取時，讓文字明顯一點 */
.search-input option {
  color: #333;
  padding: 4px;
  
}

/* 橫向捲動容器 */
.filter-scroll-view {
  display: flex;
  flex-wrap: wrap; /* ★ 關鍵修改：允許換行 */
  gap: 8px;        /* 按鈕之間的間距 */
  padding: 4px 0;
  /* 移除原本的橫向捲動相關設定 (overflow-x, scrollbar...) */
}

/* Chrome/Safari 隱藏捲軸 */
/* .filter-scroll-view::-webkit-scrollbar {
  display: none;
} */

/* 按鈕樣式 (Chip) */
.filter-chip {
  flex: 0 0 auto; /* 防止按鈕被壓縮 */
  padding: 6px 14px;
  border-radius: 20px;
  border: 1px solid #eee;
  background-color: #fff;
  color: #666;
  font-size: 0.9rem;
  cursor: pointer;
  transition: all 0.2s ease;
  white-space: nowrap; /* 防止文字換行 */
}

.filter-chip:hover {
  background-color: #f9f9f9;
}

/* 選中狀態 */
.filter-chip.active {
  background-color: #d4a373;
  color: white;
  border-color: #d4a373;
  box-shadow: 0 2px 6px rgba(212, 163, 115, 0.4);
  font-weight: bold;
}

/* 分隔線 */
.divider-vertical {
  display: none; /* 在換行模式下通常不需要分隔線 */
}
.month-selector-group {
  display: flex;
  align-items: center;
  background: white;
  border: 1px solid #ddd;
  border-radius: 20px;
  padding: 2px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

/* 左右切換按鈕 */
.month-btn {
  background: transparent;
  border: none;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  cursor: pointer;
  font-size: 1.2rem;
  color: #888;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s;
  line-height: 1;
  padding-bottom: 4px; /* 微調垂直置中 */
}

.month-btn:hover {
  background-color: #f0f0f0;
  color: #d4a373;
}

/* 中間的月份顯示區 */
.month-display-wrapper {
  position: relative;
  min-width: 100px;
  text-align: center;
  font-weight: 600;
  color: #555;
  font-size: 0.95rem;
}

/* 讓原生的 input 變透明並覆蓋在文字上 (這樣點擊文字還能叫出日曆) */
.month-input-hidden {
  position: absolute;
  top: 0; left: 0;
  width: 100%; height: 100%;
  opacity: 0; /* 完全透明 */
  cursor: pointer;
  z-index: 2; /* 蓋在文字上面 */
}

/* 顯示的文字標籤 */
.month-label {
  position: relative;
  z-index: 1;
  pointer-events: none; /* 讓點擊穿透到 input */
}

/* 新增按鈕的特別樣式 */
.add-btn-chip {
  background-color: #8c7b75 !important; /* 深棕色 */
  color: white !important;
  border-color: #8c7b75 !important;
  font-weight: bold;
}

.sub-label {
  font-size: 0.8rem;
  color: #999;
  margin-bottom: 4px;
  display: block;
}

.highlight-input {
  border-color: #d4a373;
  background-color: #fffbf5;
}

</style>