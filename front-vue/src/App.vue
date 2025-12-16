<template>
  <div class="app-layout">
    
    <div v-if="liffState.error" class="error-banner">
      <p>Error: {{ liffState.error }}</p>
    </div>

    <div v-else-if="isLoading" class="loading-container">
      <div class="spinner"></div>
      <p>FinBot 啟動中...</p>
    </div>

    <div v-else-if="!isGuestMode && (!liffState.isLoggedIn || !isOnboarded)" class="onboarding-container">
      <OnboardingView 
        @trigger-login="handleOnboardingLogin" 
        @login-direct="handleLoginDirect"
        @skip-login="handleSkipLogin"
      />
    </div>

    <div v-else class="authenticated-view">
      <nav class="navbar">
        <div class="nav-container">
          
          <div class="nav-brand-wrapper">
            <div class="brand-logo">FinBot</div>
            <div class="brand-divider">/</div>
            
            <!-- <button class="ledger-switch-btn" @click="toggleLedgerMenu">
              <span class="ledger-name">{{ currentLedger?.name || '我的帳本' }}</span>
              <span class="arrow">▼</span>
            </button>

            <div v-if="showLedgerMenu" class="ledger-dropdown">
              <div v-for="ledger in ledgers" :key="ledger.id" 
                   class="dropdown-item" 
                   :class="{ active: currentLedger?.id === ledger.id }"
                   @click="switchLedger(ledger)">
                <span class="ledger-type-tag">{{ ledger.type === 'personal' ? '個人' : '家庭' }}</span>
                <span class="item-name">{{ ledger.name }}</span>
                <span v-if="currentLedger?.id === ledger.id" class="check">✓</span>
              </div>
              <div class="dropdown-divider"></div>
              
              <div class="dropdown-item invite-action" @click="handleInviteMember">
                <span class="item-icon">🔗</span>
                <span class="item-name">邀請成員</span>
              </div>

              <div class="dropdown-item create-action" @click="createNewLedger">
                <span class="item-icon">+</span>
                <span class="item-name">建立新帳本</span>
              </div>
            </div>
            <div v-if="showLedgerMenu" class="dropdown-backdrop" @click="showLedgerMenu = false"></div> -->
          </div>

          <div class="nav-links">
            <button @click="currentTab = 'Dashboard'" :class="['nav-item', currentTab === 'Dashboard' ? 'active' : '']">收支</button>
            <button @click="currentTab = 'Subscription'" :class="['nav-item', currentTab === 'Subscription' ? 'active' : '']">週期設定</button>
            <button @click="currentTab = 'Accounts'" :class="['nav-item', currentTab === 'Accounts' ? 'active' : '']">帳戶</button>
            <button @click="currentTab = 'Crypto'" :class="['nav-item', currentTab === 'Crypto' ? 'active' : '']">Crypto專區</button>
          </div>
          <div class="nav-user">
            <img v-if="liffState.profile?.pictureUrl" :src="liffState.profile.pictureUrl" class="user-avatar" />
          </div>
        </div>
      </nav>

      <main class="main-content">
        <transition name="fade">
          <component 
            :is="currentView" 
            ref="currentViewRef" 
            :ledger-id="currentLedger?.id"
            @refresh-dashboard="handleRefreshDashboard" 
          />
        </transition>
      </main>

      <a href="https://line.me/R/ti/p/@finbot" target="_blank" class="fab-chat">
        <span class="fab-icon">💬</span><span class="fab-text">Line AI 記帳</span>
      </a>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import liff from '@line/liff';
import { liffState } from './liffState';
import { fetchWithLiffToken } from '@/utils/api';
import SubscriptionView from './views/SubscriptionView.vue';

// 引入元件
import OnboardingView from './views/OnboardingView.vue';
import DashboardView from './views/DashboardView.vue';
import AccountManagerView from './views/AccountManagerView.vue';
import CryptoView from './views/CryptoView.vue'; 

const LIFF_ID = import.meta.env.VITE_LIFF_ID;
const API_URL = import.meta.env.VITE_API_BASE_URL || window.API_BASE_URL;

const currentTab = ref('Dashboard');
const currentViewRef = ref(null);
const isLoading = ref(true); 
const isOnboarded = ref(false); 
const isGuestMode = ref(false); // ★ 新增：訪客模式狀態

