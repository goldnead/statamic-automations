import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwind from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';

/**
 * Vite config for the Statamic Automations addon.
 *
 * Statamic 6 addons are Inertia.js + Vue 3 + Tailwind v4. Pages
 * register themselves through Statamic.$inertia.register() inside
 * cp.js — Statamic's Inertia plugin then dispatches to them.
 *
 * Output:
 *   - resources/dist/cp.js (entry)
 *   - resources/dist/cp.css (Tailwind v4 + addon styles)
 *
 * The ServiceProvider publishes these into public/vendor/statamic-automations
 * via `vendor:publish --tag=statamic-automations-assets`.
 */
export default defineConfig({
    plugins: [
        vue(),
        tailwind(),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    // `@statamic/cms/ui` and `@statamic/cms/inertia` are provided by the
    // host Statamic install at runtime — never bundled into our cp.js.
    // This keeps the addon bundle small AND ensures we use the same
    // exact UI version the user is running.
    build: {
        outDir: 'resources/dist',
        emptyOutDir: true,
        manifest: true,
        rollupOptions: {
            external: [
                '@statamic/cms',
                '@statamic/cms/ui',
                '@statamic/cms/inertia',
            ],
            input: {
                cp: fileURLToPath(new URL('./resources/js/cp.js', import.meta.url)),
            },
            output: {
                entryFileNames: '[name].js',
                chunkFileNames: 'chunks/[name]-[hash].js',
                assetFileNames: '[name][extname]',
                globals: {
                    '@statamic/cms': 'Statamic',
                    '@statamic/cms/ui': 'StatamicUI',
                    '@statamic/cms/inertia': 'StatamicInertia',
                },
            },
        },
    },
});
