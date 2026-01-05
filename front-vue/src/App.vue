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
            <button @click="currentTab = 'Accounts'" :class="['nav-item', currentTab === 'Accounts' ? 'active' : '']">帳戶</button>
            <button @click="currentTab = 'Dashboard'" :class="['nav-item', currentTab === 'Dashboard' ? 'active' : '']">收支</button>
            <button @click="currentTab = 'Subscription'" :class="['nav-item', currentTab === 'Subscription' ? 'active' : '']">週期設定</button>
            <button @click="currentTab = 'Crypto'" :class="['nav-item', currentTab === 'Crypto' ? 'active' : '']">Crypto專區</button>
            <button @click="currentTab = 'Calculator'" :class="['nav-item', currentTab === 'Calculator' ? 'active' : '']">陪跑試算</button>
          </div>
          <div class="nav-user" @click="showUserMenu = !showUserMenu">
            <img 
              v-if="liffState.profile?.pictureUrl" 
              :src="liffState.profile.pictureUrl" 
              class="user-avatar" 
              alt="User"
            />
            <div v-else class="user-avatar-placeholder">?</div>

            <div v-if="showUserMenu" class="user-dropdown">
              <div class="menu-info">
                {{ liffState.profile?.displayName || '使用者' }}
              </div>
              <div class="menu-divider"></div>
              
              <button class="menu-item" @click="openSettings">
                個人設定
              </button>
              
              <div class="menu-divider"></div>
              <button class="menu-item logout-btn" @click.stop="handleLogout">
                登出
              </button>
            </div>
            <div v-if="showSettingsModal" class="modal-overlay" @click.self="showSettingsModal = false">
              <div class="modal-card">
                <h3>個人設定</h3>
                <div class="form-group link-section">
                  <label>帳號連結</label>
                  
                  <div v-if="isLineLinked" class="link-status connected">
                      LINE 帳號已綁定
                  </div>
                  <button v-else class="btn-link-line" @click="handleLinkLine">
                      綁定 LINE 帳號
                  </button>
                  <p v-if="!isLineLinked" class="link-hint">綁定後可使用 LINE 快速登入</p>
              </div>
                
                <div class="form-group">
                  <label>每月預算 (NT$)</label>
                  <input type="number" v-model="settingsForm.budget" class="modal-input" placeholder="0">
                </div>

                <div class="form-group">
                  <label>每日記帳提醒時間</label>
                  <input type="time" v-model="settingsForm.reminder_time" class="modal-input">
                  <p v-if="settingsForm.reminder_time && !isLineLinked" class="warning-text">
                    您尚未綁定 LINE 帳號，無法接收通知。
                  </p>
                </div>

                <div class="modal-actions">
                  <button class="btn-cancel" @click="showSettingsModal = false">取消</button>
                  <button class="btn-save" @click="saveSettings">儲存</button>
                </div>
              </div>
            </div>
            <div v-if="showUserMenu" class="dropdown-backdrop" @click.stop="showUserMenu = false"></div>
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

import CalculatorMain from './views/CalculatorMain.vue';

const LIFF_ID = import.meta.env.VITE_LIFF_ID || "2008601432-OmoVrl0l";
const API_URL = import.meta.env.VITE_API_BASE_URL || window.API_BASE_URL;

const currentTab = ref('Accounts');
const currentViewRef = ref(null);
const isLoading = ref(true); 
const isOnboarded = ref(false); 
const isGuestMode = ref(false); // ★ 新增：訪客模式狀態
const showUserMenu = ref(false);

const showSettingsModal = ref(false); // 控制設定視窗顯示
const settingsForm = ref({
  budget: 0,
  reminder_time: '21:00'
});

// 帳本相關狀態
const ledgers = ref([]);
const currentLedger = ref(null);
const showLedgerMenu = ref(false);

const currentView = computed(() => {
  if (currentTab.value === 'Dashboard') return DashboardView;
  if (currentTab.value === 'Accounts') return AccountManagerView;
  if (currentTab.value === 'Crypto') return CryptoView;
  if (currentTab.value === 'Subscription') return SubscriptionView;
  if (currentTab.value === 'Calculator') return CalculatorMain;
  return null;
});

const handleRefreshDashboard = () => {
    if (currentViewRef.value?.refreshAllData) {
       currentViewRef.value.refreshAllData();
    }
};

