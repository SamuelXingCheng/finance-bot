<template>
  <div class="onboarding-overlay">
    <div class="wizard-card">
      
      <div class="progress-bar">
        <div class="progress-fill" :style="{ width: (step / 7) * 100 + '%' }"></div>
      </div>

      <div v-if="step === 1" class="step-content text-center">
        <h2>歡迎使用 FinBot！</h2>
        <p class="desc">口語記帳 x 資產管理。<br>動動手、動動口，讓我們慢慢變富！</p>
        <button class="btn-primary" @click="nextStep">開始體驗</button>
      </div>

      <div v-else-if="step === 2" class="step-content">
        <h2>服務條款確認</h2>
        <p class="desc sm">
          為了保障您的權益，使用 FinBot 前請先閱讀並同意我們的
          <a href="#" @click.prevent="showTerms = true" class="link-text">使用條款與隱私權政策</a>。
        </p>
        
        <div class="checkbox-group">
          <label class="checkbox-label">
            <input type="checkbox" v-model="form.agreed">
            <span class="checkbox-text">我已閱讀並同意《使用條款暨隱私政策》</span>
          </label>
        </div>

        <button class="btn-primary" :disabled="!form.agreed" @click="nextStep">同意並繼續</button>
      </div>

      <div v-else-if="step === 3" class="step-content">
        <h2>您的目標是？</h2>
        <p class="desc">讓我們了解您，以便提供客製化建議。</p>
        <div class="radio-options">
          <label class="option-card" :class="{ selected: form.goal === 'save' }">
            <input type="radio" v-model="form.goal" value="save">
            想要存更多錢
          </label>
          <label class="option-card" :class="{ selected: form.goal === 'analyze' }">
            <input type="radio" v-model="form.goal" value="analyze">
            分析消費習慣
          </label>
          <label class="option-card" :class="{ selected: form.goal === 'control' }">
            <input type="radio" v-model="form.goal" value="control">
            記錄資產情況，提前退休
          </label>
        </div>
        <button class="btn-primary" :disabled="!form.goal" @click="nextStep">下一步</button>
      </div>

      <div v-else-if="step === 4" class="step-content">
        <h2>設定每月預算</h2>
        <p class="desc">我們會幫您監控，避免超支。</p>
        <div class="input-wrapper">
          <span class="prefix">NT$</span>
          <input type="number" v-model="form.budget" class="input-lg" placeholder="例如：30000">
        </div>
        <button class="btn-primary" :disabled="!form.budget" @click="nextStep">下一步</button>
      </div>

      <div v-else-if="step === 5" class="step-content">
        <h2>養成記帳習慣</h2>
        <p class="desc">每天最常查看手機的時間是？<br>我們會在 LINE 輕輕提醒您。</p>
        <input type="time" v-model="form.reminder_time" class="input-lg text-center">
        <button class="btn-primary" @click="nextStep">設定提醒</button>
      </div>

      <div v-else-if="step === 6" class="step-content text-center">
        <div class="icon">✨</div>
        <h2>核心功能示範</h2>
        <p class="desc">再也不用動腦筋想分類！</p>
        <div class="demo-box">
          <p class="chat-bubble user">午餐 150</p>
          <p class="chat-bubble bot">✅ 已記錄：飲食 $150</p>
        </div>
        <p class="sub-desc">只要在 LINE 聊天室輸入文字/語音，AI 幫您搞定一切。</p>
        <button class="btn-primary" @click="nextStep">太棒了</button>
      </div>

      <div v-else-if="step === 7" class="step-content text-center">

    <h2>恭喜完成！</h2>
    
    <div class="reward-card">
        <p class="reward-label">獲得新手獎勵</p>
        <p class="reward-amount">FinPoints 10 點</p>
        <p class="reward-note">點數可折抵訂閱費，比特幣回饋即將上線！</p>
    </div>

    <div class="unlock-info">
        <p class="unlock-title">您已解鎖以下功能：</p>
        <ul class="unlock-list">
        <li>AI 口語記帳與資產管理</li>
        <li>每日免費 AI 額度 (每日更新)</li>
        </ul>
    </div>
    
    <div class="spacer"></div>

    <button class="btn-primary btn-start" @click="finishOnboarding" :disabled="isSubmitting">
        {{ isSubmitting ? '啟用中...' : '開始使用 FinBot' }}
    </button>
    </div>

    </div>

    <div v-if="showTerms" class="terms-modal-overlay" @click.self="showTerms = false">
      <div class="terms-card">
        <h3>使用條款與隱私權政策</h3>
        <div class="terms-content">
        <h4>1. 隱私權政策適用範圍</h4>
        <p>
            歡迎使用 FinBot（以下簡稱「本服務」）。本隱私權政策說明我們如何收集、使用、揭露及保護您在使用本服務（包括記帳、資產管理及 AI 財務分析功能）時提供的個人資料。使用本服務即代表您同意本政策之條款。
        </p>

        <h4>2. 我們收集的資料類型</h4>
        <p>
            為了提供精準的財務分析與記帳服務，我們可能會收集以下資料：
            <br>• <strong>個人識別資訊：</strong>如您的暱稱、電子郵件地址或社群帳號 ID（如 LINE User ID）。
            <br>• <strong>財務數據：</strong>您主動輸入的收支記錄、資產狀況、預算設定及交易類別。
            <br>• <strong>使用行為：</strong>您與聊天機器人的互動記錄、功能使用頻率及錯誤報告。
        </p>

        <h4>3. 資料使用方式</h4>
        <p>
            我們收集的資料僅用於以下用途：
            <br>• 提供記帳功能、產生財務報表及資產圖表。
            <br>• 透過 AI 演算法分析您的消費習慣並提供理財教育。
            <br>• 進行系統維護、資料備份及服務優化。
            <br>• 除非取得您的同意或法律要求，我們絕不會將您的財務數據出售給第三方。
        </p>

        <h4>4. AI 分析與自動化決策</h4>
        <p>
            本服務使用人工智慧技術進行數據分析。請注意，AI 生成的建議（如「減少外食開銷」或「資產配置建議」）僅供參考，不構成專業的投資顧問意見。在做出重大財務決策前，請務必諮詢專業人士。
        </p>

        <h4>5. 資料存儲與安全</h4>
        <p>
            我們致力於保護您的資料安全。您的財務數據在傳輸與存儲過程中均採用加密技術（如 SSL/TLS）保護。我們使用安全的雲端伺服器存儲資料，並設有嚴格的存取權限控制。
        </p>

        <h4>6. 您的權利</h4>
        <p>
            針對您的個人資料，您享有以下權利：
            <br>• <strong>查詢與閱覽：</strong>您可以隨時查詢您的記帳紀錄。
            <br>• <strong>下載備份：</strong>您可以要求匯出您的記帳資料。
            <br>• <strong>刪除權（被遺忘權）：</strong>若您決定停止使用本服務，您可以隨時聯繫我們要求刪除所有與您相關的帳號及財務數據。
        </p>

        <h4>7. 隱私權政策之修訂</h4>
        <p>
            本服務有權隨時修訂本隱私權政策。修訂後的條款將公佈於本服務頁面，重大變更時我們將透過機器人推播或電子郵件通知您。
        </p>

        <h4>8. 聯絡我們</h4>
        <p>
            若您對本隱私權政策或資料處理方式有任何疑問，請透過客服信箱 support@finbot.tw 與我們聯繫。
        </p>
        </div>
        <button class="btn-close" @click="showTerms = false">關閉</button>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { fetchWithLiffToken } from '@/utils/api';

