<template>
  <div class="app-layout">
    
    <!-- 🌟 頂部導航列 -->
    <nav class="navbar">
      <div class="nav-container">
        <!-- Logo / 品牌 -->
        <div class="nav-brand">
          <span class="logo-icon">🌿</span>
          <span class="brand-text">Finance Bot</span>
        </div>

        <!-- 中間導航選單 (桌面版顯示，手機版可優化) -->
        <div class="nav-links">
          <button 
            @click="currentTab = 'Dashboard'" 
            :class="['nav-item', currentTab === 'Dashboard' ? 'active' : '']"
          >
            總覽
          </button>
          <button 
            @click="currentTab = 'Accounts'" 
            :class="['nav-item', currentTab === 'Accounts' ? 'active' : '']"
          >
            帳戶
          </button>
        </div>

        <!-- 右側使用者資訊 -->
        <div class="nav-user">
          <div v-if="liffState.isLoggedIn && liffState.profile" class="user-profile">
            <span class="user-name">{{ liffState.profile.displayName }}</span>
            <img :src="liffState.profile.pictureUrl" alt="Avatar" class="user-avatar" />
          </div>
          <div v-else class="user-profile">
            <span class="user-name">訪客</span>
            <div class="user-avatar placeholder">Wait</div>
          </div>
        </div>
      </div>
    </nav>

    <!-- ⚠️ 錯誤提示區塊 -->
    <div v-if="liffState.error" class="error-banner">
      <p>❌ {{ liffState.error }}</p>
    </div>

    <!-- 🔄 載入中畫面 -->
    <div v-else-if="!liffState.isLoggedIn" class="loading-screen">
      <div class="loading-content">
        <span class="loader"></span>
        <p>正在連線至 LINE...</p>
      </div>
    </div>

    <!-- 📱 主要內容區 -->
    <main v-else class="main-content">
      <transition name="fade" mode="out-in">
        <component 
          :is="currentView" 
          ref="currentViewRef" 
          @refresh-dashboard="handleRefreshDashboard" 
        />
      </transition>
    </main>

  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import liff from '@line/liff';
import { liffState } from './liffState';

// 引入 Views
import DashboardView from './views/DashboardView.vue';
import AccountManagerView from './views/AccountManagerView.vue';

// 環境變數
const LIFF_ID = import.meta.env.VITE_LIFF_ID;

// 頁面狀態
const currentTab = ref('Dashboard');
const currentViewRef = ref(null);

const currentView = computed(() => {
  if (currentTab.value === 'Dashboard') return DashboardView;
  if (currentTab.value === 'Accounts') return AccountManagerView;
  return null;
});

// 刷新邏輯
const handleRefreshDashboard = () => {
    if (currentView.value === DashboardView && currentViewRef.value?.refreshAllData) {
       currentViewRef.value.refreshAllData();
    }
};

// LIFF 初始化
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
        } else {
            liff.login(); 
        }
    } catch (err) {
        console.error('LIFF Error:', err);
        liffState.error = '初始化失敗，請檢查網路或 ID 設定。';
    }
});
</script>

<style scoped>
/* 佈局容器 */
.app-layout {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

/* --- 導航列設計 --- */
.navbar {
  background-color: var(--bg-nav);
  box-shadow: 0 2px 10px rgba(0,0,0,0.03); /* 極淡的陰影 */
  position: sticky;
  top: 0;
  z-index: 100;
  height: 64px;
  display: flex;
  align-items: center;
}

.nav-container {
  width: 100%;
  max-width: 1200px;
  margin: 0 auto;
  padding: 0 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

/* Brand */
.nav-brand {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 1.2rem;
  font-weight: 700;
  color: var(--text-accent); /* 暖棕色 */
}

/* Links */
.nav-links {
  display: flex;
  gap: 8px;
  background: #f7f5f0; /* 淺米色背景條 */
  padding: 4px;
  border-radius: 30px;
}

.nav-item {
  background: transparent;
  border: none;
  padding: 8px 20px;
  border-radius: 20px;
  color: var(--text-secondary);
  font-size: 0.95rem;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.3s ease;
}

.nav-item:hover {
  color: var(--text-primary);
}

.nav-item.active {
  background-color: #ffffff;
  color: var(--text-accent);
  box-shadow: 0 2px 8px rgba(0,0,0,0.05);
  font-weight: 600;
}

/* User Profile */
.nav-user {
  display: flex;
  align-items: center;
}

.user-profile {
  display: flex;
  align-items: center;
  gap: 12px;
}

.user-name {
  font-size: 0.9rem;
  color: var(--text-primary);
  display: none; /* 手機版隱藏名字 */
}

@media (min-width: 640px) {
  .user-name { display: block; }
}

.user-avatar {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  object-fit: cover;
  border: 2px solid #fff;
  box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.user-avatar.placeholder {
  background-color: #eee;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.7rem;
  color: #999;
}

/* --- 內容區 --- */
.main-content {
  flex: 1;
  width: 100%;
  max-width: 800px; /* 限制內容寬度，讓閱讀更舒適 */
  margin: 0 auto;
  padding: 24px 16px;
}

/* 狀態提示 */
.loading-screen {
  flex: 1;
  display: flex;
  justify-content: center;
  align-items: center;
  color: var(--text-secondary);
}

.error-banner {
  background-color: #ffeaea;
  color: #d67a7a;
  padding: 12px;
  text-align: center;
  font-size: 0.9rem;
}

/* 頁面切換動畫 */
.fade-enter-active, .fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from, .fade-leave-to {
  opacity: 0;
}
</style>