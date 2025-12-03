/// <reference types="../node_modules/.vue-global-types/vue_3.5_0.d.ts" />
import { ref, computed } from 'vue';
import { liffState } from './main.js'; // 引入 LIFF 全局狀態
// 引入兩個 View 組件
import DashboardView from './views/DashboardView.vue';
import AccountManagerView from './views/AccountManagerView.vue';
const currentTab = ref('Dashboard');
const currentViewRef = ref(null);
// 計算當前要渲染的組件
const currentView = computed(() => {
    if (currentTab.value === 'Dashboard')
        return DashboardView;
    if (currentTab.value === 'Accounts')
        return AccountManagerView;
    return null;
});
// 處理 Accounts View 發來的刷新事件
const handleRefreshDashboard = () => {
    // 檢查當前顯示的 View 是否是 Dashboard
    if (currentView.value === DashboardView && currentViewRef.value) {
        // 調用 Dashboard View 暴露出的刷新方法
        currentViewRef.value.refreshAllData();
    }
};
debugger; /* PartiallyEnd: #3632/scriptSetup.vue */
const __VLS_ctx = {
    ...{},
    ...{},
};
let __VLS_components;
let __VLS_directives;
__VLS_asFunctionalElement(__VLS_intrinsics.div, __VLS_intrinsics.div)({
    ...{ class: "p-4 md:p-6" },
});
if (__VLS_ctx.liffState.error) {
    // @ts-ignore
    [liffState,];
    __VLS_asFunctionalElement(__VLS_intrinsics.div, __VLS_intrinsics.div)({
        ...{ class: "bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded text-center mb-6" },
    });
    __VLS_asFunctionalElement(__VLS_intrinsics.p, __VLS_intrinsics.p)({
        ...{ class: "font-bold" },
    });
    __VLS_asFunctionalElement(__VLS_intrinsics.p, __VLS_intrinsics.p)({
        ...{ class: "text-sm mt-1" },
    });
    (__VLS_ctx.liffState.error);
    // @ts-ignore
    [liffState,];
}
else if (!__VLS_ctx.liffState.isLoggedIn) {
    // @ts-ignore
    [liffState,];
    __VLS_asFunctionalElement(__VLS_intrinsics.div, __VLS_intrinsics.div)({
        ...{ class: "text-center my-20 text-gray-500" },
    });
    __VLS_asFunctionalElement(__VLS_intrinsics.p, __VLS_intrinsics.p)({
        ...{ class: "text-lg" },
    });
}
else {
    __VLS_asFunctionalElement(__VLS_intrinsics.div, __VLS_intrinsics.div)({});
    __VLS_asFunctionalElement(__VLS_intrinsics.h1, __VLS_intrinsics.h1)({
        ...{ class: "text-3xl font-light text-amber-700 pb-3 mb-4 border-b" },
    });
    __VLS_asFunctionalElement(__VLS_intrinsics.div, __VLS_intrinsics.div)({
        ...{ class: "tabs flex border-b border-gray-200 mb-6" },
    });
    __VLS_asFunctionalElement(__VLS_intrinsics.button, __VLS_intrinsics.button)({
        ...{ onClick: (...[$event]) => {
                if (!!(__VLS_ctx.liffState.error))
                    return;
                if (!!(!__VLS_ctx.liffState.isLoggedIn))
                    return;
                __VLS_ctx.currentTab = 'Dashboard';
                // @ts-ignore
                [currentTab,];
            } },
        ...{ class: (['tab-button px-4 py-2 text-sm font-medium border-b-2 transition duration-150', __VLS_ctx.currentTab === 'Dashboard' ? 'border-amber-700 text-amber-700' : 'border-transparent text-gray-700 hover:text-amber-700']) },
    });
    // @ts-ignore
    [currentTab,];
    __VLS_asFunctionalElement(__VLS_intrinsics.button, __VLS_intrinsics.button)({
        ...{ onClick: (...[$event]) => {
                if (!!(__VLS_ctx.liffState.error))
                    return;
                if (!!(!__VLS_ctx.liffState.isLoggedIn))
                    return;
                __VLS_ctx.currentTab = 'Accounts';
                // @ts-ignore
                [currentTab,];
            } },
        ...{ class: (['tab-button px-4 py-2 text-sm font-medium border-b-2 transition duration-150', __VLS_ctx.currentTab === 'Accounts' ? 'border-amber-700 text-amber-700' : 'border-transparent text-gray-700 hover:text-amber-700']) },
    });
    // @ts-ignore
    [currentTab,];
    const __VLS_0 = ((__VLS_ctx.currentView));
    // @ts-ignore
    const __VLS_1 = __VLS_asFunctionalComponent(__VLS_0, new __VLS_0({
        ...{ 'onRefreshDashboard': {} },
        ref: "currentViewRef",
    }));
    const __VLS_2 = __VLS_1({
        ...{ 'onRefreshDashboard': {} },
        ref: "currentViewRef",
    }, ...__VLS_functionalComponentArgsRest(__VLS_1));
    let __VLS_5;
    const __VLS_6 = ({ refreshDashboard: {} },
        { onRefreshDashboard: (__VLS_ctx.handleRefreshDashboard) });
    /** @type {typeof __VLS_ctx.currentViewRef} */ ;
    var __VLS_7 = {};
    // @ts-ignore
    [currentView, handleRefreshDashboard, currentViewRef,];
    var __VLS_3;
    var __VLS_4;
}
/** @type {__VLS_StyleScopedClasses['p-4']} */ ;
/** @type {__VLS_StyleScopedClasses['md:p-6']} */ ;
/** @type {__VLS_StyleScopedClasses['bg-red-100']} */ ;
/** @type {__VLS_StyleScopedClasses['border']} */ ;
/** @type {__VLS_StyleScopedClasses['border-red-400']} */ ;
/** @type {__VLS_StyleScopedClasses['text-red-700']} */ ;
/** @type {__VLS_StyleScopedClasses['px-4']} */ ;
/** @type {__VLS_StyleScopedClasses['py-3']} */ ;
/** @type {__VLS_StyleScopedClasses['rounded']} */ ;
/** @type {__VLS_StyleScopedClasses['text-center']} */ ;
/** @type {__VLS_StyleScopedClasses['mb-6']} */ ;
/** @type {__VLS_StyleScopedClasses['font-bold']} */ ;
/** @type {__VLS_StyleScopedClasses['text-sm']} */ ;
/** @type {__VLS_StyleScopedClasses['mt-1']} */ ;
/** @type {__VLS_StyleScopedClasses['text-center']} */ ;
/** @type {__VLS_StyleScopedClasses['my-20']} */ ;
/** @type {__VLS_StyleScopedClasses['text-gray-500']} */ ;
/** @type {__VLS_StyleScopedClasses['text-lg']} */ ;
/** @type {__VLS_StyleScopedClasses['text-3xl']} */ ;
/** @type {__VLS_StyleScopedClasses['font-light']} */ ;
/** @type {__VLS_StyleScopedClasses['text-amber-700']} */ ;
/** @type {__VLS_StyleScopedClasses['pb-3']} */ ;
/** @type {__VLS_StyleScopedClasses['mb-4']} */ ;
/** @type {__VLS_StyleScopedClasses['border-b']} */ ;
/** @type {__VLS_StyleScopedClasses['tabs']} */ ;
/** @type {__VLS_StyleScopedClasses['flex']} */ ;
/** @type {__VLS_StyleScopedClasses['border-b']} */ ;
/** @type {__VLS_StyleScopedClasses['border-gray-200']} */ ;
/** @type {__VLS_StyleScopedClasses['mb-6']} */ ;
/** @type {__VLS_StyleScopedClasses['tab-button']} */ ;
/** @type {__VLS_StyleScopedClasses['px-4']} */ ;
/** @type {__VLS_StyleScopedClasses['py-2']} */ ;
/** @type {__VLS_StyleScopedClasses['text-sm']} */ ;
/** @type {__VLS_StyleScopedClasses['font-medium']} */ ;
/** @type {__VLS_StyleScopedClasses['border-b-2']} */ ;
/** @type {__VLS_StyleScopedClasses['transition']} */ ;
/** @type {__VLS_StyleScopedClasses['duration-150']} */ ;
/** @type {__VLS_StyleScopedClasses['tab-button']} */ ;
/** @type {__VLS_StyleScopedClasses['px-4']} */ ;
/** @type {__VLS_StyleScopedClasses['py-2']} */ ;
/** @type {__VLS_StyleScopedClasses['text-sm']} */ ;
/** @type {__VLS_StyleScopedClasses['font-medium']} */ ;
/** @type {__VLS_StyleScopedClasses['border-b-2']} */ ;
/** @type {__VLS_StyleScopedClasses['transition']} */ ;
/** @type {__VLS_StyleScopedClasses['duration-150']} */ ;
// @ts-ignore
var __VLS_8 = __VLS_7;
const __VLS_export = (await import('vue')).defineComponent({});
export default {};