const isLineLinked = ref(false);

// ★ 新增：打開設定視窗 (並載入目前數值)
async function openSettings() {
  showUserMenu.value = false;
  isLoading.value = true;
  
  try {
    const response = await fetchWithLiffToken(`${API_URL}?action=get_user_status`);
    if (response && response.ok) {
      const result = await response.json();
      if (result.status === 'success') {
        settingsForm.value.budget = result.data.monthly_budget;
        settingsForm.value.reminder_time = result.data.reminder_time || '21:00';
        
        // 🟢 更新綁定狀態
        isLineLinked.value = result.data.has_line_linked;
      }
    }
    showSettingsModal.value = true;
  } catch (e) {
    alert("載入設定失敗");
  } finally {
    isLoading.value = false;
  }
}

// 🟢 [新增] 綁定 LINE 的處理函式
async function handleLinkLine() {
    // 1. ★ 修正：如果 LIFF 還沒初始化 (沒有 liff.id)，就現在初始化
    if (!liff.id) {
        try {
            // 顯示載入中，避免用戶以為卡住
            isLoading.value = true;
            await liff.init({ liffId: LIFF_ID });
        } catch (err) {
            console.error("LIFF Init Error:", err);
            alert("無法啟動 LINE 元件，請檢查網路或 LIFF ID 設定");
            isLoading.value = false;
            return;
        } finally {
            isLoading.value = false;
        }
    }

    // 2. 如果沒登入 LINE，先登入
    if (!liff.isLoggedIn()) {
        if (!confirm("即將跳轉至 LINE 進行身分驗證，完成後請再次點擊「綁定」按鈕。")) return;
        
        // 這裡一定要用 redirectUri 跳回來原本頁面
        liff.login({ redirectUri: window.location.href }); 
        return;
    }

    // 3. 取得 LINE Token
    const lineToken = liff.getIDToken();
    if (!lineToken) {
        alert("無法取得 LINE 驗證資訊 (Token 為空)");
        return;
    }

    // 4. 發送綁定請求
    if (!confirm("確定要綁定當前的 LINE 帳號嗎？")) return;

    try {
        isLoading.value = true; // 加個 loading 體驗更好
        const response = await fetchWithLiffToken(`${API_URL}?action=link_line`, {
            method: 'POST',
            body: JSON.stringify({ line_token: lineToken })
        });
        
        const result = await response.json();
        if (result.status === 'success') {
            alert("🎉 綁定成功！\n您現在可以用 LINE 登入此帳號了。");
            isLineLinked.value = true;
        } else {
            alert("綁定失敗：" + result.message);
        }
    } catch (e) {
        console.error(e);
        alert("連線錯誤");
    } finally {
        isLoading.value = false;
    }
}

// ★ 新增：儲存設定
async function saveSettings() {
  if (settingsForm.value.budget < 0) {
    alert("預算不能為負數");
    return;
  }

  try {
    const response = await fetchWithLiffToken(`${API_URL}?action=update_settings`, {
      method: 'POST',
      body: JSON.stringify(settingsForm.value)
    });
    
    const result = await response.json();
    if (result.status === 'success') {
      alert("設定已儲存！");
      showSettingsModal.value = false;
      // 觸發 Dashboard 重新整理以反映預算變化 (如果有監聽的話)
      handleRefreshDashboard(); 
    } else {
      alert("儲存失敗：" + result.message);
    }
  } catch (e) {
    console.error(e);
    alert("連線錯誤");
  }
}

// ★ 新增：登出函式
function handleLogout() {
  if (!confirm('確定要登出嗎？')) return;

  // 1. 清除所有 LocalStorage
  localStorage.removeItem('google_id_token');
  localStorage.removeItem('pending_onboarding');
  localStorage.removeItem('pending_join_token');

  // 2. 安全的 LIFF 登出
  // 只有當 LIFF 真的有登入時才執行 logout，避免報錯
  try {
    if (liff && liff.isLoggedIn && liff.isLoggedIn()) {
      liff.logout();
    }
  } catch (e) {
    console.warn('LIFF Logout skipped');
  }

  // 3. 重整頁面 -> 觸發 onMounted -> 進入登入畫面
  window.location.reload();
}