// 帳本相關狀態
const ledgers = ref([]);
const currentLedger = ref(null);
const showLedgerMenu = ref(false);

const currentView = computed(() => {
  if (currentTab.value === 'Dashboard') return DashboardView;
  if (currentTab.value === 'Accounts') return AccountManagerView;
  if (currentTab.value === 'Crypto') return CryptoView;
  if (currentTab.value === 'Subscription') return SubscriptionView;
  return null;
});

const handleRefreshDashboard = () => {
    if (currentViewRef.value?.refreshAllData) {
       currentViewRef.value.refreshAllData();
    }
};

// --- 帳本操作邏輯 ---

function toggleLedgerMenu() {
  showLedgerMenu.value = !showLedgerMenu.value;
}

async function fetchLedgers() {
  const response = await fetchWithLiffToken(`${API_URL}?action=get_ledgers`);
  if (response && response.ok) {
    const result = await response.json();
    if (result.status === 'success') {
      ledgers.value = result.data;
      
      // 如果還沒選過帳本，預設選第一個 (通常是個人帳本)
      if (!currentLedger.value && ledgers.value.length > 0) {
        currentLedger.value = ledgers.value[0];
      }
    }
  }
}

function switchLedger(ledger) {
  currentLedger.value = ledger;
  showLedgerMenu.value = false;
  handleRefreshDashboard();
}

async function createNewLedger() {
  const name = prompt("請輸入新帳本名稱 (例如：甜蜜的家、公司報帳)：");
  if (!name) return;
  
  showLedgerMenu.value = false;
  try {
    const response = await fetchWithLiffToken(`${API_URL}?action=create_ledger`, {
      method: 'POST',
      body: JSON.stringify({ name: name })
    });
    const result = await response.json();
    if (result.status === 'success') {
      alert("建立成功！");
      await fetchLedgers(); 
      const newLedger = ledgers.value.find(l => l.id == result.data.id);
      if (newLedger) switchLedger(newLedger);
    } else {
      alert("建立失敗：" + result.message);
    }
  } catch (e) {
    console.error(e);
    alert("連線錯誤");
  }
}

// 邀請成員加入
async function handleInviteMember() {
  if (!currentLedger.value) return;
  
  if (currentLedger.value.type === 'personal') {
      alert("個人帳本無法邀請成員，請先建立或切換至家庭/共用帳本。");
      return;
  }

  showLedgerMenu.value = false;

  try {
    // 呼叫後端產生連結
    const response = await fetchWithLiffToken(`${API_URL}?action=generate_invite_link&ledger_id=${currentLedger.value.id}`, {
        method: 'POST'
    });
    
    const result = await response.json();
    
    if (result.status === 'success') {
        const inviteUrl = result.data.invite_url;
        
        // 準備 Flex Message
        const flexMessage = {
            type: "flex",
            altText: "邀請您加入共用帳本",
            contents: {
                type: "bubble",
                body: {
                    type: "box", layout: "vertical", spacing: "md",
                    contents: [
                        { type: "text", text: "共用帳本邀請", weight: "bold", size: "xl", color: "#d4a373" },
                        { type: "text", text: `邀請您加入「${currentLedger.value.name}」一起記帳。`, wrap: true, color: "#666666" },
                        { type: "separator" },
                        { type: "text", text: "連結有效期限：24小時", size: "xs", color: "#aaaaaa" }
                    ]
                },
                footer: {
                    type: "box", layout: "vertical",
                    contents: [
                        {
                            type: "button", style: "primary", color: "#d4a373",
                            action: { type: "uri", label: "立即加入", uri: inviteUrl }
                        }
                    ]
                }
            }
        };

        // 使用 LIFF ShareTargetPicker 發送
        if (liff.isApiAvailable('shareTargetPicker')) {
            const res = await liff.shareTargetPicker([flexMessage]);
            if (res) {
                alert("邀請已發送！");
            }
        } else {
            // 如果不支援 (例如電腦版)，改用複製連結
            prompt("請複製以下連結傳給好友：", inviteUrl);
        }
    } else {
        alert("產生邀請失敗：" + result.message);
    }
  } catch (e) {
    console.error(e);
    alert("連線錯誤");
  }
}

