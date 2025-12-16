<template>
  <div class="sub-container">
    <div class="page-header">
      <div class="title-group">
        <h2>週期收支訂閱管理</h2>
        <p class="subtitle">自動記帳與週期性收入支出</p>
      </div>
      <button class="add-btn" @click="openModal">
        <span>+</span> 新增週期收支
      </button>
    </div>

    <div v-if="loading" class="state-box"><span class="loader"></span> 載入中...</div>
    <div v-else-if="subscriptions.length === 0" class="empty-state">
      <!-- <div class="icon">📅</div> -->
      <p>沒有週期性項目</p>
      <p class="sub-text">新增房租、Netflix 或薪水，時間到自動幫您記帳！</p>
    </div>
    
    <div v-else class="sub-list">
      <div v-for="item in subscriptions" :key="item.id" class="sub-card">
        <div class="card-left">
          <div class="sub-name">{{ item.description }}</div>
          <div class="sub-meta">
            <span class="badge">{{ freqMap[item.frequency_type] }}</span>
            <span class="next-date">下次: {{ item.next_run_date }}</span>
          </div>
        </div>
        <div class="card-right">
          <div class="sub-amount" :class="item.type === 'income' ? 'text-income' : 'text-expense'">
            {{ item.type === 'income' ? '+' : '-' }} {{ numberFormat(item.amount, 0) }}
            <span class="curr">{{ item.currency }}</span>
          </div>
          <button class="btn-del" @click="handleDelete(item.id)">🗑️</button>
        </div>
      </div>
    </div>

    <div v-if="isModalOpen" class="modal-overlay" @click.self="closeModal">
      <div class="modal-content">
        <div class="modal-header">
          <h3>新增週期性項目</h3>
          <button class="close-btn" @click="closeModal">×</button>
        </div>
        <form @submit.prevent="handleSubmit">
          <div class="form-group">
            <label>名稱</label>
            <input type="text" v-model="form.description" class="input-std" placeholder="例如：Netflix 月費" required>
          </div>
          <div class="form-row">
            <div class="form-group half">
              <label>類型</label>
              <select v-model="form.type" class="input-std">
                <option value="expense">支出</option>
                <option value="income">收入</option>
              </select>
            </div>
            <div class="form-group half">
              <label>金額</label>
              <input type="number" v-model.number="form.amount" class="input-std" required>
            </div>
          </div>
          <div class="form-group">
            <label>分類</label>
            <select v-model="form.category" class="input-std">
                <option value="Bills">帳單/繳費</option>
                <option value="Entertainment">娛樂/訂閱</option>
                <option value="Salary">薪水</option>
                <option value="Allowance">零用錢</option>
                <option value="Investment">定期定額</option>
                <option value="Miscellaneous">其他</option>
            </select>
          </div>
          <div class="form-row">
            <div class="form-group half">
              <label>頻率</label>
              <select v-model="form.frequency" class="input-std">
                <option value="monthly">每月</option>
                <option value="weekly">每週</option>
                <option value="yearly">每年</option>
              </select>
            </div>
            <div class="form-group half">
              <label>首次/下次執行日</label>
              <input type="date" v-model="form.next_date" class="input-std" required>
            </div>
          </div>
          <button type="submit" class="save-btn">儲存設定</button>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, reactive, watch } from 'vue';
import { fetchWithLiffToken, numberFormat } from '@/utils/api';

const props = defineProps(['ledgerId']);
const subscriptions = ref([]);
const loading = ref(true);
const isModalOpen = ref(false);

const form = reactive({
  description: '', type: 'expense', amount: null, category: 'Bills', 
  frequency: 'monthly', next_date: new Date().toISOString().substring(0, 10), currency: 'TWD'
});

const freqMap = { 'monthly': '每月', 'weekly': '每週', 'yearly': '每年' };

watch(() => props.ledgerId, () => fetchSubscriptions());

async function fetchSubscriptions() {
  loading.value = true;
  let url = `${window.API_BASE_URL}?action=get_subscriptions`;
  if (props.ledgerId) url += `&ledger_id=${props.ledgerId}`;
  
  const response = await fetchWithLiffToken(url);
  if (response && response.ok) {
    const res = await response.json();
    if (res.status === 'success') subscriptions.value = res.data;
  }
  loading.value = false;
}

async function handleSubmit() {
  const payload = { ...form };
  if (props.ledgerId) payload.ledger_id = props.ledgerId;

  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=add_subscription`, {
    method: 'POST', body: JSON.stringify(payload)
  });
  
  if (response && response.ok) {
    const res = await response.json();
    if (res.status === 'success') {
      closeModal();
      fetchSubscriptions();
      alert('設定成功！系統將在指定日期自動記帳。');
    } else alert('失敗: ' + res.message);
  }
}

async function handleDelete(id) {
  if(!confirm('確定要取消此訂閱嗎？')) return;
  const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=delete_subscription`, {
    method: 'POST', body: JSON.stringify({ id })
  });
  if(response && (await response.json()).status === 'success') fetchSubscriptions();
}

function openModal() { isModalOpen.value = true; }
function closeModal() { isModalOpen.value = false; }

onMounted(fetchSubscriptions);
</script>

<style scoped>
.sub-container { padding-bottom: 80px; }
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding: 10px 0; }
.title-group h2 { margin: 0; font-size: 1.2rem; color: #5d5d5d; }
.subtitle { margin: 4px 0 0 0; font-size: 0.85rem; color: #888; }
.add-btn { background: #d4a373; color: white; border: none; padding: 8px 16px; border-radius: 20px; font-weight: bold; cursor: pointer; }

.empty-state { text-align: center; padding: 40px; color: #aaa; background: #fff; border-radius: 16px; border: 1px dashed #ddd; margin-top: 20px; }
.empty-state .icon { font-size: 3rem; margin-bottom: 10px; }

.sub-list { display: flex; flex-direction: column; gap: 12px; }
.sub-card { background: white; padding: 16px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border: 1px solid #f0ebe5; }
.card-left { display: flex; flex-direction: column; gap: 4px; }
.sub-name { font-weight: 600; color: #333; font-size: 1rem; }
.sub-meta { display: flex; gap: 8px; align-items: center; }
.badge { font-size: 0.75rem; background: #f0f0f0; padding: 2px 6px; border-radius: 4px; color: #666; }
.next-date { font-size: 0.75rem; color: #999; }

.card-right { text-align: right; display: flex; align-items: center; gap: 10px; }
.sub-amount { font-weight: bold; font-size: 1rem; }
.text-income { color: #1DB446; } .text-expense { color: #e5989b; }
.curr { font-size: 0.7rem; color: #aaa; font-weight: normal; }
.btn-del { background: none; border: none; cursor: pointer; font-size: 1.1rem; opacity: 0.6; }

/* Modal 樣式 (沿用之前的) */
.modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; display: flex; justify-content: center; align-items: center; padding: 20px; }
.modal-content { background: white; width: 100%; max-width: 400px; padding: 24px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
.modal-header { display: flex; justify-content: space-between; margin-bottom: 20px; }
.close-btn { background: none; border: none; font-size: 1.5rem; color: #999; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 0.85rem; color: #888; margin-bottom: 6px; }
.input-std { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; box-sizing: border-box; background: #f9f9f9; }
.form-row { display: flex; gap: 12px; } .half { flex: 1; }
.save-btn { width: 100%; padding: 12px; background: #d4a373; color: white; border: none; border-radius: 10px; font-weight: bold; margin-top: 10px; cursor: pointer; }
</style>