/// <reference types="../../node_modules/.vue-global-types/vue_3.5_0.d.ts" />
import { ref, onMounted } from 'vue';
import { fetchWithLiffToken, numberFormat } from '@/utils/api'; // 假設 utils/api.js 位於 src/utils
import { defineEmits } from 'vue';
const accounts = ref([]);
const loading = ref(true);
const emit = defineEmits(['refreshDashboard']); // 定義發射事件
// 從 AssetService 獲取帳戶列表 (對應 api.php?action=get_accounts)
async function fetchAccounts() {
    loading.value = true;
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=get_accounts`);
    if (response) {
        const result = await response.json();
        if (result.status === 'success') {
            accounts.value = result.data;
        }
        else {
            console.error('API Error:', result.message);
        }
    }
    loading.value = false;
}
// 處理刪除帳戶請求 (對應 api.php?action=delete_account)
async function handleDelete(name) {
    if (!confirm(`確定要刪除帳戶 [${name}] 嗎？此操作會影響淨值計算，無法撤銷。`))
        return;
    const response = await fetchWithLiffToken(`${window.API_BASE_URL}?action=delete_account`, {
        method: 'POST',
        body: JSON.stringify({ name: name })
    });
    if (response) {
        const result = await response.json();
        alert(result.message);
        if (result.status === 'success') {
            fetchAccounts(); // 刷新列表
            emit('refreshDashboard'); // 通知 Dashboard 刷新總覽
        }
    }
}
onMounted(fetchAccounts);
debugger; /* PartiallyEnd: #3632/scriptSetup.vue */
const __VLS_ctx = {
    ...{},
    ...{},
    ...{},
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
__VLS_asFunctionalElement(__VLS_intrinsics.button, __VLS_intrinsics.button)({
    ...{ onClick: (...[$event]) => {
            __VLS_ctx.alert('TODO: 彈出新增/編輯表單');
            // @ts-ignore
            [alert,];
        } },
    ...{ class: "py-2 px-4 bg-green-600 text-white font-semibold rounded-md hover:bg-green-700 transition duration-150" },
});
if (__VLS_ctx.loading) {
    // @ts-ignore
    [loading,];
    __VLS_asFunctionalElement(__VLS_intrinsics.div, __VLS_intrinsics.div)({
        ...{ class: "text-center text-gray-500 py-8" },
    });
}
else if (__VLS_ctx.accounts.length === 0) {
    // @ts-ignore
    [accounts,];
    __VLS_asFunctionalElement(__VLS_intrinsics.div, __VLS_intrinsics.div)({
        ...{ class: "text-center text-gray-500 p-4 border rounded-md bg-white" },
    });
}
else {
    __VLS_asFunctionalElement(__VLS_intrinsics.div, __VLS_intrinsics.div)({
        ...{ class: "data-box p-4 rounded-lg shadow-sm overflow-x-auto bg-white" },
    });
    __VLS_asFunctionalElement(__VLS_intrinsics.table, __VLS_intrinsics.table)({
        ...{ class: "min-w-full divide-y divide-stone-200 account-table" },
    });
    __VLS_asFunctionalElement(__VLS_intrinsics.thead, __VLS_intrinsics.thead)({
        ...{ class: "bg-stone-50" },
    });
    __VLS_asFunctionalElement(__VLS_intrinsics.tr, __VLS_intrinsics.tr)({});
    __VLS_asFunctionalElement(__VLS_intrinsics.th, __VLS_intrinsics.th)({
        ...{ class: "px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase" },
    });
    __VLS_asFunctionalElement(__VLS_intrinsics.th, __VLS_intrinsics.th)({
        ...{ class: "px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase" },
    });
    __VLS_asFunctionalElement(__VLS_intrinsics.th, __VLS_intrinsics.th)({
        ...{ class: "px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase" },
    });
    __VLS_asFunctionalElement(__VLS_intrinsics.th, __VLS_intrinsics.th)({
        ...{ class: "px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase" },
    });
    __VLS_asFunctionalElement(__VLS_intrinsics.th, __VLS_intrinsics.th)({
        ...{ class: "px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase" },
    });
    __VLS_asFunctionalElement(__VLS_intrinsics.tbody, __VLS_intrinsics.tbody)({
        ...{ class: "divide-y divide-stone-200" },
    });
    for (const [account] of __VLS_getVForSourceType((__VLS_ctx.accounts))) {
        // @ts-ignore
        [accounts,];
        __VLS_asFunctionalElement(__VLS_intrinsics.tr, __VLS_intrinsics.tr)({
            key: (account.name),
        });
        __VLS_asFunctionalElement(__VLS_intrinsics.td, __VLS_intrinsics.td)({
            ...{ class: "px-3 py-2 whitespace-nowrap text-sm text-gray-900" },
        });
        (account.name);
        __VLS_asFunctionalElement(__VLS_intrinsics.td, __VLS_intrinsics.td)({
            ...{ class: "px-3 py-2 whitespace-nowrap text-sm text-gray-500" },
        });
        (account.type);
        __VLS_asFunctionalElement(__VLS_intrinsics.td, __VLS_intrinsics.td)({
            ...{ class: (['px-3 py-2 whitespace-nowrap text-sm font-semibold', account.type === 'Liability' ? 'text-red-600' : 'text-green-600']) },
        });
        (__VLS_ctx.numberFormat(account.balance, 2));
        // @ts-ignore
        [numberFormat,];
        __VLS_asFunctionalElement(__VLS_intrinsics.td, __VLS_intrinsics.td)({
            ...{ class: "px-3 py-2 whitespace-nowrap text-sm text-gray-500" },
        });
        (account.currency_unit);
        __VLS_asFunctionalElement(__VLS_intrinsics.td, __VLS_intrinsics.td)({
            ...{ class: "px-3 py-2 whitespace-nowrap text-sm font-medium" },
        });
        __VLS_asFunctionalElement(__VLS_intrinsics.button, __VLS_intrinsics.button)({
            ...{ onClick: (...[$event]) => {
                    if (!!(__VLS_ctx.loading))
                        return;
                    if (!!(__VLS_ctx.accounts.length === 0))
                        return;
                    __VLS_ctx.handleDelete(account.name);
                    // @ts-ignore
                    [handleDelete,];
                } },
            ...{ class: "bg-red-600 hover:bg-red-700 text-white py-1 px-2 rounded text-xs transition duration-150" },
        });
    }
}
/** @type {__VLS_StyleScopedClasses['space-y-6']} */ ;
/** @type {__VLS_StyleScopedClasses['section-title']} */ ;
/** @type {__VLS_StyleScopedClasses['text-xl']} */ ;
/** @type {__VLS_StyleScopedClasses['font-semibold']} */ ;
/** @type {__VLS_StyleScopedClasses['text-amber-700']} */ ;
/** @type {__VLS_StyleScopedClasses['py-2']} */ ;
/** @type {__VLS_StyleScopedClasses['px-4']} */ ;
/** @type {__VLS_StyleScopedClasses['bg-green-600']} */ ;
/** @type {__VLS_StyleScopedClasses['text-white']} */ ;
/** @type {__VLS_StyleScopedClasses['font-semibold']} */ ;
/** @type {__VLS_StyleScopedClasses['rounded-md']} */ ;
/** @type {__VLS_StyleScopedClasses['hover:bg-green-700']} */ ;
/** @type {__VLS_StyleScopedClasses['transition']} */ ;
/** @type {__VLS_StyleScopedClasses['duration-150']} */ ;
/** @type {__VLS_StyleScopedClasses['text-center']} */ ;
/** @type {__VLS_StyleScopedClasses['text-gray-500']} */ ;
/** @type {__VLS_StyleScopedClasses['py-8']} */ ;
/** @type {__VLS_StyleScopedClasses['text-center']} */ ;
/** @type {__VLS_StyleScopedClasses['text-gray-500']} */ ;
/** @type {__VLS_StyleScopedClasses['p-4']} */ ;
/** @type {__VLS_StyleScopedClasses['border']} */ ;
/** @type {__VLS_StyleScopedClasses['rounded-md']} */ ;
/** @type {__VLS_StyleScopedClasses['bg-white']} */ ;
/** @type {__VLS_StyleScopedClasses['data-box']} */ ;
/** @type {__VLS_StyleScopedClasses['p-4']} */ ;
/** @type {__VLS_StyleScopedClasses['rounded-lg']} */ ;
/** @type {__VLS_StyleScopedClasses['shadow-sm']} */ ;
/** @type {__VLS_StyleScopedClasses['overflow-x-auto']} */ ;
/** @type {__VLS_StyleScopedClasses['bg-white']} */ ;
/** @type {__VLS_StyleScopedClasses['min-w-full']} */ ;
/** @type {__VLS_StyleScopedClasses['divide-y']} */ ;
/** @type {__VLS_StyleScopedClasses['divide-stone-200']} */ ;
/** @type {__VLS_StyleScopedClasses['account-table']} */ ;
/** @type {__VLS_StyleScopedClasses['bg-stone-50']} */ ;
/** @type {__VLS_StyleScopedClasses['px-3']} */ ;
/** @type {__VLS_StyleScopedClasses['py-2']} */ ;
/** @type {__VLS_StyleScopedClasses['text-left']} */ ;
/** @type {__VLS_StyleScopedClasses['text-xs']} */ ;
/** @type {__VLS_StyleScopedClasses['font-medium']} */ ;
/** @type {__VLS_StyleScopedClasses['text-gray-500']} */ ;
/** @type {__VLS_StyleScopedClasses['uppercase']} */ ;
/** @type {__VLS_StyleScopedClasses['px-3']} */ ;
/** @type {__VLS_StyleScopedClasses['py-2']} */ ;
/** @type {__VLS_StyleScopedClasses['text-left']} */ ;
/** @type {__VLS_StyleScopedClasses['text-xs']} */ ;
/** @type {__VLS_StyleScopedClasses['font-medium']} */ ;
/** @type {__VLS_StyleScopedClasses['text-gray-500']} */ ;
/** @type {__VLS_StyleScopedClasses['uppercase']} */ ;
/** @type {__VLS_StyleScopedClasses['px-3']} */ ;
/** @type {__VLS_StyleScopedClasses['py-2']} */ ;
/** @type {__VLS_StyleScopedClasses['text-left']} */ ;
/** @type {__VLS_StyleScopedClasses['text-xs']} */ ;
/** @type {__VLS_StyleScopedClasses['font-medium']} */ ;
/** @type {__VLS_StyleScopedClasses['text-gray-500']} */ ;
/** @type {__VLS_StyleScopedClasses['uppercase']} */ ;
/** @type {__VLS_StyleScopedClasses['px-3']} */ ;
/** @type {__VLS_StyleScopedClasses['py-2']} */ ;
/** @type {__VLS_StyleScopedClasses['text-left']} */ ;
/** @type {__VLS_StyleScopedClasses['text-xs']} */ ;
/** @type {__VLS_StyleScopedClasses['font-medium']} */ ;
/** @type {__VLS_StyleScopedClasses['text-gray-500']} */ ;
/** @type {__VLS_StyleScopedClasses['uppercase']} */ ;
/** @type {__VLS_StyleScopedClasses['px-3']} */ ;
/** @type {__VLS_StyleScopedClasses['py-2']} */ ;
/** @type {__VLS_StyleScopedClasses['text-left']} */ ;
/** @type {__VLS_StyleScopedClasses['text-xs']} */ ;
/** @type {__VLS_StyleScopedClasses['font-medium']} */ ;
/** @type {__VLS_StyleScopedClasses['text-gray-500']} */ ;
/** @type {__VLS_StyleScopedClasses['uppercase']} */ ;
/** @type {__VLS_StyleScopedClasses['divide-y']} */ ;
/** @type {__VLS_StyleScopedClasses['divide-stone-200']} */ ;
/** @type {__VLS_StyleScopedClasses['px-3']} */ ;
/** @type {__VLS_StyleScopedClasses['py-2']} */ ;
/** @type {__VLS_StyleScopedClasses['whitespace-nowrap']} */ ;
/** @type {__VLS_StyleScopedClasses['text-sm']} */ ;
/** @type {__VLS_StyleScopedClasses['text-gray-900']} */ ;
/** @type {__VLS_StyleScopedClasses['px-3']} */ ;
/** @type {__VLS_StyleScopedClasses['py-2']} */ ;
/** @type {__VLS_StyleScopedClasses['whitespace-nowrap']} */ ;
/** @type {__VLS_StyleScopedClasses['text-sm']} */ ;
/** @type {__VLS_StyleScopedClasses['text-gray-500']} */ ;
/** @type {__VLS_StyleScopedClasses['px-3']} */ ;
/** @type {__VLS_StyleScopedClasses['py-2']} */ ;
/** @type {__VLS_StyleScopedClasses['whitespace-nowrap']} */ ;
/** @type {__VLS_StyleScopedClasses['text-sm']} */ ;
/** @type {__VLS_StyleScopedClasses['font-semibold']} */ ;
/** @type {__VLS_StyleScopedClasses['px-3']} */ ;
/** @type {__VLS_StyleScopedClasses['py-2']} */ ;
/** @type {__VLS_StyleScopedClasses['whitespace-nowrap']} */ ;
/** @type {__VLS_StyleScopedClasses['text-sm']} */ ;
/** @type {__VLS_StyleScopedClasses['text-gray-500']} */ ;
/** @type {__VLS_StyleScopedClasses['px-3']} */ ;
/** @type {__VLS_StyleScopedClasses['py-2']} */ ;
/** @type {__VLS_StyleScopedClasses['whitespace-nowrap']} */ ;
/** @type {__VLS_StyleScopedClasses['text-sm']} */ ;
/** @type {__VLS_StyleScopedClasses['font-medium']} */ ;
/** @type {__VLS_StyleScopedClasses['bg-red-600']} */ ;
/** @type {__VLS_StyleScopedClasses['hover:bg-red-700']} */ ;
/** @type {__VLS_StyleScopedClasses['text-white']} */ ;
/** @type {__VLS_StyleScopedClasses['py-1']} */ ;
/** @type {__VLS_StyleScopedClasses['px-2']} */ ;
/** @type {__VLS_StyleScopedClasses['rounded']} */ ;
/** @type {__VLS_StyleScopedClasses['text-xs']} */ ;
/** @type {__VLS_StyleScopedClasses['transition']} */ ;
/** @type {__VLS_StyleScopedClasses['duration-150']} */ ;
const __VLS_export = (await import('vue')).defineComponent({
    setup: () => ({}),
});
export default {};
