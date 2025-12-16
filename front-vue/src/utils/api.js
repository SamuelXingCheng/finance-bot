// front-vue/src/utils/api.js

// 🌟 修正點：使用 "賦值表達式" 確保 window.API_BASE_URL 被設定
// 這樣寫：將字串賦值給 window.API_BASE_URL，同時也賦值給本地 const API_BASE_URL
const API_BASE_URL = window.API_BASE_URL = 'https://finbot.tw/api.php'; 
// (或是 '../api.php'，視您的部署路徑而定，建議寫完整網址以避免相對路徑問題)

/**
 * 核心 API 呼叫方法：自動附加 LIFF ID Token
 */
export async function fetchWithLiffToken(url, options = {}) {
    // 1. 先檢查是否有 Google Token (優先權可自行調整，這裡假設有 Google Token 就用 Google)
    const googleToken = localStorage.getItem('google_id_token');
    
    // 2. 檢查 LIFF 狀態
    const isLiffLoggedIn = (typeof liff !== 'undefined') && liff.isLoggedIn();
    
    let token = '';
    let provider = 'line'; // 預設為 line

    if (googleToken) {
        token = googleToken;
        provider = 'google';
    } else if (isLiffLoggedIn) {
        token = liff.getIDToken();
        provider = 'line';
    } else {
        // 都沒登入，直接回傳 null 或讓後端擋
        return null;
    }
    
    const defaultHeaders = { 
        'Authorization': `Bearer ${token}`,
        'X-Auth-Provider': provider  // ★ 關鍵：告訴後端要用哪把尺來驗證
    };

    if (!(options.body instanceof FormData)) {
        defaultHeaders['Content-Type'] = 'application/json';
    }

    options.headers = { ...defaultHeaders, ...options.headers };
    
    // 建議：加上 try-catch 防止網絡錯誤導致崩潰
    try {
        const response = await fetch(url, options);
        if (response.status === 401) {
             console.warn("Token 過期");
             if (provider === 'google') {
                 localStorage.removeItem('google_id_token');
                 window.location.reload();
             } else {
                 liff.logout();
                 liff.login();
             }
             return null;
        }
        return response;
    } catch (e) {
        console.error("Network Error:", e);
        return null;
    }
}

// ... numberFormat 和 generateColors 保持不變 ...
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

export function generateColors(count) {
    const colors = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#E7E9ED', '#4CAF50', '#F44336', '#2196F3'];
    const result = [];
    for (let i = 0; i < count; i++) {
        result.push(colors[i % colors.length]);
    }
    return result;
}