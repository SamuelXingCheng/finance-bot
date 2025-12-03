import { fileURLToPath, URL } from 'node:url' // <--- 1. 引入這個
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [
    vue(),
  ],
  resolve: {
    alias: {
      // 2. 設定 '@' 指向 src 目錄
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  }
})