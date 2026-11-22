import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from 'ziggy-js';

const appName = import.meta.env.VITE_APP_NAME || 'PixelPilot';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    // Pages are split into their own chunks and loaded on first visit.
    resolve: async (name) => {
        const pages = import.meta.glob<DefineComponent>('./pages/**/*.vue');
        const load = pages[`./pages/${name}.vue`];

        if (!load) {
            throw new Error(`Page not found: ${name}`);
        }

        return load();
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: { color: '#673DE6' },
});
