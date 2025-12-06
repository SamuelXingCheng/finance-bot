<template>
  <div class="app-layout">
    
    <div v-if="liffState.error" class="error-banner">
      <p>❌ {{ liffState.error }}</p>
    </div>

    <div v-else-if="!liffState.isLoggedIn" class="onboarding-container">
      <OnboardingView @trigger-login="handleOnboardingLogin" />
    </div>

    <div v-else class="authenticated-view">
      <nav class="navbar">
        <div class="nav-container">
          <div class="nav-brand"><span class="brand-text">FinBot</span></div>
          <div class="nav-links">
            <button @click="currentTab = 'Dashboard'" :class="['nav-item', currentTab === 'Dashboard' ? 'active' : '']">收支</button>
            <button @click="currentTab = 'Accounts'" :class="['nav-item', currentTab === 'Accounts' ? 'active' : '']">帳戶</button>
            <button @click="currentTab = 'Crypto'" :class="['nav-item', currentTab === 'Crypto' ? 'active' : '']">Crypto</button>
          </div>
          <div class="nav-user">
            <img v-if="liffState.profile" :src="liffState.profile.pictureUrl" class="user-avatar" />
          </div>
        </div>
      </nav>

      <main class="main-content">
        <transition name="fade" mode="out-in">
          <component 
            :is="currentView" 
            ref="currentViewRef" 
            @refresh-dashboard="handleRefreshDashboard" 
          />
        </transition>
      </main>

      <a href="https://line.me/R/ti/p/@finbot" target="_blank" class="fab-chat">
        <span class="fab-icon">💬</span><span class="fab-text">AI 記帳</span>
      </a>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import liff from '@line/liff';
import { liffState } from './liffState';
import { fetchWithLiffToken } from '@/utils/api';

// 引入元件 (請確保路徑正確)
import OnboardingView from './views/OnboardingView.vue';
import DashboardView from './views/DashboardView.vue';
import AccountManagerView from './views/AccountManagerView.vue';
import CryptoView from './views/CryptoView.vue'; 

const LIFF_ID = import.meta.env.VITE_LIFF_ID;
const currentTab = ref('Dashboard');
const currentViewRef = ref(null);

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

// --- 核心邏輯：處理引導與登入 ---

// 1. 當用戶在 OnboardingView 點擊登入時觸發
function handleOnboardingLogin(data) {
  // 將用戶填寫的目標、預算等資料暫存入 localStorage
  localStorage.setItem('pending_onboarding', JSON.stringify(data));
  
  // 執行 LINE 登入 (會跳轉)
  if (!liff.isLoggedIn()) {
    liff.login();
  }
}

// 2. 用戶登入回來後，檢查是否有暫存資料需要寫入後端
async function processPendingOnboarding() {
  const pendingData = localStorage.getItem('pending_onboarding');
  
  if (pendingData) {
    try {
      const formData = JSON.parse(pendingData);
      
      // 呼叫後端 API 寫入設定
      const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=submit_onboarding`, {
        method: 'POST',
        body: JSON.stringify(formData)
      });

      if (response && response.ok) {
        // 成功後提示並刷新
        alert('🎉 歡迎加入！已成功為您開通 FinPoints 獎勵與試用權限。');
        if (currentViewRef.value?.refreshAllData) currentViewRef.value.refreshAllData();
      }
    } catch (e) {
      console.error('Onboarding submission failed', e);
    } finally {
      // 無論成功失敗，都清除暫存，避免下次重整又跳出來
      localStorage.removeItem('pending_onboarding');
    }
  }
}

onMounted(async () => {
    if (!liff) return;
    try {
        await liff.init({ liffId: LIFF_ID });
        
        if (liff.isLoggedIn()) {
            liffState.isLoggedIn = true;
            liffState.profile = await liff.getProfile();
            
            // 登入後檢查：是否有剛剛填寫的引導資料？
            await processPendingOnboarding();
        } 
        // 若未登入，template 中的 v-else-if 會自動顯示 OnboardingView
    } catch (err) {
        console.error('LIFF Error:', err);
        liffState.error = '連線失敗，請檢查網路';
    }
});
</script>

<style scoped>
/* 確保引導頁面全螢幕置中 */
.onboarding-container {
  min-height: 100vh;
  background-color: #f9f7f2;
  display: flex;
  justify-content: center;
  align-items: center;
  padding: 20px;
}

/* App 佈局 */
.app-layout { display: flex; flex-direction: column; min-height: 100vh; width: 100%; overflow-x: hidden; }

/* Navbar */
.navbar { background-color: white; box-shadow: 0 2px 10px rgba(0,0,0,0.03); position: sticky; top: 0; z-index: 100; height: 60px; display: flex; align-items: center; width: 100%; }
.nav-container { width: 100%; max-width: 800px; margin: 0 auto; padding: 0 16px; display: flex; justify-content: space-between; align-items: center; }
.nav-brand { display: flex; align-items: center; gap: 6px; font-size: 1.2rem; font-weight: 700; color: #5A483C; }
.nav-links { display: flex; gap: 4px; background: #f7f5f0; padding: 4px; border-radius: 30px; }
.nav-item { background: transparent; border: none; padding: 6px 12px; border-radius: 20px; color: #999; font-size: 0.85rem; font-weight: 500; cursor: pointer; transition: all 0.3s ease; white-space: nowrap; }
.nav-item.active { background-color: #ffffff; color: #d4a373; box-shadow: 0 2px 8px rgba(0,0,0,0.05); font-weight: 600; }
.nav-user { display: flex; align-items: center; }
.user-avatar { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 2px solid #fff; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }

/* Main Content */
.main-content { flex: 1; width: 100%; max-width: 800px; margin: 0 auto; padding: 20px 16px; }

/* Floating Action Button */
.fab-chat { position: fixed; bottom: 24px; right: 20px; background-color: #1DB446; color: white; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 12px 20px; border-radius: 50px; box-shadow: 0 4px 12px rgba(29, 180, 70, 0.4); text-decoration: none; z-index: 999; transition: transform 0.2s, box-shadow 0.2s; }
.fab-chat:active { transform: scale(0.95); }

/* Error Banner */
.error-banner { background-color: #ffeaea; color: #d67a7a; padding: 12px; text-align: center; font-size: 0.9rem; }

/* Transition */
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>