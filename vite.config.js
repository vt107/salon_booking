import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // Cả hai font đều có bộ dấu tiếng Việt; phải khai báo subset 'vietnamese'
            fonts: [
                bunny('Fraunces', {
                    weights: [400, 500, 600],
                    styles: ['normal', 'italic'],
                    subsets: ['latin', 'latin-ext', 'vietnamese'],
                }),
                bunny('Be Vietnam Pro', {
                    weights: [400, 500, 600],
                    subsets: ['latin', 'latin-ext', 'vietnamese'],
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
