import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // Брендовый шрифт — Geist (брендбук стр. 51, лицензия OFL).
            // Подтягиваем с Bunny Fonts; когда дизайнер пришлёт дистрибутив
            // с кириллицей, переедем на self-host в public/fonts
            // (см. resources/css/app.css и открытый вопрос 9.2).
            fonts: [
                bunny('Geist', {
                    weights: [400, 700, 900],
                }),
                // Публичный Geist поставляется без кириллицы (проверено: в subset только
                // латиница). Пока дизайнер не пришлёт расширенную версию, кириллицу
                // закрывает Inter — ближайший по рисунку гротеск.
                bunny('Inter', {
                    weights: [400, 700, 900],
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
