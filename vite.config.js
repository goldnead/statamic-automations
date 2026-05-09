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
 * `@statamic/cms`, `@statamic/cms/ui` and `@statamic/cms/inertia` are
 * provided by the host Statamic install at runtime — they are NOT
 * installed via npm. We mark them external so Vite leaves the import
 * statements untouched in the output bundle; Statamic's CP loader
 * resolves them when the bundle runs.
 *
 * Output:
 *   - resources/dist/cp.js (entry, ESM)
 *   - resources/dist/cp.css (Tailwind v4 + addon styles)
 *
 * The ServiceProvider then publishes these via
 * `php artisan vendor:publish --tag=statamic-automations-assets`.
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
    build: {
        outDir: 'resources/dist',
        emptyOutDir: true,
        manifest: true,
        rollupOptions: {
            // Statamic ships these — they are not on npm.
            external: [
                /^@statamic\/cms($|\/.+)/,
            ],
            input: {
                cp: fileURLToPath(new URL('./resources/js/cp.js', import.meta.url)),
            },
            output: {
                entryFileNames: '[name].js',
                chunkFileNames: 'chunks/[name]-[hash].js',
                assetFileNames: '[name][extname]',
            },
        },
    },
});
