import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { google } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

// Шрифты самохостятся сборкой, а не подключаются тегом <link> с
// fonts.googleapis.com, как в макете: внешний CDN на критическом пути рендера —
// это и лишний блокирующий запрос к чужому домену, и передача IP каждого
// посетителя третьей стороне. Плагин скачивает woff2 на сборке, кладёт их в
// public/build, собирает @font-face, preload-ссылки и CSS-переменные
// --font-unbounded / --font-manrope; в шаблон это приходит директивой @fonts.
//
// Пакет `fontaine` в devDependencies стоит ровно ради умолчания
// `optimizedFallbacks: true`: он считает метрики шрифта и подгоняет под них
// системный фолбэк, чтобы подмена при `display: swap` не дёргала вёрстку.
// Без пакета плагин печатает предупреждение на каждой сборке и молча
// выключает фичу.
//
// Алиасы намеренно оставлены умолчательными. Соблазн задать alias: 'display' и
// получить сразу --font-display велик, но тогда одну и ту же переменную на
// :root пишут двое — инлайновый стиль @fonts и блок @theme Tailwind, — и кто
// выиграет, зависит от порядка тегов в <head>. Имена разведены: плагин даёт
// --font-unbounded и --font-manrope, @theme объявляет через них --font-display
// и --font-sans (см. resources/css/app.css).
const fonts = [
    // Веса по UI Kit макета (§03): 500 для H2–H3, 600 для H1, акцентов и цен,
    // 700 для чисел. Курсива в макете нет — не подключается.
    google('Unbounded', {
        weights: [500, 600, 700],
        // `subsets` — не оптимизация, а условие работоспособности: умолчание
        // провайдера ['latin'], а сайт русский целиком. Без кириллического
        // подмножества скачанный файл не содержит нужных глифов, и браузер
        // молча уезжает в системный шрифт — при живом @font-face и работающем
        // preload. Ни один тест на «шрифт запрошен» этого не поймает.
        subsets: ['latin', 'cyrillic'],
        // Умолчание `preload: true` прелоадит все варианты всех сабсетов —
        // четырнадцать файлов конкурируют за канал на первом экране. В preload
        // остаётся одно начертание на семейство: то, которым набран первый
        // видимый текст.
        preload: [{ weight: 600 }],
    }),
    // 400 текст, 500 лейблы, 600–700 кнопки и акценты в тексте (UI Kit §03).
    google('Manrope', {
        weights: [400, 500, 600, 700],
        subsets: ['latin', 'cyrillic'],
        preload: [{ weight: 400 }],
    }),
];

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