const emit = defineEmits(['complete']);

const step = ref(1);
const isSubmitting = ref(false);
const showTerms = ref(false); // 🟢 控制條款 Modal 顯示

const form = reactive({
  agreed: false,
  goal: '',
  budget: null,
  reminder_time: '21:00'
});

function nextStep() {
  if (step.value < 7) {
    step.value++;
  }
}

async function finishOnboarding() {
  isSubmitting.value = true;
  try {
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=submit_onboarding`, {
      method: 'POST',
      body: JSON.stringify(form)
    });
    
    if (response && response.ok) {
      emit('complete');
    } else {
      alert('連線錯誤，請稍後再試');
    }
  } catch (e) {
    console.error(e);
    alert('發生錯誤');
  } finally {
    isSubmitting.value = false;
  }
}
</script>

<style scoped>
/* 既有樣式保持不變 */
.onboarding-overlay {
  position: fixed; top: 0; left: 0; width: 100%; height: 100%;
  background: #f9f7f2; z-index: 9999;
  display: flex; justify-content: center; align-items: center;
  padding: 20px;
}
.wizard-card {
  background: white; width: 100%; max-width: 380px;
  padding: 30px 24px; border-radius: 20px;
  box-shadow: 0 10px 30px rgba(212, 163, 115, 0.15);
  text-align: left; position: relative; overflow: hidden;
}
.text-center { text-align: center; }

/* 進度條 */
.progress-bar {
  position: absolute; top: 0; left: 0; width: 100%; height: 4px;
  background: #eee;
}
.progress-fill {
  height: 100%; background: #d4a373; transition: width 0.3s ease;
}

h2 { color: #8c7b75; margin: 0 0 12px 0; font-size: 1.4rem; }
.desc { color: #666; line-height: 1.6; margin-bottom: 24px; }
.desc.sm { font-size: 0.9rem; }
.sub-desc { font-size: 0.85rem; color: #999; margin-top: 10px; }
.highlight { color: #d4a373; font-weight: bold; font-size: 1.1rem; }

.icon { font-size: 3rem; margin-bottom: 16px; display: block; }
.icon-lg { font-size: 4rem; margin-bottom: 10px; display: block; animation: bounce 2s infinite; }

/* 按鈕與輸入 */
.btn-primary {
  width: 100%; padding: 14px; border-radius: 12px; border: none;
  background: #d4a373; color: white; font-size: 1rem; font-weight: bold;
  cursor: pointer; transition: background 0.2s; margin-top: 20px;
}
.btn-primary:disabled { background: #e0d0c0; cursor: not-allowed; }
.btn-primary:active { transform: scale(0.98); }

.input-wrapper { display: flex; align-items: center; border-bottom: 2px solid #eee; padding: 5px; }
.prefix { font-size: 1.2rem; color: #aaa; margin-right: 8px; }
.input-lg {
  width: 100%; border: 1px solid #ddd; padding: 12px; font-size: 1.2rem;
  border-radius: 8px; outline: none;
}
.input-lg:focus { border-color: #d4a373; }

/* 選項卡片 */
.option-card {
  display: flex; align-items: center; padding: 15px;
  border: 1px solid #eee; border-radius: 12px; margin-bottom: 10px;
  cursor: pointer; transition: all 0.2s;
}
.option-card.selected {
  border-color: #d4a373; background: #fff8f0; color: #d4a373; font-weight: bold;
}
.option-card input { display: none; }
.opt-icon { margin-right: 10px; font-size: 1.2rem; }

/* 聊天示範 */
.demo-box {
  background: #f4f6f8; padding: 15px; border-radius: 12px;
  margin: 10px 0; text-align: left;
}
.chat-bubble {
  padding: 8px 12px; border-radius: 16px; width: fit-content; margin-bottom: 8px; font-size: 0.9rem;
}
.chat-bubble.user {
  background: #d4a373; color: white; margin-left: auto; border-bottom-right-radius: 4px;
}
.chat-bubble.bot {
  background: white; color: #333; border: 1px solid #eee; border-bottom-left-radius: 4px;
}

@keyframes bounce {
  0%, 20%, 50%, 80%, 100% {transform: translateY(0);}
  40% {transform: translateY(-15px);}
  60% {transform: translateY(-10px);}
}

/* 🟢 條款相關樣式 (新增) */
.link-text { color: #d4a373; text-decoration: underline; cursor: pointer; }
.checkbox-group { margin-bottom: 20px; }
.checkbox-label { display: flex; align-items: flex-start; cursor: pointer; }
.checkbox-label input { margin-top: 4px; margin-right: 8px; }
.checkbox-text { font-size: 0.9rem; color: #333; }

.terms-modal-overlay {
  position: fixed; top: 0; left: 0; width: 100%; height: 100%;
  background: rgba(0, 0, 0, 0.5); z-index: 10000;
  display: flex; justify-content: center; align-items: center; padding: 20px;
}
.terms-card {
  background: white; width: 100%; max-width: 400px;
  padding: 24px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.2);
  display: flex; flex-direction: column; max-height: 80vh;
}
.terms-card h3 { margin: 0 0 16px 0; color: #8c7b75; font-size: 1.2rem; text-align: center; }
.terms-content { 
  flex: 1; overflow-y: auto; font-size: 0.9rem; color: #555; line-height: 1.6; 
  padding-right: 8px; margin-bottom: 16px; border: 1px solid #eee; padding: 12px; border-radius: 8px; background: #fdfdfd;
}
.terms-content h4 { margin: 12px 0 6px 0; color: #333; font-size: 1rem; }
.terms-content p { margin: 0 0 10px 0; }
.btn-close {
  background: #eee; border: none; padding: 10px; width: 100%; border-radius: 8px;
  cursor: pointer; font-weight: bold; color: #555;
}
.btn-close:hover { background: #e0e0e0; }
</style>