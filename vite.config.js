import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // Module front ends are inputs to the one root build rather than
            // separate per-module builds: there is a single public site, and
            // one manifest is far less to reason about than several.
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'Modules/PublicSite/resources/js/building.js',
                'Modules/PublicSite/resources/js/floor.js',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
