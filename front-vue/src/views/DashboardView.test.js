import { describe, it, expect } from 'vitest';
// 我們只測試邏輯，不需要掛載整個組件 UI，所以把那段邏輯複製過來測試
// 或者更好的做法是：將 groupedTransactions 的核心邏輯重構成一個純函式 (utils/transform.js)
// 這裡為了演示，我們模擬測試那個重構後的函式：

// 假設這段邏輯是您 component 裡的 groupedTransactions 邏輯
function groupTransactions(transactions) {
    if (transactions.length === 0) return [];
    const dateGroupMap = new Map();
    const weekdayNames = ['日', '一', '二', '三', '四', '五', '六'];
    
    transactions.forEach(tx => {
        const date = tx.transaction_date;
        const categoryKey = tx.category; // 注意：實際代碼可能是 tx.category
        
        if (!dateGroupMap.has(date)) {
            const dateObj = new Date(date);
            dateGroupMap.set(date, {
                date: date, // 補上 date 方便排序
                categories: new Map(), 
                displayDate: date.substring(5),
                weekday: `(${weekdayNames[dateObj.getDay()]})`
            });
        }
        const dateGroup = dateGroupMap.get(date);
        
        if (!dateGroup.categories.has(categoryKey)) {
            dateGroup.categories.set(categoryKey, {
                categoryName: categoryKey, // 簡化
                categoryKey: categoryKey,
                items: []
            });
        }
        dateGroup.categories.get(categoryKey).items.push(tx);
    });

    const result = Array.from(dateGroupMap, ([date, data]) => ({
        date: date,
        displayDate: data.displayDate,
        weekday: data.weekday,
        categories: Array.from(data.categories.values())
    }));
    
    // 降序排序 (最新的日期在前面)
    return result.sort((a, b) => new Date(b.date) - new Date(a.date));
}

describe('Dashboard Logic', () => {
    it('groups transactions by date correctly', () => {
        const mockData = [
            { id: 1, transaction_date: '2023-10-01', category: 'Food', amount: 100 },
            { id: 2, transaction_date: '2023-10-01', category: 'Food', amount: 50 },
            { id: 3, transaction_date: '2023-10-02', category: 'Transport', amount: 30 },
        ];

        const result = groupTransactions(mockData);

        // 應該要有兩個日期群組
        expect(result).toHaveLength(2);
        
        // 10-02 應該在前面 (降序)
        expect(result[0].date).toBe('2023-10-02');
        expect(result[1].date).toBe('2023-10-01');

        // 10-01 的群組應該包含兩筆 Food 交易
        const group1001 = result[1];
        expect(group1001.categories[0].categoryKey).toBe('Food');
        expect(group1001.categories[0].items).toHaveLength(2);
    });
});