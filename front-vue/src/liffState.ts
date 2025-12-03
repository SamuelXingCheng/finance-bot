// src/liffState.ts
import { reactive } from 'vue';

// 定義 LIFF 的狀態介面 (TypeScript 的好處！)
interface LiffState {
  isLoggedIn: boolean;
  profile: any; // 或者定義更詳細的 Profile 結構
  error: string | null;
}

// 建立響應式狀態
export const liffState = reactive<LiffState>({
  isLoggedIn: false,
  profile: null,
  error: null
});

// 您也可以在這裡導出初始化 LIFF 的函數，如果你有的話
// export const initLiff = async () => { ... }