/// <reference types="../../node_modules/.vue-global-types/vue_3.5_0.d.ts" />
import { ref, onMounted, computed, watch } from 'vue';
import { fetchWithLiffToken, numberFormat, generateColors } from '@/utils/api';
import Chart from 'chart.js/auto'; // 引入 Chart.js
// 狀態管理
const assetData = ref({});
const assetLoading = ref(true);
const assetError = ref('');
const totalExpense = ref(0);
const expenseBreakdown = ref({});
const chartInstance = ref(null);
const expenseChartCanvas = ref(null);
const formMessage = ref('');
const messageClass = ref('');
// 表單數據 (需補齊所有欄位)
const transactionForm = ref({
    type: 'expense',
    amount: null,
    date: new Date().toISOString().substring(0, 10), // YYYY-MM-DD
    description: '',
    category: 'Miscellaneous',
    currency: 'TWD',
});
// 計算屬性
const globalNetWorth = computed(() => assetData.value.global_twd_net_worth || 0);
// --- 數據載入函式 ---
async function fetchAssetSummary() {
    assetLoading.value = true;
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=asset_summary`);
    if (response) {
        const result = await response.json();
        if (result.status === 'success') {
            assetData.value = result.data;
        }
        else {
            assetError.value = result.message;
        }
    }
    assetLoading.value = false;
}
async function fetchExpenseData() {
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=monthly_expense_breakdown`);
    if (response) {
        const result = await response.json();
        if (result.status === 'success') {
            totalExpense.value = result.data.total_expense;
            expenseBreakdown.value = result.data.breakdown;
            renderChart();
        }
        else {
            console.error(result.message);
        }
    }
}
// --- 圖表渲染 ---
function renderChart() {
    if (chartInstance.value) {
        chartInstance.value.destroy();
    }
    const labels = Object.keys(expenseBreakdown.value);
    const dataValues = Object.values(expenseBreakdown.value).map(v => parseFloat(v));
    if (labels.length === 0 || totalExpense.value <= 0)
        return;
    chartInstance.value = new Chart(expenseChartCanvas.value, {
        type: 'pie',
        data: {
            labels: labels,
            datasets: [{
                    data: dataValues,
                    backgroundColor: generateColors(labels.length),
                    hoverOffset: 8,
                }],
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'right' },
                title: { display: false }
            }
        },
    });
}
// --- 交易提交處理 ---
async function handleTransactionSubmit() {
    formMessage.value = '處理中...';
    messageClass.value = 'text-gray-500';
    const dataToSend = { ...transactionForm.value };
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=add_transaction`, {
        method: 'POST',
        body: JSON.stringify(dataToSend)
    });
    if (response) {
        const result = await response.json();
        if (result.status === 'success') {
            formMessage.value = '🎉 ' + result.message;
            messageClass.value = 'text-green-600';
            // 刷新數據
            fetchAssetSummary();
            fetchExpenseData();
        }
        else {
            formMessage.value = '❌ ' + (result.message || '新增失敗');
            messageClass.value = 'text-red-600';
        }
    }
}
// 當組件掛載時，開始載入數據
onMounted(() => {
    fetchAssetSummary();
    fetchExpenseData();
});
// 當外部通知刷新時 (來自 AccountManagerView)，重新載入
// 假設 AccountManagerView 會發射一個 'refreshDashboard' 事件
// 由於 Vue Router 尚未設定，這個邏輯需要外部元件直接調用
// watch(() => props.refreshTrigger, () => {
//     fetchAssetSummary();
//     fetchExpenseData();
// });
debugger; /* PartiallyEnd: #3632/scriptSetup.vue */
const __VLS_ctx = {
    ...{},
    ...{},
};
let __VLS_components;
let __VLS_directives;
__VLS_asFunctionalElement(__VLS_intrinsics.div, __VLS_intrinsics.div)({
    ...{ class: "space-y-6" },
});
__VLS_asFunctionalElement(__VLS_intrinsics.div, __VLS_intrinsics.div)({
    ...{ class: "section-title" },
});
__VLS_asFunctionalElement(__VLS_intrinsics.h2, __VLS_intrinsics.h2)({
    ...{ class: "text-xl font-semibold text-amber-700" },
});
__VLS_asFunctionalElement(__VLS_intrinsics.div, __VLS_intrinsics.div)({
    ...{ class: "data-box bg-white p-4 rounded-lg shadow-md border border-stone-200" },
});
__VLS_asFunctionalElement(__VLS_intrinsics.form, __VLS_intrinsics.form)({
    ...{ onSubmit: (__VLS_ctx.handleTransactionSubmit) },
    id: "add-transaction-form",
    ...{ class: "space-y-3" },
});
// @ts-ignore
[handleTransactionSubmit,];
__VLS_asFunctionalElement(__VLS_intrinsics.p, __VLS_intrinsics.p)({
    ...{ class: "text-sm font-medium text-gray-700 mb-1" },
});
__VLS_asFunctionalElement(__VLS_intrinsics.div, __VLS_intrinsics.div)({
    ...{ class: "flex items-center space-x-4" },
});
__VLS_asFunctionalElement(__VLS_intrinsics.input)({
    type: "radio",
    id: "expense",
    value: "expense",
    required: true,
    ...{ class: "form-radio text-amber-700" },
});
(__VLS_ctx.transactionForm.type);
// @ts-ignore
[transactionForm,];
__VLS_asFunctionalElement(__VLS_intrinsics.label, __VLS_intrinsics.label)({
    for: "expense",
    ...{ class: "ml-1 text-red-600" },
});
__VLS_asFunctionalElement(__VLS_intrinsics.input)({
    type: "radio",
    id: "income",
    value: "income",
    required: true,
    ...{ class: "form-radio text-amber-700" },
});
(__VLS_ctx.transactionForm.type);
// @ts-ignore
[transactionForm,];
__VLS_asFunctionalElement(__VLS_intrinsics.label, __VLS_intrinsics.label)({
    for: "income",
    ...{ class: "ml-1 text-green-600" },
});
__VLS_asFunctionalElement(__VLS_intrinsics.div, __VLS_intrinsics.div)({});
__VLS_asFunctionalElement(__VLS_intrinsics.label, __VLS_intrinsics.label)({
    ...{ class: "block text-sm font-medium text-gray-700 mb-1" },
});
__VLS_asFunctionalElement(__VLS_intrinsics.input)({
    type: "number",
    required: true,
    min: "0.01",
    step: "0.01",
    ...{ class: "w-full p-2 border border-stone-300 rounded-md" },
});
(__VLS_ctx.transactionForm.amount);
// @ts-ignore
[transactionForm,];
__VLS_asFunctionalElement(__VLS_intrinsics.button, __VLS_intrinsics.button)({
    type: "submit",
    ...{ class: "w-full py-2 bg-amber-700 text-white font-semibold rounded-md hover:bg-amber-800 transition duration-150" },
});
__VLS_asFunctionalElement(__VLS_intrinsics.div, __VLS_intrinsics.div)({
    id: "form-message",
    ...{ class: "mt-4 font-bold text-center" },
    ...{ class: (__VLS_ctx.messageClass) },
});
// @ts-ignore
[messageClass,];
(__VLS_ctx.formMessage);
// @ts-ignore
[formMessage,];
__VLS_asFunctionalElement(__VLS_intrinsics.div, __VLS_intrinsics.div)({
    ...{ class: "section-title" },
});
__VLS_asFunctionalElement(__VLS_intrinsics.h2, __VLS_intrinsics.h2)({
    ...{ class: "text-xl font-semibold text-amber-700" },
});
if (__VLS_ctx.assetLoading) {
    // @ts-ignore
    [assetLoading,];
    __VLS_asFunctionalElement(__VLS_intrinsics.div, __VLS_intrinsics.div)({
        ...{ class: "text-center text-gray-500 py-4" },
    });
}
else if (__VLS_ctx.assetError) {
    // @ts-ignore
    [assetError,];
    __VLS_asFunctionalElement(__VLS_intrinsics.div, __VLS_intrinsics.div)({
        ...{ class: "text-center text-red-600 py-4" },
    });
    (__VLS_ctx.assetError);
    // @ts-ignore
    [assetError,];
}
else {
    __VLS_asFunctionalElement(__VLS_intrinsics.div, __VLS_intrinsics.div)({
        id: "asset-summary",
        ...{ class: "data-box bg-white p-4 rounded-lg shadow-md border border-stone-200" },
    });
    __VLS_asFunctionalElement(__VLS_intrinsics.p, __VLS_intrinsics.p)({
        ...{ class: "text-gray-500" },
    });
    __VLS_asFunctionalElement(__VLS_intrinsics.span, __VLS_intrinsics.span)({
        ...{ class: (['net-worth', __VLS_ctx.globalNetWorth >= 0 ? 'text-green-600' : 'text-red-600']) },
    });
    // @ts-ignore
    [globalNetWorth,];
    (__VLS_ctx.numberFormat(__VLS_ctx.globalNetWorth, 2));
    // @ts-ignore
    [globalNetWorth, numberFormat,];
}
__VLS_asFunctionalElement(__VLS_intrinsics.div, __VLS_intrinsics.div)({
    ...{ class: "section-title" },
});
__VLS_asFunctionalElement(__VLS_intrinsics.h2, __VLS_intrinsics.h2)({
    ...{ class: "text-xl font-semibold text-amber-700" },
});
__VLS_asFunctionalElement(__VLS_intrinsics.div, __VLS_intrinsics.div)({
    id: "expense-breakdown",
    ...{ class: "data-box bg-white p-4 rounded-lg shadow-md border border-stone-200" },
});
__VLS_asFunctionalElement(__VLS_intrinsics.div, __VLS_intrinsics.div)({
    id: "chart-container",
    ...{ class: "max-w-md mx-auto" },
});
__VLS_asFunctionalElement(__VLS_intrinsics.canvas, __VLS_intrinsics.canvas)({
    ref: "expenseChartCanvas",
});
/** @type {typeof __VLS_ctx.expenseChartCanvas} */ ;
// @ts-ignore
[expenseChartCanvas,];
__VLS_asFunctionalElement(__VLS_intrinsics.p, __VLS_intrinsics.p)({
    ...{ class: "text-center font-medium mt-4 text-red-600" },
});
__VLS_asFunctionalElement(__VLS_intrinsics.span, __VLS_intrinsics.span)({
    ...{ class: "text-xl font-bold" },
});
(__VLS_ctx.numberFormat(__VLS_ctx.totalExpense, 2));
// @ts-ignore
[numberFormat, totalExpense,];
/** @type {__VLS_StyleScopedClasses['space-y-6']} */ ;
/** @type {__VLS_StyleScopedClasses['section-title']} */ ;
/** @type {__VLS_StyleScopedClasses['text-xl']} */ ;
/** @type {__VLS_StyleScopedClasses['font-semibold']} */ ;
/** @type {__VLS_StyleScopedClasses['text-amber-700']} */ ;
/** @type {__VLS_StyleScopedClasses['data-box']} */ ;
/** @type {__VLS_StyleScopedClasses['bg-white']} */ ;
/** @type {__VLS_StyleScopedClasses['p-4']} */ ;
/** @type {__VLS_StyleScopedClasses['rounded-lg']} */ ;
/** @type {__VLS_StyleScopedClasses['shadow-md']} */ ;
/** @type {__VLS_StyleScopedClasses['border']} */ ;
/** @type {__VLS_StyleScopedClasses['border-stone-200']} */ ;
/** @type {__VLS_StyleScopedClasses['space-y-3']} */ ;
/** @type {__VLS_StyleScopedClasses['text-sm']} */ ;
/** @type {__VLS_StyleScopedClasses['font-medium']} */ ;
/** @type {__VLS_StyleScopedClasses['text-gray-700']} */ ;
/** @type {__VLS_StyleScopedClasses['mb-1']} */ ;
/** @type {__VLS_StyleScopedClasses['flex']} */ ;
/** @type {__VLS_StyleScopedClasses['items-center']} */ ;
/** @type {__VLS_StyleScopedClasses['space-x-4']} */ ;
/** @type {__VLS_StyleScopedClasses['form-radio']} */ ;
/** @type {__VLS_StyleScopedClasses['text-amber-700']} */ ;
/** @type {__VLS_StyleScopedClasses['ml-1']} */ ;
/** @type {__VLS_StyleScopedClasses['text-red-600']} */ ;
/** @type {__VLS_StyleScopedClasses['form-radio']} */ ;
/** @type {__VLS_StyleScopedClasses['text-amber-700']} */ ;
/** @type {__VLS_StyleScopedClasses['ml-1']} */ ;
/** @type {__VLS_StyleScopedClasses['text-green-600']} */ ;
/** @type {__VLS_StyleScopedClasses['block']} */ ;
/** @type {__VLS_StyleScopedClasses['text-sm']} */ ;
/** @type {__VLS_StyleScopedClasses['font-medium']} */ ;
/** @type {__VLS_StyleScopedClasses['text-gray-700']} */ ;
/** @type {__VLS_StyleScopedClasses['mb-1']} */ ;
/** @type {__VLS_StyleScopedClasses['w-full']} */ ;
/** @type {__VLS_StyleScopedClasses['p-2']} */ ;
/** @type {__VLS_StyleScopedClasses['border']} */ ;
/** @type {__VLS_StyleScopedClasses['border-stone-300']} */ ;
/** @type {__VLS_StyleScopedClasses['rounded-md']} */ ;
/** @type {__VLS_StyleScopedClasses['w-full']} */ ;
/** @type {__VLS_StyleScopedClasses['py-2']} */ ;
/** @type {__VLS_StyleScopedClasses['bg-amber-700']} */ ;
/** @type {__VLS_StyleScopedClasses['text-white']} */ ;
/** @type {__VLS_StyleScopedClasses['font-semibold']} */ ;
/** @type {__VLS_StyleScopedClasses['rounded-md']} */ ;
/** @type {__VLS_StyleScopedClasses['hover:bg-amber-800']} */ ;
/** @type {__VLS_StyleScopedClasses['transition']} */ ;
/** @type {__VLS_StyleScopedClasses['duration-150']} */ ;
/** @type {__VLS_StyleScopedClasses['mt-4']} */ ;
/** @type {__VLS_StyleScopedClasses['font-bold']} */ ;
/** @type {__VLS_StyleScopedClasses['text-center']} */ ;
/** @type {__VLS_StyleScopedClasses['section-title']} */ ;
/** @type {__VLS_StyleScopedClasses['text-xl']} */ ;
/** @type {__VLS_StyleScopedClasses['font-semibold']} */ ;
/** @type {__VLS_StyleScopedClasses['text-amber-700']} */ ;
/** @type {__VLS_StyleScopedClasses['text-center']} */ ;
/** @type {__VLS_StyleScopedClasses['text-gray-500']} */ ;
/** @type {__VLS_StyleScopedClasses['py-4']} */ ;
/** @type {__VLS_StyleScopedClasses['text-center']} */ ;
/** @type {__VLS_StyleScopedClasses['text-red-600']} */ ;
/** @type {__VLS_StyleScopedClasses['py-4']} */ ;
/** @type {__VLS_StyleScopedClasses['data-box']} */ ;
/** @type {__VLS_StyleScopedClasses['bg-white']} */ ;
/** @type {__VLS_StyleScopedClasses['p-4']} */ ;
/** @type {__VLS_StyleScopedClasses['rounded-lg']} */ ;
/** @type {__VLS_StyleScopedClasses['shadow-md']} */ ;
/** @type {__VLS_StyleScopedClasses['border']} */ ;
/** @type {__VLS_StyleScopedClasses['border-stone-200']} */ ;
/** @type {__VLS_StyleScopedClasses['text-gray-500']} */ ;
/** @type {__VLS_StyleScopedClasses['net-worth']} */ ;
/** @type {__VLS_StyleScopedClasses['section-title']} */ ;
/** @type {__VLS_StyleScopedClasses['text-xl']} */ ;
/** @type {__VLS_StyleScopedClasses['font-semibold']} */ ;
/** @type {__VLS_StyleScopedClasses['text-amber-700']} */ ;
/** @type {__VLS_StyleScopedClasses['data-box']} */ ;
/** @type {__VLS_StyleScopedClasses['bg-white']} */ ;
/** @type {__VLS_StyleScopedClasses['p-4']} */ ;
/** @type {__VLS_StyleScopedClasses['rounded-lg']} */ ;
/** @type {__VLS_StyleScopedClasses['shadow-md']} */ ;
/** @type {__VLS_StyleScopedClasses['border']} */ ;
/** @type {__VLS_StyleScopedClasses['border-stone-200']} */ ;
/** @type {__VLS_StyleScopedClasses['max-w-md']} */ ;
/** @type {__VLS_StyleScopedClasses['mx-auto']} */ ;
/** @type {__VLS_StyleScopedClasses['text-center']} */ ;
/** @type {__VLS_StyleScopedClasses['font-medium']} */ ;
/** @type {__VLS_StyleScopedClasses['mt-4']} */ ;
/** @type {__VLS_StyleScopedClasses['text-red-600']} */ ;
/** @type {__VLS_StyleScopedClasses['text-xl']} */ ;
/** @type {__VLS_StyleScopedClasses['font-bold']} */ ;
const __VLS_export = (await import('vue')).defineComponent({});
export default {};
