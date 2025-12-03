// src/utils/api.js

// 獲取 PHP 注入的 API 基礎網址
const API_BASE_URL = window.API_BASE_URL || '../api.php'; 

/**
 * 核心 API 呼叫方法：自動附加 LIFF ID Token
 */
export async function fetchWithLiffToken(url, options = {}) {
    if (typeof liff === 'undefined' || !liff.isLoggedIn()) {
        return null;
    }
    
    const idToken = liff.getIDToken();
    const defaultHeaders = { 
        'Authorization': `Bearer ${idToken}`, 
        'Content-Type': 'application/json' 
    };

    options.headers = { ...defaultHeaders, ...options.headers };
    
    const response = await fetch(url, options);

    if (response.status === 401) {
        // 後端驗證失敗，觸發重新登入
        alert("登入狀態失效，請重新登入。");
        liff.logout(); 
        liff.login();
        return null;
    }

    return response;
}

/**
 * 數字格式化輔助函式 (來自舊程式碼的邏輯)
 */
export function numberFormat(number, decimals = 2, dec_point = '.', thousands_sep = ',') {
    number = (number + '').replace(/[^0-9+\-Ee.]/g, ' ');
    const n = !isFinite(+number) ? 0 : +number;
    const prec = !isFinite(+decimals) ? 0 : Math.abs(decimals);
    const sep = (typeof thousands_sep === 'undefined') ? ',' : thousands_sep;
    const dec = (typeof dec_point === 'undefined') ? '.' : dec_point;
    let s = '';

    const toFixedFix = function (n, prec) {
        const k = Math.pow(10, prec);
        return '' + Math.round(n * k) / k;
    };

    s = (prec ? toFixedFix(n, prec) : '' + Math.round(n)).split('.');
    if (s[0].length > 3) {
        s[0] = s[0].replace(/\B(?=(?:\d{3})+(?!\d))/g, sep);
    }
    if ((s[1] || '').length < prec) {
        s[1] = s[1] || '';
        s[1] += new Array(prec - s[1].length + 1).join('0');
    }
    return s.join(dec);
}

// Chart.js 輔助函式
export function generateColors(count) {
    const colors = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#E7E9ED', '#4CAF50', '#F44336', '#2196F3'];
    const result = [];
    for (let i = 0; i < count; i++) {
        result.push(colors[i % colors.length]);
    }
    return result;
}