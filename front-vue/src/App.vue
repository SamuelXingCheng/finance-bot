<template>
  <div class="app-layout">
    
    <div v-if="liffState.error" class="error-banner">
      <p>❌ {{ liffState.error }}</p>
    </div>

    <div v-else-if="isLoading" class="loading-container">
      <div class="spinner"></div>
      <p>FinBot 啟動中...</p>
    </div>

    <div v-else-if="!liffState.isLoggedIn || !isOnboarded" class="onboarding-container">
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
            <img v-if="liffState.profile?.pictureUrl" :src="liffState.profile.pictureUrl" class="user-avatar" />
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

// 引入元件
import OnboardingView from './views/OnboardingView.vue';
import DashboardView from './views/DashboardView.vue';
import AccountManagerView from './views/AccountManagerView.vue';
import CryptoView from './views/CryptoView.vue'; 

// 環境變數設定
const LIFF_ID = import.meta.env.VITE_LIFF_ID;
const API_URL = import.meta.env.VITE_API_BASE_URL || window.API_BASE_URL;

const currentTab = ref('Dashboard');
const currentViewRef = ref(null);
const isLoading = ref(true); 

// 🟢 修正點 1 (狀態)：用來儲存從後端查到的「是否已引導」狀態
const isOnboarded = ref(false); 

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

// 🟢 修正點 2 (按鈕行為)：如果已登入，直接送出資料；未登入才轉跳
async function handleOnboardingLogin(data) {
  // 1. 存入暫存 (以防萬一)
  localStorage.setItem('pending_onboarding', JSON.stringify(data));
  
  // 2. 判斷狀態
  if (!liff.isLoggedIn()) {
    // 情況 A：真的還沒登入 -> 呼叫登入 (會跳轉)
    liff.login();
  } else {
    // 情況 B：其實已經登入了 (只是被擋在引導頁) -> 直接執行資料提交
    console.log("已登入，直接執行提交...");
    await processPendingOnboarding();
  }
}

async function processPendingOnboarding() {
  const pendingData = localStorage.getItem('pending_onboarding');
  if (pendingData) {
    try {
      const formData = JSON.parse(pendingData);
      const response = await fetchWithLiffToken(`${API_URL}?action=submit_onboarding`, {
        method: 'POST',
        body: JSON.stringify(formData)
      });

      if (response && response.ok) {
        alert('🎉 歡迎加入！已成功為您開通 FinPoints 獎勵與試用權限。');
        
        // 🟢 修正點 3 (即時切換)：提交成功後，立刻在前端標記為已完成，讓畫面自動切換到 Dashboard
        isOnboarded.value = true; 

        if (currentViewRef.value?.refreshAllData) currentViewRef.value.refreshAllData();
      }
    } catch (e) {
      console.error('Onboarding submission failed', e);
    } finally {
      localStorage.removeItem('pending_onboarding');
    }
  }
}

onMounted(async () => {
    if (!liff) {
        liffState.error = 'LIFF SDK 未載入';
        isLoading.value = false;
        return;
    }

    try {
        await liff.init({ liffId: LIFF_ID });
        
        if (liff.isLoggedIn()) {
            liffState.isLoggedIn = true;
            try {
                liffState.profile = await liff.getProfile();

                // 🟢 修正點 4 (初始化檢查)：登入後，立刻呼叫 API 檢查 DB 中的引導狀態
                const statusResponse = await fetchWithLiffToken(`${API_URL}?action=get_user_status`);
                if (statusResponse && statusResponse.ok) {
                    const result = await statusResponse.json();
                    if (result.status === 'success') {
                        // 將 DB 的狀態 (0 或 1) 同步到前端變數
                        isOnboarded.value = Number(result.data.is_onboarded) === 1;
                        console.log("User Status Checked: Onboarded =", isOnboarded.value);
                    }
                }

            } catch (pErr) {
                console.warn('無法獲取個人資料或狀態', pErr);
            }
            
            // 處理剛填完引導表單並登入的情況
            await processPendingOnboarding();
        } 
    } catch (err) {
        console.error('LIFF Error:', err);
        liffState.error = '連線失敗，請檢查網路設定';
    } finally {
        isLoading.value = false;
    }
});
</script>

<style scoped>
/* =========================================
   ★★★ 全域 Box-Sizing ★★★
   解決「padding 把版面撐大導致右邊被切掉」的問題
   ========================================= */
* {
  box-sizing: border-box;
}