// 加入帳本
async function joinLedger(token) {
    isLoading.value = true;
    try {
        const response = await fetchWithLiffToken(`${API_URL}?action=join_ledger`, {
            method: 'POST',
            body: JSON.stringify({ token: token })
        });
        const result = await response.json();
        
        if (result.status === 'success') {
            alert(`🎉 成功加入帳本：${result.data.ledger_name}`);
            await fetchLedgers();
            // 重整頁面以確保資料同步
            window.location.href = window.location.pathname; 
        } else {
            alert(`加入失敗：${result.message}`);
        }
    } catch (e) {
        console.error(e);
        alert("加入過程發生錯誤");
    } finally {
        isLoading.value = false;
    }
}

// --- 引導與登入邏輯 ---

// ★ 新增：處理老用戶直接登入 (含 400 錯誤修復)
function handleLoginDirect() {
  if (!liff.isLoggedIn()) {
    // 1. 取得最乾淨的網址 (例如 https://finbot.tw/ 或 https://finbot.tw/index.html)
    // 這樣可以確保不會帶有 ?code=... 或 ?tab=... 等雜訊
    const cleanRedirectUri = window.location.origin + window.location.pathname;

    // 2. 使用這個乾淨網址進行登入
    // ★ 注意：這個網址必須要在 LINE Console 設定過 (詳見下一步)
    liff.login({ redirectUri: cleanRedirectUri });
  } else {
    window.location.reload();
  }
}

// ★ 新增：處理訪客略過登入
function handleSkipLogin() {
  isGuestMode.value = true;
}

async function handleOnboardingLogin(data) {
  localStorage.setItem('pending_onboarding', JSON.stringify(data));
  if (!liff.isLoggedIn()) {
    // 使用乾淨網址登入
    const url = new URL(window.location.href);
    url.searchParams.delete('code');
    url.searchParams.delete('state');
    liff.login({ redirectUri: url.toString() });
  } else {
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
        isOnboarded.value = true; 
        
        // 檢查是否有暫存的加入 Token
        const pendingToken = localStorage.getItem('pending_join_token');
        if (pendingToken) {
            localStorage.removeItem('pending_join_token');
            await joinLedger(pendingToken);
        } else {
            alert('歡迎加入！已成功開通。');
        }

        await fetchLedgers(); 
        handleRefreshDashboard();
      }
    } catch (e) {
      console.error('Onboarding submission failed', e);
    } finally {
      localStorage.removeItem('pending_onboarding');
    }
  }
}

