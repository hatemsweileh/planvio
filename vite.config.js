import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

/**
 * Planvio is self-hosted and must build and run with no external network calls.
 * Inter is vendored under public/fonts/inter and declared in resources/css/app.css,
 * so no webfont CDN plugin is used here.
 */
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: [
                'resources/views/**',
                'app/Livewire/**',
            ],
        }),
        tailwindcss(),
    ],
    build: {
        // Production ships these compiled files inside the release ZIP; keep them
        // reproducible and free of source maps that would leak internal paths.
        sourcemap: false,
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**', '**/vendor/**'],
        },
    },
});
