import { describe, it, expect } from 'vitest';
import { numberFormat, generateColors } from './api';

describe('API Utils - numberFormat', () => {
  // 1. 測試基本金額格式化 (預設 2 位小數)
  it('formats standard currency correctly', () => {
    expect(numberFormat(123456)).toBe('123,456.00');
    expect(numberFormat(1000)).toBe('1,000.00');
  });

  // 2. 測試四捨五入邏輯
  it('handles rounding correctly', () => {
    expect(numberFormat(1234.5678)).toBe('1,234.57'); // 進位
    expect(numberFormat(1234.5611)).toBe('1,234.56'); // 捨去
  });

  // 3. 測試加密貨幣 (高精度小數)
  it('supports custom precision for crypto', () => {
    // 測試 8 位小數 (比特幣常用)
    expect(numberFormat(0.12345678, 8)).toBe('0.12345678');
    // 測試 4 位小數
    expect(numberFormat(100.5555, 4)).toBe('100.5555');
  });

  // 4. 測試無效輸入 (防呆)
  it('returns zero for invalid inputs', () => {
    expect(numberFormat('abc')).toBe('0.00');
    expect(numberFormat(null)).toBe('0.00');
    expect(numberFormat(undefined)).toBe('0.00');
  });

  // 5. 測試字串型別的數字輸入
  it('handles string numbers correctly', () => {
    expect(numberFormat('1234')).toBe('1,234.00');
  });
});

describe('API Utils - generateColors', () => {
  // 1. 測試生成的數量是否正確
  it('generates correct number of colors', () => {
    const count = 5;
    const colors = generateColors(count);
    expect(colors).toHaveLength(count);
  });

  // 2. 測試生成的顏色格式 (是否為 Hex Code)
  it('returns valid hex color strings', () => {
    const colors = generateColors(3);
    // 正則表達式檢查 #RRGGBB 格式
    const hexPattern = /^#[0-9A-F]{6}$/i;
    
    colors.forEach(color => {
      expect(color).toMatch(hexPattern);
    });
  });

  // 3. 測試循環取色 (當請求數量超過預設色票時)
  it('cycles through colors when count exceeds palette size', () => {
    // 假設你的 palette 有 10 個顏色，我們請求 12 個
    const colors = generateColors(12);
    expect(colors).toHaveLength(12);
    // 第 11 個顏色應該等於第 1 個顏色 (索引 10 == 索引 0)
    expect(colors[10]).toBe(colors[0]);
  });
});