/* --- 全域變數 --- */
:root {
  --bg-nav: #ffffff;
  --text-primary: #5A483C;
  --text-secondary: #999999;
  --text-accent: #d4a373;
  --bg-main: #f9f7f2;
}

/* --- 版面基礎設定 --- */
.app-layout { 
  display: flex; 
  flex-direction: column; 
  min-height: 100vh; 
  min-height: 100dvh; /* 解決 Chrome 網址列跳動 */
  width: 100%; 
  overflow-x: hidden; 
  background-color: var(--bg-main);
}

.onboarding-container, .loading-container {
  min-height: 100vh;
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  padding: 20px;
}

/* --- 導覽列 (Navbar) --- */
.navbar { 
  background-color: var(--bg-nav); 
  box-shadow: 0 2px 10px rgba(0,0,0,0.03); 
  position: sticky; 
  top: 0; 
  z-index: 100; 
  height: 60px; 
  display: flex; 
  align-items: center; 
  width: 100%; 
}

.nav-container { 
  width: 100%; 
  max-width: 800px; 
  margin: 0 auto; 
  padding: 0 16px; 
  display: flex; 
  justify-content: space-between; 
  align-items: center; 
}

.nav-brand { 
  display: flex; 
  align-items: center; 
  gap: 6px; 
  font-size: 1.2rem; 
  font-weight: 700; 
  color: #5A483C;
  flex-shrink: 0; 
}

.nav-links { 
  display: flex; 
  gap: 4px; 
  background: #f7f5f0; 
  padding: 4px; 
  border-radius: 30px; 
  flex-shrink: 1; 
  white-space: nowrap;
}

.nav-item { 
  background: transparent; 
  border: none; 
  padding: 6px 12px; 
  border-radius: 20px; 
  color: #999; 
  font-size: 0.85rem; 
  font-weight: 500; 
  cursor: pointer; 
  transition: all 0.3s ease; 
}

.nav-item.active { 
  background-color: #ffffff; 
  color: #d4a373; 
  box-shadow: 0 2px 8px rgba(0,0,0,0.05); 
  font-weight: 600; 
}

.nav-user { 
  display: flex; 
  align-items: center; 
  flex-shrink: 0; 
}

.user-avatar { 
  width: 36px; 
  height: 36px; 
  border-radius: 50%; 
  object-fit: cover; 
  border: 2px solid #fff; 
  box-shadow: 0 2px 6px rgba(0,0,0,0.1); 
}

/* --- Main Content --- */
.main-content { 
  flex: 1; 
  width: 100%; 
  max-width: 800px; 
  margin: 0 auto; 
  padding: 20px 16px; 
}

/* --- 其他元件 --- */
.fab-chat { position: fixed; bottom: 24px; right: 20px; background-color: #1DB446; color: white; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 12px 20px; border-radius: 50px; box-shadow: 0 4px 12px rgba(29, 180, 70, 0.4); text-decoration: none; z-index: 999; transition: transform 0.2s, box-shadow 0.2s; }
.fab-chat:active { transform: scale(0.95); }
.error-banner { background-color: #ffeaea; color: #d67a7a; padding: 12px; text-align: center; font-size: 0.9rem; }
.spinner { width: 40px; height: 40px; border: 4px solid #e0e0e0; border-top-color: #d4a373; border-radius: 50%; animation: spin 1s linear infinite; margin-bottom: 16px; }
@keyframes spin { to { transform: rotate(360deg); } }
.loading-container p { color: #5A483C; font-weight: 500; font-size: 0.95rem; }
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

/* =========================================
   ★★★ 手機版優化區 ★★★ 
   解決：1. Navbar 擠壓 2. 內容邊距
   ========================================= */
@media (max-width: 480px) {
  /* 縮小 Navbar 邊距 */
  .nav-container { padding: 0 8px; }
  
  /* 縮小 Logo 字體，防止佔用太多空間 */
  .nav-brand { font-size: 1rem; gap: 4px; }
  
  /* 縮小按鈕內距，讓 3 個按鈕能排進中間 */
  .nav-item { padding: 5px 8px; font-size: 0.8rem; }
  
  /* 縮小按鈕群組間距 */
  .nav-links { gap: 2px; }
  
  /* 微調頭像大小 */
  .user-avatar { width: 32px; height: 32px; }
  
  /* 縮小內容區塊的左右 Padding，讓卡片更寬 */
  .main-content { padding: 16px 12px; }
}
</style>