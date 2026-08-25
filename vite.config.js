import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import statamic from '@statamic/cms/vite-plugin';

/**
 * Vite config for the Statamic Automations addon.
 *
 * Statamic 6 addons are Inertia.js + Vue 3 + Tailwind v4. The CP pages
 * import from `@statamic/cms/inertia` and `@statamic/cms/ui`; those bare
 * specifiers are resolved against the host Control Panel at runtime, not
 * bundled. The official `@statamic/cms/vite-plugin` wires that up: it
 * externalises `vue` to the CP runtime build and registers the Vue plugin,
 * so the addon's `@statamic/cms/*` imports resolve against the host instead
 * of being re-bundled (which would otherwise ship a second Vue instance and
 * 500 the CP).
 *
 * laravel-vite-plugin emits the manifest flat at resources/dist/build/, the
 * same `<publicDirectory>/build` location configured on the ServiceProvider's
 * `$vite` property. Statamic's AddonServiceProvider publishes those compiled
 * assets to the host's public/vendor/<package>/build/ on install, and the
 * Laravel/Statamic Vite tag serves them in the CP — no end-user build step.
 *
 * Third-party Vue libraries that are NOT part of the CP runtime (the Vue Flow
 * canvas, axios) are bundled normally; only `vue` and `@statamic/cms/*` are
 * externalised by the Statamic plugin.
 *
 * ---------------------------------------------------------------------------
 * One config, two jobs.
 *
 * Everything above is correct for a bundle that runs inside the Control Panel
 * and fatal in a test process. The Statamic plugin rewrites `vue` to
 * `window.Vue`, and there is no `window.Vue` under Vitest; the imports have to
 * resolve for real there.
 *
 * So under Vitest the SFCs are compiled with the plain Vue plugin instead. The
 * `@statamic/cms/*` entry points then resolve through node_modules (symlinked
 * to `vendor/statamic/cms/resources/dist-package`) and read their components
 * off the `__STATAMIC__` global, which `tests/js/setup.js` populates with stubs
 * before any test module is imported.
 *
 * `@vitejs/plugin-vue` is not listed in devDependencies on purpose: it is a
 * declared dependency of `@statamic/cms`, which this package depends on, so it
 * is always installed and always the version the CP itself compiles with.
 * ---------------------------------------------------------------------------
 */
const isTest = !!process.env.VITEST;

export default defineConfig({
    resolve: {
        // `@goldnead/flow-canvas` is installed from a Composer path repository,
        // so npm links it and its files resolve from outside this project. Then
        // `vue`, `@vue-flow/*` and `@statamic/cms` cannot be found from there
        // and every import in the shared editor fails. Keeping the symlinked
        // path means resolution walks this project's node_modules, which is
        // where those live — and where they must stay, so there is one Vue on
        // the page and one flow library.
        preserveSymlinks: true,
    },

    plugins: isTest
        ? [vue()]
        : [
            laravel({
                input: [
                    'resources/js/cp.js',
                    'resources/css/cp.css',
                ],
                publicDirectory: 'resources/dist',
                refresh: true,
            }),
            statamic(),
            tailwindcss(),
        ],

    test: {
        environment: 'jsdom',
        // The shared editor lives outside this project's root, so Vite would
        // hand its `.vue` files to Node untransformed and Node has never heard
        // of `.vue`. Inlining puts them back through the transform pipeline,
        // which is what makes the shared components testable from here at all.
        server: {
            deps: {
                inline: [/statamic-flow-canvas/, /@goldnead\/flow-canvas/],
            },
        },
        // The node:test suite in tests/js/*.test.mjs stays where it is: it
        // covers pure functions and needs no DOM, no compiler and no CP. Vitest
        // takes the `.test.js` files, which mount components.
        include: ['tests/js/**/*.test.js'],
        setupFiles: ['tests/js/setup.js'],
    },
});
