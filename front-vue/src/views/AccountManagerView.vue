<template>
  <div class="accounts-container">
    <div class="page-header">
      <div class="title-group">
        <h2>📂 帳戶管理</h2>
        <p class="subtitle">管理您的資產與負債項目</p>
      </div>
      <button class="add-btn" @click="showCustomModal('新增帳戶功能開發中...')">
        <span>+</span> 新增帳戶
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="state-box">
      <span class="loader"></span> 讀取中...
    </div>

    <!-- Empty State -->
    <div v-else-if="accounts.length === 0" class="state-box empty">
      <p>📭 目前還沒有帳戶記錄</p>
      <p class="subtitle mt-2">請從 LINE Bot 輸入「設定 帳戶名 類型 金額 幣種」來新增。</p>
    </div>

    <!-- Account List (Card Style for Mobile / Table for Desktop) -->
    <div v-else class="account-list">
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
// 確保 '@/utils/api' 檔案中定義了 fetchWithLiffToken 和 numberFormat
import { fetchWithLiffToken, numberFormat } from '@/utils/api'; 
import { defineEmits } from 'vue';

const accounts = ref([]);
const loading = ref(true);
const emit = defineEmits(['refreshDashboard']);

// 為了避免使用 alert() 和 confirm() 造成 LIFF 凍結，我們使用 console.error 暫代
// 實際專案中，這裡應該替換成自定義的 Modal UI。
function showCustomModal(message) {
    console.error(`[Modal Placeholder] ${message}`);
    // 可以在這裡暫時使用瀏覽器原生的 console.log 進行通知
}


async function fetchAccounts() {
  loading.value = true;
  // 檢查 API BASE URL
  if (!window.API_BASE_URL) {
      console.error('API Error: window.API_BASE_URL 未定義。請檢查 src/utils/api.js 或 index.html。');
      loading.value = false;
      return;
  }
  
  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=get_accounts`);
  if (response) {
    const result = await response.json();
    if (result.status === 'success') {
      accounts.value = result.data;
    } else {
      console.error('API Error: 無法獲取帳戶資料。', result.message);
    }
  } else {
      console.error('API Error: fetchWithLiffToken 失敗 (可能 LIFF Token 無效或網路錯誤)。');
  }
  loading.value = false;
}

async function handleDelete(name) {
  // 替換 confirm()
  if (!window.confirm(`確定要刪除 [${name}] 嗎？`)) return;

  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=delete_account`, {
    method: 'POST',
    body: JSON.stringify({ name: name })
  });
  
  if (response) {
    const result = await response.json();
    if (result.status === 'success') {
      showCustomModal('刪除成功！');
      fetchAccounts();
      emit('refreshDashboard');
    } else {
      showCustomModal(`刪除失敗: ${result.message}`);
    }
  } else {
      showCustomModal('網路錯誤，刪除請求失敗。');
  }
}

function getTypeClass(type) {
  return type === 'Liability' ? 'badge-debt' : 'badge-asset';
}

onMounted(fetchAccounts);
</script>

<style scoped>
/* 文青風樣式 */
.accounts-container {
  max-width: 100%;
}

/* 頁面標題區 */
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
}

.title-group h2 {
  font-size: 1.2rem;
  color: var(--text-primary);
  margin: 0;
}

.subtitle {
  font-size: 0.85rem;
  color: var(--text-secondary);
  margin: 4px 0 0 0;
}

/* 新增按鈕 */
.add-btn {
  background-color: var(--color-primary);
  color: white;
  border: none;
  padding: 8px 16px;
  border-radius: 20px;
  font-size: 0.9rem;
  cursor: pointer;
  box-shadow: var(--shadow-soft);
  transition: transform 0.1s;
}
.add-btn:active { transform: scale(0.95); }

/* 狀態區塊 */
.state-box {
  text-align: center;
  padding: 40px;
  color: var(--text-secondary);
  background: var(--bg-card);
  border-radius: var(--border-radius);
  box-shadow: var(--shadow-soft);
}

/* 帳戶卡片列表 */
.account-list {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.account-card {
  background: var(--bg-card);
  padding: 20px;
  border-radius: var(--border-radius);
  box-shadow: var(--shadow-soft);
  display: flex;
  justify-content: space-between;
  align-items: center;
  transition: transform 0.2s;
  border: 1px solid #f0ebe5;
}

.account-card:hover {
  transform: translateY(-2px);
}

/* 左側資訊 */
.card-left {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.acc-name {
  font-weight: 600;
  font-size: 1.05rem;
  color: var(--text-primary);
}

.acc-meta {
  display: flex;
  align-items: center;
  gap: 8px;
}

.currency {
  font-size: 0.8rem;
  color: var(--text-secondary);
}

/* Badge 標籤 */
.badge {
  font-size: 0.75rem;
  padding: 2px 8px;
  border-radius: 4px;
}
.badge-asset { background: #e9edc9; color: #556b2f; }
.badge-debt { background: #ffe5d9; color: #c44536; }

/* 右側金額與操作 */
.card-right {
  text-align: right;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 8px;
}

.acc-balance {
  font-size: 1.1rem;
  font-weight: 700;
  letter-spacing: 0.5px;
}
.text-asset { color: var(--text-primary); }
.text-debt { color: var(--color-danger); }

.delete-icon {
  background: transparent;
  border: none;
  cursor: pointer;
  font-size: 1rem;
  opacity: 0.3;
  transition: opacity 0.2s;
  padding: 4px;
}
.delete-icon:hover { opacity: 1; }

/* 手機版優化 */
@media (max-width: 480px) {
  .account-card {
    padding: 16px;
  }
  .acc-name { font-size: 1rem; }
  .acc-balance { font-size: 1rem; }
}
</style>