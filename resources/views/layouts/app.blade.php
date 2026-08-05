{{--
    Каркас публичной части.

    Собран вехой 4.1 по UI Kit макета: токены дизайн-системы живут
    в `resources/css/app.css`, шапка и подвал — компоненты `x-site-header`
    и `x-site-footer`. Панель Filament этот layout не использует — у неё
    собственная тема.

    SEO-задел вех 4.x — сменные title, description, canonical и robots.
    Публичная часть рендерится на сервере: списки каталога должны
    индексироваться, а фильтры оставаться обычными GET-параметрами.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Цвет адресной строки мобильных браузеров. В hex, а не `oklch()`:
         поддержка современного синтаксиса цвета в `theme-color` неполная.
         Значение — точный эквивалент токена `--color-page`
         (`oklch(0.13 0.004 60)`), пересчитанный один раз. --}}
    <meta name="theme-color" content="#080706">

    <title>@yield('title', config('app.name'))</title>

    @hasSection('description')
        <meta name="description" content="@yield('description')">
    @endif

    {{-- Управление индексацией задают сами страницы. Каталог уводит
         в noindex,follow любую комбинацию фильтров: follow обязателен —
         ссылки на карточки со страницы должны обходиться. Пагинация под
         это правило не подпадает, страница 2 обязана индексироваться. --}}
    @hasSection('robots')
        <meta name="robots" content="@yield('robots')">
    @endif

    {{-- Канонический URL без query-строки: фильтры каталога не должны плодить
         дубли страниц в индексе. Карточки авто переопределяют секцию, когда им
         нужен собственный канонический адрес. --}}
    @section('canonical')
        <link rel="canonical" href="{{ url()->current() }}">
    @show

    {{-- Шрифты самохостятся сборкой (см. блок `fonts` в `vite.config.js`),
         а не подключаются с fonts.googleapis.com, как в макете. Директива
         идёт до `@vite`, чтобы preload-ссылки на woff2 попали в разбор
         раньше блокирующего CSS. --}}
    @fonts

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen antialiased">
    {{-- Меню из четырёх пунктов на каждой странице — это четыре лишних
         таба до содержимого для того, кто ходит по сайту с клавиатуры. --}}
    <a
        href="#content"
        class="sr-only rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-page focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-200"
    >Перейти к содержимому</a>

    {{-- Место для бегущей строки главной (веха 4.2). В макете она стоит выше
         шапки, то есть технически принадлежит каркасу; по содержанию это
         настройка `home.ticker`, то есть контент страницы. Секция объявлена
         здесь, чтобы вехе 4.2 не пришлось править layout ради одного блока. --}}
    @yield('above-header')

    <x-site-header :overlay="$headerOverlay ?? false" />

    <main id="content">
        @yield('content')
    </main>

    <x-site-footer />

    {{-- Задел под страницы со своим JS.

         Alpine сюда не кладётся, и это не вкус, а ловушка: `@vite` отдаёт
         `app.js` модулем из `<head>`, модуль исполняется отложенно и сам
         вызывает `Alpine.start()`. Пушнутый сюда скрипт исполнится либо
         раньше модуля (обычный `<script>` — `window.Alpine` ещё нет), либо
         позже (`type="module"` — событие `alpine:init` уже отгремело,
         а после старта `Alpine.data()` к разобранной разметке
         не применяется). Оба варианта ломаются молча: разметка и стили
         на месте, клики не работают.

         Поэтому галерея карточки автомобиля (веха 4.3) и авто-сабмит
         фильтров каталога написаны инлайном в `x-data` — как мобильное
         меню шапки. Стек остаётся для скриптов, которым порядок Alpine
         безразличен: счётчики, карты, виджеты. --}}
    @stack('scripts')
</body>
</html>
