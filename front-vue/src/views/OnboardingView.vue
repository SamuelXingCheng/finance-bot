<template>
  <div class="onboarding-overlay">
    <div class="wizard-card">
      
      <div class="progress-bar">
        <div class="progress-fill" :style="{ width: Math.min((step / 5) * 100, 100) + '%' }"></div>
      </div>

      <div v-if="step === 1" class="step-content text-center">
  
        <div v-if="!showLoginMode">
          <div class="logo-circle">Fin</div>
          <h2>歡迎使用 FinBot！</h2>
          <p class="desc">動動口就能記帳，<br>結合 AI 分析與資產管理的最佳夥伴。</p>
          
          <button class="btn-primary" @click="nextStep">開始體驗</button>

          <div class="terms-note mt-3">
            <label class="checkbox-label-sm">
              <input type="checkbox" v-model="form.agreed">
              <span>我同意 <a href="#" @click.prevent="showTerms = true">服務條款與隱私政策</a></span>
            </label>
          </div>

          <button class="btn-link mt-4" @click="switchToLoginMode">
            我是老用戶，直接登入
          </button>
        </div>

        <div v-else>
          <h2>歡迎回來</h2>
          <p class="desc">請選擇您的登入方式</p>

          <button class="btn-primary btn-login" @click="emit('login-direct')">
            LINE 登入
          </button>

          <div class="divider">或</div>

          <div id="google-btn-step1" class="google-btn-container"></div>

          <button class="btn-link mt-4" @click="showLoginMode = false">
            &larr; 返回
          </button>
        </div>

      </div>

      <div v-else-if="step === 2" class="step-content text-center">
        <div class="icon">✨</div>
        <h2>記帳，可以很懶惰</h2>
        <p class="desc">支援語音、截圖、甚至信用卡帳單。</p>
        
        <div class="demo-tabs">
          <button :class="{ active: demoMode === 'text' }" @click="setDemoMode('text')">⌨️ 文字</button>
          <button :class="{ active: demoMode === 'voice' }" @click="setDemoMode('voice')">🎙️ 語音</button>
          <button :class="{ active: demoMode === 'image' }" @click="setDemoMode('image')">📸 截圖</button>
        </div>

        <div class="demo-box interactive">
          <div v-if="demoStage === 0" class="demo-placeholder" @click="playDemo">
            <span v-if="demoMode === 'text'">👇 點我試試：輸入 "午餐 150"</span>
            <span v-if="demoMode === 'voice'">👇 點我試試：說出 "計程車 300"</span>
            <span v-if="demoMode === 'image'">👇 點我試試：上傳 "UberEats 訂單截圖"</span>
          </div>

          <transition name="fade">
            <div v-if="demoStage >= 1" class="chat-bubble user">
              <span v-if="demoMode === 'text'">午餐 150</span>
              <span v-if="demoMode === 'voice'">(( 🎤 計程車三百元... ))</span>
              <span v-if="demoMode === 'image'" class="img-preview">🧾 [訂單截圖.jpg]</span>
            </div>
          </transition>
          
          <transition name="fade">
            <div v-if="demoStage >= 2" class="chat-bubble bot">
              <div v-if="demoMode === 'text'">✅ <b>已記錄</b><br>飲食 $150</div>
              <div v-else-if="demoMode === 'voice'">✅ <b>已記錄</b><br>交通 $300</div>
              <div v-else>
                  ✅ <b>辨識成功</b><br>
                  類別：飲食<br>
                  金額：$240<br>
                  <span class="highlight-xs"> ✨ 圖片自動辨識</span>
              </div>
            </div>
          </transition>
        </div>

        <button class="btn-primary" @click="nextStep" :disabled="demoStage < 2">
          {{ demoStage < 2 ? '請先試玩上方功能' : '太酷了！下一步' }}
        </button>
      </div>

      <div v-else-if="step === 3" class="step-content">
        <h2>您的主要目標？</h2>
        <p class="desc">FinBot 將為您開啟對應的專屬功能。</p>
        
        <div class="radio-options">
          <label class="option-card" :class="{ selected: form.goal === 'fin' }">
            <input type="radio" v-model="form.goal" value="fin">
            <div>
              <div class="opt-title">生活平衡</div>
              <div class="opt-sub">想要輕鬆記帳，養成好習慣</div>
            </div>
          </label>
          <label class="option-card" :class="{ selected: form.goal === 'analyze' }">
            <input type="radio" v-model="form.goal" value="analyze">
            <div>
              <div class="opt-title">消費分析</div>
              <div class="opt-sub">想知道錢花去哪，控制開銷</div>
            </div>
          </label>
          <label class="option-card" :class="{ selected: form.goal === 'control' }">
            <input type="radio" v-model="form.goal" value="control">
            <div>
              <div class="opt-title">資產增值</div>
              <div class="opt-sub">管理股票、Crypto 與淨資產</div>
            </div>
          </label>
        </div>

        <transition name="fade">
          <div v-if="form.goal" class="feature-preview-card">
            <div class="fp-icon">{{ goalFeatures[form.goal].icon }}</div>
            <div class="fp-text">
              <div class="fp-title">推薦功能：{{ goalFeatures[form.goal].title }}</div>
              <div class="fp-desc">{{ goalFeatures[form.goal].desc }}</div>
            </div>
          </div>
        </transition>

        <button class="btn-primary" :disabled="!form.goal" @click="nextStep">下一步</button>
      </div>

      <div v-else-if="step === 4" class="step-content">
        <h2>個人化設定</h2>
        <p class="desc">設定預算與提醒，讓我們當您的理財管家。</p>
        
        <label class="section-label">每月預算</label>
        <div class="quick-budget-options">
          <button 
            v-for="amount in [10000, 35000, 50000]" 
            :key="amount"
            type="button"
            class="btn-outline-sm"
            :class="{ active: form.budget === amount }"
            @click="form.budget = amount"
          >
            ${{ amount.toLocaleString() }}
          </button>
        </div>
        <div class="input-wrapper mb-4">
          <span class="prefix">NT$</span>
          <input 
            type="number" 
            v-model="form.budget" 
            class="input-lg" 
            placeholder="或手動輸入金額"
          >
        </div>

        <label class="section-label">每日記帳提醒</label>
        <div class="time-selector-container compact">
          <div class="select-wrapper">
            <select v-model="selectedHour" @change="updateTime" class="custom-select">
              <option v-for="h in hours" :key="h" :value="h">{{ h }}</option>
            </select>
          </div>
          <span class="colon">:</span>
          <div class="select-wrapper">
            <select v-model="selectedMinute" @change="updateTime" class="custom-select">
              <option v-for="m in minutes" :key="m" :value="m">{{ m }}</option>
            </select>
          </div>
        </div>
        
        <button class="btn-primary" :disabled="!form.budget" @click="nextStep">完成設定</button>
      </div>

      <div v-else-if="step === 5" class="step-content text-center">

        <h2>恭喜完成！</h2>
        
        <div class="reward-card">
            <p class="reward-label">🎉 新手專屬好禮</p>
            <p class="reward-amount">7 天 PRO 會員試用</p>
            <p class="reward-sub">+ FinPoints 50 點 (可抵扣訂閱)</p>
        </div>

        <div class="unlock-info">
            <p class="unlock-title">試用期間您將擁有：</p>
            <ul class="unlock-list">
              <li>🚀 無限次 AI 記帳與資產分析</li>
              <li>📊 解鎖完整財務報表</li>
              <li>☁️ 雲端自動備份</li>
            </ul>
            <p class="unlock-note">* 試用結束後將自動轉為免費版，不會自動扣款。</p>
        </div>
        
        <div class="spacer"></div>

        <div class="login-actions">
            <div v-if="!isUserLoggedIn">
                <button class="btn-primary btn-login" @click="emitLogin">
                    LINE 登入並領取
                </button>
                
                <div class="divider">或</div>

                <div id="google-btn-wrapper" class="google-btn-container"></div>
                
                <p class="login-note">點擊將跳轉至授權頁面</p>
                
                <button class="btn-link mt-2" @click="emit('skip-login')">
                  先不登入，僅看看網頁 &rarr;
                </button>
            </div>

            <div v-else>
                <button class="btn-primary" @click="emitLogin">
                    🚀 開始使用 FinBot
                </button>
                <p class="login-note mt-2">將為您開通帳號並套用設定</p>
            </div>

        </div>
      </div>

    </div>

    <div v-if="showTerms" class="terms-modal-overlay" @click.self="showTerms = false">
      <div class="terms-card">
        <h3>使用條款與隱私權政策</h3>
        <div class="terms-content">
          <h4>1. 隱私權政策適用範圍</h4>
          <p>歡迎使用 FinBot（以下簡稱「本服務」）。本隱私權政策說明我們如何收集、使用、揭露及保護您在使用本服務（包括記帳、資產管理及 AI 財務分析功能）時提供的個人資料。使用本服務即代表您同意本政策之條款。</p>
          <h4>2. 我們收集的資料類型</h4>
          <p>為了提供精準的財務分析與記帳服務，我們可能會收集以下資料：<br>• <strong>個人識別資訊：</strong>如您的暱稱、電子郵件地址或社群帳號 ID（如 LINE User ID）。<br>• <strong>財務數據：</strong>您主動輸入的收支記錄、資產狀況、預算設定及交易類別。<br>• <strong>使用行為：</strong>您與聊天機器人的互動記錄、功能使用頻率及錯誤報告。</p>
          <h4>3. 資料使用方式</h4>
          <p>我們收集的資料僅用於以下用途：<br>• 提供記帳功能、產生財務報表及資產圖表。<br>• 透過 AI 演算法分析您的消費習慣並提供理財教育。<br>• 進行系統維護、資料備份及服務優化。<br>• 除非取得您的同意或法律要求，我們絕不會將您的財務數據出售給第三方。</p>
          <h4>4. AI 分析與自動化決策</h4>
          <p>本服務使用人工智慧技術進行數據分析。請注意，AI 生成的建議（如「減少外食開銷」或「資產配置建議」）僅供參考，不構成專業的投資顧問意見。在做出重大財務決策前，請務必諮詢專業人士。</p>
          <h4>5. 資料存儲與安全</h4>
          <p>我們致力於保護您的資料安全。您的財務數據在傳輸與存儲過程中均採用加密技術（如 SSL/TLS）保護。我們使用安全的雲端伺服器存儲資料，並設有嚴格的存取權限控制。</p>
          <h4>6. 您的權利</h4>
          <p>針對您的個人資料，您享有以下權利：<br>• <strong>查詢與閱覽：</strong>您可以隨時查詢您的記帳紀錄。<br>• <strong>下載備份：</strong>您可以要求匯出您的記帳資料。<br>• <strong>刪除權（被遺忘權）：</strong>若您決定停止使用本服務，您可以隨時聯繫我們要求刪除所有與您相關的帳號及財務數據。</p>
          <h4>7. 隱私權政策之修訂</h4>
          <p>本服務有權隨時修訂本隱私權政策。修訂後的條款將公佈於本服務頁面，重大變更時我們將透過機器人推播或電子郵件通知您。</p>
          <h4>8. 聯絡我們</h4>
          <p>若您對本隱私權政策或資料處理方式有任何疑問，請透過客服信箱 support@finbot.tw 與我們聯繫。</p>
        </div>
        <button class="btn-close" @click="showTerms = false">關閉</button>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, reactive, watch, nextTick, onMounted, computed } from 'vue';
