<template>
  <div class="space-y-6">
    <div class="section-title">
      <h2 class="text-xl font-semibold text-amber-700">所有帳戶列表</h2>
    </div>

    <button class="py-2 px-4 bg-green-600 text-white font-semibold rounded-md hover:bg-green-700 transition duration-150" @click="alert('TODO: 彈出新增/編輯表單')">
      新增/編輯帳戶 (TODO)
    </button>
    
    <div v-if="loading" class="text-center text-gray-500 py-8">載入中...</div>
    <div v-else-if="accounts.length === 0" class="text-center text-gray-500 p-4 border rounded-md bg-white">目前沒有任何帳戶記錄。</div>

    <div v-else class="data-box p-4 rounded-lg shadow-sm overflow-x-auto bg-white">
      <table class="min-w-full divide-y divide-stone-200 account-table">
        <thead class="bg-stone-50">
          <tr>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">名稱</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">類型</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">餘額</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">幣種</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">操作</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-stone-200">
          <tr v-for="account in accounts" :key="account.name">
            <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-900">{{ account.name }}</td>
            <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500">{{ account.type }}</td>
            <td :class="['px-3 py-2 whitespace-nowrap text-sm font-semibold', account.type === 'Liability' ? 'text-red-600' : 'text-green-600']">
              {{ numberFormat(account.balance, 2) }}
            </td>
            <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500">{{ account.currency_unit }}</td>
            <td class="px-3 py-2 whitespace-nowrap text-sm font-medium">
              <button class="bg-red-600 hover:bg-red-700 text-white py-1 px-2 rounded text-xs transition duration-150" @click="handleDelete(account.name)">
                刪除
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { fetchWithLiffToken, numberFormat } from '@/utils/api'; // 假設 utils/api.js 位於 src/utils
import { defineEmits } from 'vue';

const accounts = ref([]);
const loading = ref(true);
const emit = defineEmits(['refreshDashboard']); // 定義發射事件

// 從 AssetService 獲取帳戶列表 (對應 api.php?action=get_accounts)
async function fetchAccounts() {
  loading.value = true;
  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=get_accounts`);
  if (response) {
    const result = await response.json();
    if (result.status === 'success') {
      accounts.value = result.data;
    } else {
      console.error('API Error:', result.message);
    }
  }
  loading.value = false;
}

// 處理刪除帳戶請求 (對應 api.php?action=delete_account)
async function handleDelete(name) {
  if (!confirm(`確定要刪除帳戶 [${name}] 嗎？此操作會影響淨值計算，無法撤銷。`)) return;

  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=delete_account`, {
    method: 'POST',
    body: JSON.stringify({ name: name })
  });

  if (response) {
    const result = await response.json();
    alert(result.message);
    if (result.status === 'success') {
      fetchAccounts(); // 刷新列表
      emit('refreshDashboard'); // 通知 Dashboard 刷新總覽
    }
  }
}

onMounted(fetchAccounts);
</script>