// 解析 JWT Token (取得 Google 用戶資訊)
function parseJwt(token) {
    try {
        const base64Url = token.split('.')[1];
        const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
        const jsonPayload = decodeURIComponent(window.atob(base64).split('').map(function(c) {
            return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
        }).join(''));
        return JSON.parse(jsonPayload);
    } catch (e) {
        return null;
    }
}

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
  // 1. 先存檔
  localStorage.setItem('pending_onboarding', JSON.stringify(data));
  
  // 2. ★ 修正：檢查是否已登入 (包含 Google 或 LINE)
  const isGoogleLoggedIn = !!localStorage.getItem('google_id_token');
  const isLineLoggedIn = liff.id && liff.isLoggedIn(); // 加 liff.id 檢查防止報錯

  if (!isGoogleLoggedIn && !isLineLoggedIn) {
    // 兩者都沒登入，才執行 LINE 登入跳轉
    const url = new URL(window.location.href);
    url.searchParams.delete('code');
    url.searchParams.delete('state');
    liff.login({ redirectUri: url.toString() });
  } else {
    // 已經登入 (不管是哪種)，直接送出資料
    await processPendingOnboarding();
  }
}

// front-vue/src/App.vue

async function processPendingOnboarding() {
  const pendingData = localStorage.getItem('pending_onboarding');
  
  if (pendingData) {
    try {
      const formData = JSON.parse(pendingData);
      
      const response = await fetchWithLiffToken(`${API_URL}?action=submit_onboarding`, {
        method: 'POST',
        body: JSON.stringify(formData)
      });

      // 只有在 API 回傳成功時才執行後續動作
      if (response && response.ok) {
        
        // 🔥 修正：確定成功後，才刪除暫存資料
        localStorage.removeItem('pending_onboarding');
        
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
      } else {
        // 如果失敗，印出警告，且「不刪除」localStorage，讓用戶重整後有機會重試
        console.warn('Onboarding API failed, keeping local data for retry.');
      }
    } catch (e) {
      console.error('Onboarding submission failed', e);
      // 發生錯誤也不刪除
    } 
    // ❌ 移除 finally 區塊，避免無條件刪除
  }
}

// ★ 抽離共用的資料載入邏輯
async function loadUserData() {
    try {
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
        
        // 處理 Pending Onboarding (如果是剛註冊完)
        await processPendingOnboarding();
        
    } catch (e) {
        console.error("Load User Data Error:", e);
    }
}

