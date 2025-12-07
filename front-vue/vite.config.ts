/// <reference types="vitest" />
import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'; 

// 取得當前檔案 (vite.config.ts) 的目錄絕對路徑
const __dirname = path.dirname(fileURLToPath(import.meta.url));

// 根據您提供的資訊，假設 .env 在 src/ 裡面
const ABSOLUTE_ENV_DIR = path.resolve(__dirname, 'src'); 

// 由於您之前說在 '上上一層'，我們也列出來作為對照
// const ABSOLUTE_ENV_DIR = path.resolve(__dirname, '../..'); 

console.log(`\n--- VITE ENV PATH DIAGNOSIS ---`);
console.log(`1. vite.config.ts 路徑 (dirname): ${__dirname}`);
console.log(`2. .env 預期絕對路徑 (envDir): ${ABSOLUTE_ENV_DIR}`);
console.log(`-------------------------------\n`);


export default defineConfig({
  // 🌟 使用計算出來的絕對路徑

  plugins: [
    vue(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  },
  // 👇 新增這一段測試設定
  test: {
    environment: 'jsdom',
    globals: true,
  }
})