import { liffState } from '../liffState';

const emit = defineEmits(['trigger-login', 'login-direct', 'skip-login']);
const showLoginMode = ref(false);

const step = ref(1);
const showTerms = ref(false);

const form = reactive({
  agreed: false,
  goal: '', 
  budget: null,
  reminder_time: '21:00'
});

const isUserLoggedIn = computed(() => {
  return liffState.isLoggedIn || !!localStorage.getItem('google_id_token');
});

// --- 互動示範 (Step 2) ---
const demoMode = ref('text'); // text, voice, image
const demoStage = ref(0); // 0:未開始, 1:用戶輸入, 2:AI回覆

function setDemoMode(mode) {
  demoMode.value = mode;
  demoStage.value = 0; // 重置動畫
}

function playDemo() {
  demoStage.value = 1;
  setTimeout(() => {
    demoStage.value = 2;
  }, 800); // 模擬 AI 思考時間
}

// --- 目標對應的功能文案 (Step 3) ---
const goalFeatures = {
  fin: {
    icon: '🧘',
    title: '訂閱管理 & 習慣養成',
    desc: '自動偵測週期性扣款，幫您揪出沒在用的訂閱服務。'
  },
  analyze: {
    icon: '💳',
    title: '信用卡帳單匯入',
    desc: '支援 CSV/PDF 帳單匯入，一秒紀錄上百筆消費，無需手動輸入。'
  },
  control: {
    icon: '📈',
    title: '淨資產趨勢分析',
    desc: '整合現金、股票與加密貨幣 (Crypto)，視覺化您的財富增長曲線。'
  }
};

