{{--
    Базовый каркас публичной части.

    Намеренно минимальный: вёрстка по UI Kit и токены дизайн-системы — веха 4.1.
    Здесь только то, без чего страницы не собрать, и задел под SEO вех 4.x —
    сменные title, description и canonical. Публичная часть рендерится на
    сервере: списки каталога должны индексироваться, а фильтры оставаться
    обычными GET-параметрами.

    Панель Filament этот layout не использует — у неё собственная тема.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

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

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white text-gray-900 antialiased">
    @yield('content')
</body>
</html>
