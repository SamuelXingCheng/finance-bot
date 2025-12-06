<template>
  <div class="app-layout">
    
    <transition name="fade">
      <OnboardingView 
        v-if="showOnboarding" 
        @complete="handleOnboardingComplete" 
      />
    </transition>

    <nav class="navbar">
      <div class="nav-container">
        <div class="nav-brand">
          <span class="brand-text">FinBot</span>
        </div>

        <div class="nav-links">
          <button 
            @click="currentTab = 'Dashboard'" 
            :class="['nav-item', currentTab === 'Dashboard' ? 'active' : '']"
          >
            收支總覽
          </button>
          <button 
            @click="currentTab = 'Accounts'" 
            :class="['nav-item', currentTab === 'Accounts' ? 'active' : '']"
          >
            帳戶
          </button>
          <button 
            @click="currentTab = 'Crypto'" 
            :class="['nav-item', currentTab === 'Crypto' ? 'active' : '']"
          >
            Crypto(開發中)
          </button>
        </div>

        <div class="nav-user">
          <div v-if="liffState.isLoggedIn && liffState.profile" class="user-profile">
            <span class="user-name desktop-only">{{ liffState.profile.displayName }}</span>
            <img :src="liffState.profile.pictureUrl" alt="Avatar" class="user-avatar" />
          </div>
          <div v-else class="user-profile">
            <div class="user-avatar placeholder">...</div>
          </div>
        </div>
      </div>
    </nav>

    <div v-if="liffState.error" class="error-banner">
      <p>❌ {{ liffState.error }}</p>
    </div>

    <div v-else-if="!liffState.isLoggedIn" class="loading-screen">
      <div class="loading-content">
        <span class="loader"></span>
        <p>正在連線至 LINE...</p>
      </div>
    </div>

    <main v-else class="main-content">
      <transition name="fade" mode="out-in">
        <component 
          :is="currentView" 
          ref="currentViewRef" 
          @refresh-dashboard="handleRefreshDashboard" 
        />
      </transition>
    </main>

    <a href="https://line.me/R/ti/p/@finbot" target="_blank" class="fab-chat" title="AI 記帳">
      <span class="fab-icon">💬</span>
      <span class="fab-text">AI 記帳</span>
    </a>

  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import liff from '@line/liff';
import { liffState } from './liffState';
// 🟢 2. 引入 API 工具與新的引導視圖
import { fetchWithLiffToken } from '@/utils/api';
import OnboardingView from './views/OnboardingView.vue';

// 引入所有視圖元件
import DashboardView from './views/DashboardView.vue';
import AccountManagerView from './views/AccountManagerView.vue';
import CryptoView from './views/CryptoView.vue'; 

const LIFF_ID = import.meta.env.VITE_LIFF_ID;
const currentTab = ref('Dashboard');
const currentViewRef = ref(null);
// 🟢 3. 控制引導顯示的狀態
const showOnboarding = ref(false);

// 路由切換邏輯
const currentView = computed(() => {
  if (currentTab.value === 'Dashboard') return DashboardView;
  if (currentTab.value === 'Accounts') return AccountManagerView;
  if (currentTab.value === 'Crypto') return CryptoView;
  return null;
});

const handleRefreshDashboard = () => {
    if (currentView.value === DashboardView && currentViewRef.value?.refreshAllData) {
       currentViewRef.value.refreshAllData();
    }
};

// 🟢 4. 檢查用戶引導狀態的函式 (呼叫後端 API)
async function checkUserStatus() {
  // 確保 API_BASE_URL 已定義 (通常在 utils/api.js 中設定)
  const baseUrl = window.API_BASE_URL || 'https://finbot.tw/api.php';
  
  const response = await fetchWithLiffToken(`${baseUrl}?action=get_user_status`);
  if (response && response.ok) {
    const result = await response.json();
    // 如果 is_onboarded 為 0 (false)，則顯示引導
    if (result.status === 'success' && result.data.is_onboarded == 0) {
      showOnboarding.value = true;
    }
  }
}

// 🟢 5. 引導完成後的處理
function handleOnboardingComplete() {
  showOnboarding.value = false;
  // 重新整理目前的視圖資料 (例如刷新 Dashboard 以顯示試用狀態)
  if (currentViewRef.value?.refreshAllData) {
    currentViewRef.value.refreshAllData();
  }
}

onMounted(async () => {
    if (!liff) {
        liffState.error = 'LIFF SDK 未載入';
        return;
    }
    try {
        await liff.init({ liffId: LIFF_ID });
        if (liff.isLoggedIn()) {
            liffState.isLoggedIn = true;
            liffState.profile = await liff.getProfile();
            
            // 🟢 6. 登入成功後，檢查是否需要顯示引導
            checkUserStatus(); 
        } else {
            liff.login(); 
        }
    } catch (err) {
        console.error('LIFF Error:', err);
        liffState.error = '初始化失敗，請檢查網路或 ID 設定。';
    }
});
</script>

<style>
* { box-sizing: border-box; }
body { overflow-x: hidden; margin: 0; background-color: var(--bg-body); }
</style>

<style scoped>
.app-layout { display: flex; flex-direction: column; min-height: 100vh; width: 100%; overflow-x: hidden; }
.navbar { background-color: var(--bg-nav); box-shadow: 0 2px 10px rgba(0,0,0,0.03); position: sticky; top: 0; z-index: 100; height: 60px; display: flex; align-items: center; width: 100%; }
.nav-container { width: 100%; max-width: 800px; margin: 0 auto; padding: 0 16px; display: flex; justify-content: space-between; align-items: center; }
.nav-brand { display: flex; align-items: center; gap: 6px; font-size: 1.2rem; font-weight: 700; color: var(--text-accent); }
.nav-links { display: flex; gap: 4px; background: #f7f5f0; padding: 4px; border-radius: 30px; }
.nav-item { background: transparent; border: none; padding: 6px 12px; border-radius: 20px; color: var(--text-secondary); font-size: 0.85rem; font-weight: 500; cursor: pointer; transition: all 0.3s ease; white-space: nowrap; }
.nav-item.active { background-color: #ffffff; color: var(--text-accent); box-shadow: 0 2px 8px rgba(0,0,0,0.05); font-weight: 600; }
.nav-user { display: flex; align-items: center; }
.user-profile { display: flex; align-items: center; gap: 8px; }
.user-name { font-size: 0.9rem; color: var(--text-primary); }
.user-avatar { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 2px solid #fff; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
.user-avatar.placeholder { background-color: #eee; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; color: #999; }
.desktop-only { display: none; }
@media (min-width: 640px) { .desktop-only { display: block; } .nav-container { padding: 0 24px; } }
.main-content { flex: 1; width: 100%; max-width: 800px; margin: 0 auto; padding: 20px 16px; }
.fab-chat { position: fixed; bottom: 24px; right: 20px; background-color: #1DB446; color: white; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 12px 20px; border-radius: 50px; box-shadow: 0 4px 12px rgba(29, 180, 70, 0.4); text-decoration: none; z-index: 999; transition: transform 0.2s, box-shadow 0.2s; }
.fab-chat:active { transform: scale(0.95); }
.loading-screen { flex: 1; display: flex; justify-content: center; align-items: center; color: var(--text-secondary); }
.error-banner { background-color: #ffeaea; color: #d67a7a; padding: 12px; text-align: center; font-size: 0.9rem; }
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>