onMounted(async () => {
    // 1. 檢查網址參數 (保留原本邏輯)
    const urlParams = new URLSearchParams(window.location.search);
    const targetTab = urlParams.get('tab');
    if (targetTab && ['Dashboard', 'Accounts', 'Crypto'].includes(targetTab)) {
        currentTab.value = targetTab;
    }

    const inviteAction = urlParams.get('action');
    const inviteToken = urlParams.get('token'); 
    if (inviteAction === 'join_ledger' && inviteToken) {
        localStorage.setItem('pending_join_token', inviteToken);
        window.history.replaceState({}, document.title, window.location.pathname);
    }

    // ---------------------------------------------------------
    // ★ 修改開始：統一登入檢查邏輯
    // ---------------------------------------------------------
    
    // 2. 先檢查是否有 Google Token
    const googleToken = localStorage.getItem('google_id_token');
    
    if (googleToken) {
        // --- A. Google 登入流程 ---
        const payload = parseJwt(googleToken);
        if (payload) {
            // 設定狀態為已登入
            liffState.isLoggedIn = true;
            
            // 模擬 LIFF Profile 結構，讓畫面能顯示頭像
            liffState.profile = {
                userId: payload.sub,
                displayName: payload.name,
                pictureUrl: payload.picture,
                email: payload.email
            };

            // 執行後續資料載入
            await loadUserData(); 
        } else {
            // Token 格式錯誤或過期，清除它
            localStorage.removeItem('google_id_token');
        }
        
        // Google 登入處理完畢，停止 Loading
        isLoading.value = false;
        
    } else {
        // --- B. 如果沒有 Google Token，才執行 LIFF 初始化 ---
        if (!liff) {
            console.warn('LIFF SDK not loaded');
            // ★ 修改點：不要設定 error，直接結束 loading，這樣就會顯示登入頁
            isLoading.value = false;
            return;
        }

        try {
            // 嘗試初始化
            await liff.init({ liffId: LIFF_ID || "" }); // 防止 undefined 報錯
            
            if (liff.isLoggedIn()) {
                liffState.isLoggedIn = true;
                liffState.profile = await liff.getProfile();
                await loadUserData();
            }
        } catch (err) {
          console.warn('LIFF Init Failed (可能為純網頁模式):', err);
            // 只有在非 Google 登入且 LIFF 也失敗時才顯示錯誤，避免嚇到網頁版用戶
            // liffState.error = '連線失敗'; 
        } finally {
            // 不管 LIFF 成功還是失敗，都要把 Loading 關掉，讓用戶看到畫面
            isLoading.value = false;
        }
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

/* 用戶區域與游標 */
.nav-user {
  position: relative;
  cursor: pointer;
  margin-left: 8px;
}

.user-avatar-placeholder {
  width: 36px; height: 36px; background: #eee; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  color: #aaa; font-weight: bold;
}

/* 用戶下拉選單 (參考帳本選單樣式) */
.user-dropdown {
  position: absolute;
  top: 120%; right: 0; /* 靠右對齊 */
  background: white;
  border: 1px solid #eee;
  border-radius: 12px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.1);
  min-width: 160px;
  z-index: 1001;
  overflow: hidden;
  animation: fadeIn 0.2s ease;
}

.menu-info {
  padding: 12px 16px;
  font-size: 0.9rem;
  font-weight: bold;
  color: #555;
  background: #f9f9f9;
  border-bottom: 1px solid #eee;
}

.menu-divider {
  height: 1px; background: #eee; margin: 0;
}

.menu-item {
  width: 100%;
  text-align: left;
  background: none;
  border: none;
  padding: 12px 16px;
  font-size: 0.95rem;
  cursor: pointer;
  transition: background 0.2s;
}

.logout-btn {
  color: #ff4d4f; /* 紅色字體表示登出 */
  font-weight: 500;
}

.logout-btn:hover {
  background: #fff1f0;
}

/* 簡單的淡入動畫 */
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-5px); }
  to { opacity: 1; transform: translateY(0); }
}

/* Modal 樣式 */
.modal-overlay {
  position: fixed; top: 0; left: 0; width: 100%; height: 100%;
  background: rgba(0,0,0,0.5); z-index: 2000;
  display: flex; justify-content: center; align-items: center;
  padding: 20px;
}

.modal-card {
  background: white; width: 100%; max-width: 320px;
  border-radius: 16px; padding: 24px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.2);
  animation: slideUp 0.3s ease;
}

.modal-card h3 {
  margin: 0 0 20px 0; color: #5A483C; text-align: center;
}

.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 8px; font-size: 0.9rem; color: #666; }

.modal-input {
  width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;
  font-size: 1rem; outline: none;
  box-sizing: border-box; /* 重要：防止寬度爆版 */
}
.modal-input:focus { border-color: #d4a373; }

.modal-actions {
  display: flex; gap: 10px; margin-top: 24px;
}

.btn-cancel, .btn-save {
  flex: 1; padding: 10px; border-radius: 8px; border: none;
  font-weight: bold; cursor: pointer;
}
.btn-cancel { background: #f0f0f0; color: #666; }
.btn-save { background: #d4a373; color: white; }

@keyframes slideUp {
  from { transform: translateY(20px); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}

.link-section {
    border-top: 1px dashed #eee;
    padding-top: 16px;
    margin-top: 16px;
}

.btn-link-line {
    width: 100%;
    background-color: #06C755; /* LINE Green */
    color: white;
    border: none;
    padding: 10px;
    border-radius: 8px;
    font-weight: bold;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: background 0.2s;
}
.btn-link-line:hover {
    background-color: #05b34c;
}

.link-status.connected {
    background-color: #f0fdf4;
    color: #15803d;
    padding: 10px;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    text-align: center;
    border: 1px solid #bbf7d0;
}

.link-hint {
    font-size: 0.8rem;
    color: #999;
    margin-top: 6px;
    text-align: center;
}
.warning-text {
  color: #d9534f; /* 紅色警告 */
  font-size: 0.8rem;
  margin-top: 4px;
}
</style>