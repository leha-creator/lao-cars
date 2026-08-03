import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// Блок `fonts: [bunny('Instrument Sans', ...)]` из скелета Laravel убран:
// он подключал шрифт с внешнего CDN, а вехе 4.1 нужны Unbounded и Manrope.
// Шрифты подключаются вместе с токенами дизайн-системы в resources/css/app.css.

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
