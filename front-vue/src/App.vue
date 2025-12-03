<template>
  <div class="p-4 md:p-6">
    <div v-if="liffState.error" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded text-center mb-6">
      <p class="font-bold">❌ 錯誤！應用程式無法啟動</p>
      <p class="text-sm mt-1">{{ liffState.error }}</p>
    </div>

    <div v-else-if="!liffState.isLoggedIn" class="text-center my-20 text-gray-500">
      <p class="text-lg">正在進行 LINE 登入驗證...</p>
    </div>

    <div v-else>
      <h1 class="text-3xl font-light text-amber-700 pb-3 mb-4 border-b">財務戰情室 (Vue)</h1>
      
      <div class="tabs flex border-b border-gray-200 mb-6">
        <button 
          @click="currentTab = 'Dashboard'" 
          :class="['tab-button px-4 py-2 text-sm font-medium border-b-2 transition duration-150', currentTab === 'Dashboard' ? 'border-amber-700 text-amber-700' : 'border-transparent text-gray-700 hover:text-amber-700']">
          總覽與記帳
        </button>
        <button 
          @click="currentTab = 'Accounts'" 
          :class="['tab-button px-4 py-2 text-sm font-medium border-b-2 transition duration-150', currentTab === 'Accounts' ? 'border-amber-700 text-amber-700' : 'border-transparent text-gray-700 hover:text-amber-700']">
          帳戶管理
        </button>
      </div>
      
      <component :is="currentView" ref="currentViewRef" @refresh-dashboard="handleRefreshDashboard" />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'; // 🌟 新增 onMounted
import liff from '@line/liff';                // 🌟 新增 liff 引入
import { liffState } from './liffState';       // 確保路徑正確

// 引入兩個 View 組件
import DashboardView from './views/DashboardView.vue';
import AccountManagerView from './views/AccountManagerView.vue';

// ----------------------------------------------------
// ⚠️ 替換成您的 LIFF ID ⚠️
const LIFF_ID = import.meta.env.VITE_LIFF_ID;

// ----------------------------------------------------

// === LIFF 初始化邏輯 (START) ===
onMounted(async () => {
    // 檢查瀏覽器是否支援 LIFF
    if (!liff) {
        liffState.error = 'LIFF SDK 未載入或不支援。請檢查網路或套件安裝。';
        return;
    }
    
    try {
        console.log('App.vue: 開始初始化 LIFF...');
        await liff.init({ liffId: LIFF_ID });
        console.log('App.vue: LIFF 初始化成功');

        // 檢查是否登入
        if (liff.isLoggedIn()) {
            liffState.isLoggedIn = true;
            const profile = await liff.getProfile();
            liffState.profile = profile;
        } else {
            // 如果在外部瀏覽器或未登入狀態，跳轉到登入頁面
            liff.login(); 
        }

    } catch (err) {
        // LIFF 初始化失敗
        console.error('App.vue: LIFF 初始化失敗:', err);
        liffState.error = err.message || 'LIFF 初始化失敗，請檢查網路、HTTPS 或 LIFF ID。';
    }
});
// === LIFF 初始化邏輯 (END) ===

const currentTab = ref('Dashboard');
const currentViewRef = ref(null);

// 計算當前要渲染的組件
const currentView = computed(() => {
  if (currentTab.value === 'Dashboard') return DashboardView;
  if (currentTab.value === 'Accounts') return AccountManagerView;
  return null;
});

// 處理 Accounts View 發來的刷新事件
const handleRefreshDashboard = () => {
    // 檢查當前顯示的 View 是否是 Dashboard
    if (currentView.value === DashboardView && currentViewRef.value) {
        // 調用 Dashboard View 暴露出的刷新方法
        // 假設 Dashboard View 有暴露 refreshAllData 方法
        if (currentViewRef.value.refreshAllData) {
           currentViewRef.value.refreshAllData();
        } else {
           console.warn('DashboardView 尚未暴露 refreshAllData 方法。');
        }
    }
};
</script>

<style scoped>
/* 此處可以放置 App.vue 的局部樣式 */
.tab-button.active {
    border-color: var(--color-amber-700);
}
</style>