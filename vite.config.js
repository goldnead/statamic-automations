import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

/**
 * Vite config for the Statamic Automations addon.
 *
 * Produces a single `cp.js` (and accompanying CSS) into
 * `resources/dist/` which the ServiceProvider can publish into
 * Statamic's Control Panel.
 */
export default defineConfig({
    plugins: [vue()],
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