// --- 時間選擇器 ---
const hours = Array.from({ length: 24 }, (_, i) => i.toString().padStart(2, '0'));
const minutes = Array.from({ length: 60 }, (_, i) => i.toString().padStart(2, '0'));
const selectedHour = ref('21');
const selectedMinute = ref('00');

function updateTime() {
  form.reminder_time = `${selectedHour.value}:${selectedMinute.value}`;
}

// --- Google 登入按鈕渲染 ---
function renderGoogleBtn(elementId) {
  if (window.google) {
    // 請填入您的 Client ID
    const clientId = "251064690633-qgktj8rrpjf3fiqbtqntou7hk32q9e8t.apps.googleusercontent.com"; 

    window.google.accounts.id.initialize({
      client_id: clientId,
      callback: handleGoogleCredentialResponse
    });
    
    window.google.accounts.id.renderButton(
      document.getElementById(elementId),
      { theme: "outline", size: "large", width: "100%" }
    );
  }
}

// --- Google 登入回調 (含資料保護修正) ---
function handleGoogleCredentialResponse(response) {
    // 1. 存入 Google Token
    localStorage.setItem('google_id_token', response.credential);
    
    // 2. 判斷是「新手」還是「老手」
    if (!showLoginMode.value) {
        // [新手模式 - Step 5]
        // 用戶剛填完問卷，我們要存檔，讓 App.vue 幫他送出
        localStorage.setItem('pending_onboarding', JSON.stringify(form));
    } else {
        // [老手模式 - Step 1]
        // 老用戶直接登入，清除任何殘留的引導資料
        localStorage.removeItem('pending_onboarding');
    }
    
    // 3. 重新整理
    window.location.reload(); 
}

