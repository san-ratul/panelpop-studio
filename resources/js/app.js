import { createInertiaApp } from '@inertiajs/vue3';
import { createApp, h } from 'vue';
import { createRouteResolver } from './route.js';
import '../css/app.css';

createInertiaApp({
    title: (title) => `${title} — ${import.meta.env.VITE_APP_NAME ?? 'Cartoon Shorts Studio'}`,
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue');
        return pages[`./Pages/${name}.vue`]();
    },
    setup({ el, App, props, plugin }) {
        const route = createRouteResolver(props.initialPage.props.routes);

        window.route = route;

        const app = createApp({ render: () => h(App, props) });
        app.use(plugin);
        app.config.globalProperties.route = route;
        app.mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
