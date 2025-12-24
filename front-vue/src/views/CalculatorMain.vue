<template>
  <div class="calculator-wrapper">
    
    <div class="sub-nav-wrapper">
      <div class="sub-nav">
        <button 
          class="nav-pill" 
          :class="{ active: currentStrategy === 'RentVsBuy' }"
          @click="currentStrategy = 'RentVsBuy'"
        >
          租房買房策略
        </button>
        <button 
          class="nav-pill" 
          :class="{ active: currentStrategy === 'Lifecycle' }"
          @click="currentStrategy = 'Lifecycle'"
        >
          生命週期投資
        </button>
      </div>
    </div>

    <keep-alive>
      <component :is="currentComponent" />
    </keep-alive>

  </div>
</template>

<script setup>
import { ref, computed } from 'vue';

// 引入您的兩個策略頁面
import PropertyVsStockView from './PropertyVsStockView.vue';
import LifecycleInvestingView from './LifecycleInvestingView.vue';

const currentStrategy = ref('RentVsBuy');

const currentComponent = computed(() => {
  switch (currentStrategy.value) {
    case 'RentVsBuy':
      return PropertyVsStockView;
    case 'Lifecycle':
      return LifecycleInvestingView;
    default:
      return PropertyVsStockView;
  }
});
</script>

<style scoped>
.calculator-wrapper {
  width: 100%;
}

/* 子導航容器 */
.sub-nav-wrapper {
  display: flex;
  justify-content: center;
  padding: 15px 0 5px 0; /* 上方留點空間 */
  margin-bottom: 10px;
  background-color: var(--bg-body); /* 與背景融合 */
  position: sticky;
  top: 0;
  z-index: 10; /* 確保浮在內容上方 */
}

/* 膠囊式按鈕群組 */
.sub-nav {
  display: flex;
  gap: 8px;
  background-color: #eaddcf; /* 淺卡其色底 */
  padding: 4px;
  border-radius: 25px;
  box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
}

.nav-pill {
  border: none;
  background: transparent;
  padding: 8px 20px;
  border-radius: 20px;
  font-size: 0.9rem;
  font-weight: 600;
  color: #8c7b75;
  cursor: pointer;
  transition: all 0.3s ease;
  white-space: nowrap;
}

.nav-pill:hover {
  color: #5d4037;
}

.nav-pill.active {
  background-color: #fff;
  color: #d4a373; /* 主色調 */
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

/* 手機版優化：如果按鈕太多，可以橫向捲動 */
@media (max-width: 480px) {
  .nav-pill {
    padding: 8px 16px;
    font-size: 0.85rem;
  }
}
</style>