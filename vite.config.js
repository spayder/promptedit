import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    // Inside Docker (Sail) Vite must listen on all interfaces, while the browser
    // on the host reaches it as localhost.
    server: {
        host: '0.0.0.0',
        hmr: { host: 'localhost' },
    },
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
});