onMounted(async () => {
    // 1. 檢查網址參數
    const urlParams = new URLSearchParams(window.location.search);
    const targetTab = urlParams.get('tab');
    if (targetTab && ['Dashboard', 'Accounts', 'Crypto'].includes(targetTab)) {
        currentTab.value = targetTab;
    }

    // 偵測邀請連結參數
    const inviteAction = urlParams.get('action');
    const inviteToken = urlParams.get('token'); 
    
    if (inviteAction === 'join_ledger' && inviteToken) {
        localStorage.setItem('pending_join_token', inviteToken);
        window.history.replaceState({}, document.title, window.location.pathname);
    }

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

                // 獲取用戶狀態
                const statusResponse = await fetchWithLiffToken(`${API_URL}?action=get_user_status`);
                if (statusResponse && statusResponse.ok) {
                    const result = await statusResponse.json();
                    if (result.status === 'success') {
                        isOnboarded.value = Number(result.data.is_onboarded) === 1;
                    }
                }
                
                // 處理暫存的加入請求
                const pendingToken = localStorage.getItem('pending_join_token');
                if (pendingToken) {
                    localStorage.removeItem('pending_join_token');
                    await joinLedger(pendingToken);
                }

                // 獲取帳本列表
                if (isOnboarded.value) {
                    await fetchLedgers();
                }

            } catch (pErr) {
                console.warn('Init Data Error', pErr);
            }
            
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
/* 保留原有樣式 */
.onboarding-container, .loading-container { min-height: 100vh; min-height: 100dvh; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 20px; }
.navbar { background-color: var(--bg-nav); box-shadow: 0 2px 10px rgba(0,0,0,0.03); position: sticky; top: 0; z-index: 100; height: 60px; display: flex; align-items: center; width: 100%; }
.nav-container { width: 100%; max-width: 800px; margin: 0 auto; padding: 0 16px; display: flex; justify-content: space-between; align-items: center; position: relative;}

.nav-brand-wrapper { 
  position: relative; 
  display: flex; 
  align-items: center; 
  gap: 6px; 
}

.brand-logo {
  font-weight: 800;
  font-size: 1.1rem;
  color: #d4a373; 
  letter-spacing: 0.5px;
}

.brand-divider {
  color: #e0e0e0;
  font-size: 1rem;
  font-weight: 300;
  margin-top: -2px;
}

.ledger-switch-btn {
  background: none; border: none; padding: 0;
  display: flex; align-items: center; gap: 4px;
  cursor: pointer; color: var(--text-primary);
  font-size: 1rem; font-weight: 600; 
}
.ledger-name { max-width: 100px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.arrow { font-size: 0.7rem; color: #aaa; margin-top: 2px; }

.ledger-dropdown {
  position: absolute; top: 100%; left: 0;
  background: white; border: 1px solid #eee;
  border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);
  width: 200px; z-index: 1001; margin-top: 8px;
  padding: 4px 0;
}
.dropdown-item {
  padding: 10px 16px; display: flex; align-items: center; gap: 8px;
  cursor: pointer; font-size: 0.9rem; color: #555;
  transition: background 0.2s;
}
.dropdown-item:hover { background: #f9f7f2; }
.dropdown-item.active { color: #d4a373; font-weight: bold; background: #fff8f0; }
.ledger-type-tag {
  font-size: 0.7rem; background: #eee; padding: 2px 6px; border-radius: 4px; color: #888;
}
.dropdown-item.active .ledger-type-tag { background: #d4a373; color: white; }
.dropdown-divider { height: 1px; background: #eee; margin: 4px 0; }
.create-action { color: #d4a373; font-weight: 600; }

.invite-action { color: #2A9D8F; font-weight: 600; }
.invite-action:hover { background-color: #e6fcf5; }

.check { margin-left: auto; color: #d4a373; }
.dropdown-backdrop {
  position: fixed; top: 0; left: 0; width: 100%; height: 100%;
  z-index: 1000; background: transparent; cursor: default;
}

.nav-links { display: flex; gap: 4px; background: #f7f5f0; padding: 4px; border-radius: 30px; flex-shrink: 1; white-space: nowrap; }
.nav-item { background: transparent; border: none; padding: 6px 12px; border-radius: 20px; color: var(--text-secondary); font-size: 0.85rem; font-weight: 500; cursor: pointer; transition: all 0.3s ease; }
.nav-item.active { background-color: #ffffff; color: var(--text-accent); box-shadow: 0 2px 8px rgba(0,0,0,0.05); font-weight: 600; }
.nav-user { display: flex; align-items: center; flex-shrink: 0; }
.user-avatar { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 2px solid #fff; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
.main-content { flex: 1; width: 100%; max-width: 800px; margin: 0 auto; padding: 20px 16px; }
.fab-chat { position: fixed; bottom: 24px; right: 20px; background-color: #1DB446; color: white; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 12px 20px; border-radius: 50px; box-shadow: 0 4px 12px rgba(29, 180, 70, 0.4); text-decoration: none; z-index: 999; transition: transform 0.2s, box-shadow 0.2s; }
.fab-chat:active { transform: scale(0.95); }
.error-banner { background-color: #ffeaea; color: #d67a7a; padding: 12px; text-align: center; font-size: 0.9rem; }
.spinner { width: 40px; height: 40px; border: 4px solid #e0e0e0; border-top-color: var(--text-accent); border-radius: 50%; animation: spin 1s linear infinite; margin-bottom: 16px; }
@keyframes spin { to { transform: rotate(360deg); } }
.loading-container p { color: var(--text-primary); font-weight: 500; font-size: 0.95rem; }
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
@media (max-width: 480px) {
  .nav-container { padding: 0 8px; }
  .ledger-switch-btn { font-size: 0.9rem; }
  .nav-item { padding: 5px 8px; font-size: 0.8rem; }
  .nav-links { gap: 2px; }
  .user-avatar { width: 32px; height: 32px; }
  .main-content { padding: 16px 12px; }
  .brand-logo { font-size: 1rem; } 
}
</style>