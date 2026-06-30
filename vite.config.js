import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
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
 */
export default defineConfig({
    plugins: [
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
});