// --- 監聽切換事件，渲染按鈕 ---
watch(showLoginMode, async (val) => {
  if (val) {
    await nextTick();
    renderGoogleBtn("google-btn-step1");
  }
});

watch(step, async (newVal) => {
    if (newVal === 5) { // 改為 Step 5 (最後一步)
        await nextTick();
        renderGoogleBtn("google-btn-wrapper"); 
    }
});

// --- 流程控制 ---
function nextStep() {
  if (step.value === 1 && !form.agreed) {
    alert("請先同意服務條款");
    return;
  }
  if (step.value < 5) {
    step.value++;
  }
}

function switchToLoginMode() {
  showLoginMode.value = true;
  // 切換到登入模式時，順手清乾淨，確保狀態純淨
  localStorage.removeItem('pending_onboarding');
}

function emitLogin() {
  // 發出事件前先存檔
  localStorage.setItem('pending_onboarding', JSON.stringify(form));
  emit('trigger-login', form);
}

onMounted(() => {
    // 檢查是否有 Google Token 或 LIFF 登入
    const isGoogle = !!localStorage.getItem('google_id_token');
    // 簡單判斷：如果全域狀態是已登入，或者本地有 Token
    if (liffState.isLoggedIn || isGoogle) {
        // ★ 關鍵：已登入用戶直接從 Step 2 開始，補填資料
        step.value = 2; 
    }
});

</script>

<style scoped>
/* 基礎樣式 (保留大部分) */
.onboarding-overlay {
  position: fixed; top: 0; left: 0; width: 100%; height: 100%;
  background: #f9f7f2; z-index: 9999;
  display: flex; justify-content: center; align-items: center;
  padding: 20px;
}

