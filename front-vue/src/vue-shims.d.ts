// 告訴 TypeScript 如何處理所有以 .vue 結尾的檔案
declare module '*.vue' {
    import type { DefineComponent } from 'vue';
    // eslint-disable-next-line @typescript-eslint/ban-types
    const component: DefineComponent<{}, {}, any>;
    export default component;
  }