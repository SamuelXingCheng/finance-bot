<template>
  <div class="dashboard-container">
    <div class="card-section">
      <div class="section-header">
        <h2>生命週期投資法 (Lifecycle Investing)</h2>
      </div>
      <div class="data-box intro-card">
        <p class="intro-text">
          此策略源自耶魯大學教授 Ayres 與 Nalebuff 的研究。<br>
          核心概念是：<strong>在年輕時透過「槓桿」提早曝險於市場</strong>，隨著年齡增長降低槓桿，以達到時間多樣化 (Time Diversification) 的效果，平滑一生的投資風險。
        </p>
      </div>
    </div>

    <div class="card-section">
      <div class="data-box empty-state">
        <div class="icon">🚧</div>
        <h3>功能開發中</h3>
        <p>我們正在建構「槓桿比例試算」與「年齡配置模型」。<br>敬請期待！</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.dashboard-container {
  width: 100%;
  max-width: 100%;
  margin: 0 auto;
  color: var(--text-primary);
  padding-bottom: 30px;
}
.card-section { margin-bottom: 20px; }
.section-header h2 {
  font-size: 1.1rem;
  font-weight: 600;
  color: #8c7b75;
  margin-bottom: 12px;
  margin-left: 4px;
}
.data-box {
  background-color: var(--bg-card);
  border-radius: var(--border-radius);
  padding: 20px;
  box-shadow: var(--shadow-soft);
  border: 1px solid #f0ebe5;
}
.intro-text {
  font-size: 0.9rem;
  color: #666;
  line-height: 1.6;
  margin: 0;
}
.empty-state {
  text-align: center;
  padding: 40px 20px;
  color: #999;
}
.empty-state .icon {
  font-size: 3rem;
  margin-bottom: 10px;
}
</style>