.wizard-card {
  background: white; width: 100%; max-width: 380px;
  padding: 30px 24px; 
  border-radius: 24px; 
  box-shadow: 0 10px 40px rgba(212, 163, 115, 0.25), 0 2px 10px rgba(0,0,0,0.05);
  text-align: left; position: relative; overflow: hidden;
  border: 1px solid rgba(212, 163, 115, 0.1); 
}
.text-center { text-align: center; }

/* Logo */
.logo-circle {
  width: 70px; height: 70px;
  background: #d4a373;
  color: white;
  font-size: 1.2rem;
  font-weight: bold;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 20px auto;
  box-shadow: 0 4px 15px rgba(212, 163, 115, 0.4);
}
/* 進度條 */
.progress-bar { position: absolute; top: 0; left: 0; width: 100%; height: 4px; background: #eee; }
.progress-fill { height: 100%; background: #d4a373; transition: width 0.3s ease; }

/* 文字樣式 */
h2 { color: #8c7b75; margin: 0 0 12px 0; font-size: 1.4rem; }
.desc { color: #666; line-height: 1.6; margin-bottom: 24px; }
.icon { font-size: 3rem; margin-bottom: 16px; display: block; }

/* 按鈕 */
.btn-primary {
  width: 100%; padding: 14px; border-radius: 12px; border: none;
  background: #d4a373; color: white; font-size: 1rem; font-weight: bold;
  cursor: pointer; transition: background 0.2s; margin-top: 20px;
}
.btn-primary:disabled { background: #e0d0c0; cursor: not-allowed; }
.btn-primary:active { transform: scale(0.98); }

.btn-link {
  background: none; border: none; color: #8c7b75; text-decoration: underline;
  cursor: pointer; font-size: 0.9rem; width: 100%; display: inline-block;
  transition: opacity 0.2s;
}
.btn-link:hover { opacity: 0.7; }

/* Step 1 條款勾選 */
.terms-note { font-size: 0.9rem; color: #666; display: flex; justify-content: center; }
.checkbox-label-sm { display: flex; align-items: center; gap: 6px; cursor: pointer; }
.checkbox-label-sm a { color: #d4a373; text-decoration: underline; }

/* Step 2: 互動 Demo 樣式 */
.demo-tabs { display: flex; justify-content: center; gap: 8px; margin-bottom: 12px; }
.demo-tabs button {
  background: #f0f0f0; border: none; padding: 6px 12px; border-radius: 20px;
  font-size: 0.9rem; color: #888; cursor: pointer; transition: all 0.2s;
}
.demo-tabs button.active {
  background: #d4a373; color: white; font-weight: bold; box-shadow: 0 2px 6px rgba(212, 163, 115, 0.3);
}
.demo-box { background: #f4f6f8; padding: 15px; border-radius: 12px; margin: 10px 0; text-align: left; min-height: 100px; display: flex; flex-direction: column; justify-content: center;}
.demo-placeholder {
  color: #a98467; font-weight: bold; cursor: pointer; padding: 20px; text-align: center;
  border: 2px dashed #e0e0e0; border-radius: 12px; background: white; width: 100%;
}
.demo-placeholder:hover { background: #fffbf5; border-color: #d4a373; }
.img-preview { font-size: 0.85rem; color: #555; display: flex; align-items: center; gap: 4px; }
.highlight-xs { font-size: 0.7rem; background: #fff8f0; color: #d4a373; padding: 2px 4px; border-radius: 4px; }
.chat-bubble { padding: 8px 12px; border-radius: 16px; width: fit-content; margin-bottom: 8px; font-size: 0.9rem; }
.chat-bubble.user { background: #d4a373; color: white; margin-left: auto; border-bottom-right-radius: 4px; }
.chat-bubble.bot { background: white; color: #333; border: 1px solid #eee; border-bottom-left-radius: 4px; }

/* Step 3: 目標卡片與預覽 */
.option-card {
  display: flex; align-items: center; padding: 12px 15px;
  border: 1px solid #eee; border-radius: 12px; margin-bottom: 10px;
  cursor: pointer; transition: all 0.2s; background: white;
}
.option-card.selected { border-color: #d4a373; background: #fff8f0; }
.option-card input { display: none; }
.opt-title { font-weight: bold; color: #555; font-size: 0.95rem; }
.opt-sub { font-size: 0.8rem; color: #999; }
.option-card.selected .opt-title { color: #d4a373; }
.feature-preview-card {
  margin-top: 16px; background: #fdfcf8; border: 1px solid #efeadd;
  border-radius: 12px; padding: 12px; display: flex; gap: 12px; align-items: flex-start;
  animation: slideUp 0.3s ease;
}
.fp-icon { font-size: 1.5rem; background: #fff; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 50%; box-shadow: 0 2px 5px rgba(0,0,0,0.05); flex-shrink: 0;}
.fp-text { text-align: left; }
.fp-title { font-weight: bold; color: #d4a373; font-size: 0.9rem; margin-bottom: 2px; }
.fp-desc { font-size: 0.8rem; color: #777; line-height: 1.4; }

/* Step 4: 預算與時間 */
.section-label { display: block; font-weight: bold; color: #555; margin-bottom: 8px; font-size: 0.95rem; }
.quick-budget-options { display: flex; gap: 10px; margin-bottom: 10px; justify-content: space-between; }
.btn-outline-sm {
  flex: 1; padding: 8px 4px; border: 1px solid #d4a373; border-radius: 8px;
  background: white; color: #d4a373; font-size: 0.85rem; font-weight: 600; cursor: pointer;
}
.btn-outline-sm.active { background: #d4a373; color: white; }
.input-wrapper { display: flex; align-items: center; border-bottom: 2px solid #eee; padding: 5px; }
.input-lg { width: 100%; border: 1px solid #ddd; padding: 10px; font-size: 1.1rem; border-radius: 8px; outline: none; box-sizing: border-box; }
.input-lg:focus { border-color: #d4a373; }
.mb-4 { margin-bottom: 20px; }

/* 時間選擇器 (精簡版) */
.time-selector-container.compact {
  display: flex; justify-content: flex-start; align-items: center; gap: 8px;
}
.select-wrapper { position: relative; width: 80px; }
.custom-select {
  width: 100%; appearance: none; -webkit-appearance: none;
  background-color: white; border: 1px solid #ddd; border-radius: 8px;
  padding: 8px; font-size: 1.2rem; font-weight: bold; color: #5A483C;
  text-align: center; cursor: pointer;
}
.colon { font-size: 1.5rem; font-weight: bold; color: #d4a373; }

/* Step 5: 獎勵與登入 */
.reward-card {
  background: #fffbf5; border: 2px dashed #d4a373; border-radius: 16px;
  padding: 20px; margin: 20px 0;
}
.reward-label { color: #8c7b75; font-size: 0.9rem; margin: 0; }
.reward-amount { color: #d4a373; font-size: 1.6rem; font-weight: bold; margin: 5px 0; }
.reward-sub { color: #d4a373; font-size: 0.9rem; font-weight: 500; }
.unlock-info { text-align: left; margin-bottom: 20px; }
.unlock-title { font-weight: bold; color: #555; margin-bottom: 8px; }
.unlock-list { padding-left: 20px; margin: 0; color: #666; font-size: 0.9rem; }
.unlock-note { font-size: 0.8rem; color: #aaa; margin-top: 12px; font-style: italic; }
.divider { margin: 15px 0; color: #aaa; font-size: 0.9rem; display: flex; align-items: center; justify-content: center; }
.divider::before, .divider::after { content: ""; flex: 1; height: 1px; background: #eee; margin: 0 10px; }
.btn-login { background: #06C755; }
.google-btn-container { display: flex; justify-content: center; min-height: 40px; margin-bottom: 10px; }
.login-note { font-size: 0.8rem; color: #ccc; margin-top: 5px; }

/* 條款 Modal */
.terms-modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; justify-content: center; align-items: center; padding: 20px; }
.terms-card { background: white; width: 100%; max-width: 400px; padding: 24px; border-radius: 16px; display: flex; flex-direction: column; max-height: 80vh; }
.terms-content { flex: 1; overflow-y: auto; font-size: 0.9rem; color: #555; border: 1px solid #eee; padding: 12px; border-radius: 8px; margin-bottom: 10px; }
.btn-close { background: #eee; border: none; padding: 10px; width: 100%; border-radius: 8px; cursor: pointer; }

@keyframes slideUp { from { transform: translateY(10px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>