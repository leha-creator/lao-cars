{{--
    Страница «не найдено» (веха 4.11).

    До неё в проекте не было ни одного своего шаблона ошибок: папки
    `resources/views/errors/` не существовало, `bootstrap/app.php` их
    не переопределял, и на любой опечатке в адресе отдавалась штатная
    страница Laravel — светлая, без шапки, без логотипа и без единой
    ссылки обратно. Заметно это стало не сразу и не всем: на 404 попадают
    редко, поэтому такую страницу всегда есть чем вытеснить из приоритета.

    Своего маршрута у файла нет и не нужно: Laravel сам ищет
    `errors/{статус}.blade.php` при отдаче ответа с этим кодом.

    В отличие от пятисотки (`500.blade.php`), эта страница стоит
    на ОБЩЕМ layout, с шапкой и подвалом. Разница не в оформлении:
    404 приходит от работающего приложения — база доступна, настройки
    читаются, меню и телефон в шапке на месте. Пятисотка приходит
    от сломанного, и там те же самые шапка с подвалом превратили бы одну
    ошибку в две.

    Канонический адрес гасится намеренно: layout по умолчанию печатает
    `url()->current()`, то есть на 404 он объявил бы каноническим адрес,
    которого нет. `noindex,follow` рядом — по тому же основанию, что
    и у отфильтрованной выдачи каталога: индексировать нечего, а ссылки
    со страницы (меню шапки и подвала) обойти нужно.
--}}
@extends('layouts.app')

@section('title', 'Страница не найдена — '.config('app.name'))
@section('robots', 'noindex,follow')

@section('canonical')
@endsection

@section('content')
    {{-- Светлая, как и остальные внутренние страницы (веха 4.11).
         `min-h` держит подвал внизу экрана: содержимого здесь три строки,
         и без него подвал уехал бы под шапку, а страница выглядела бы
         не короткой, а недогруженной. --}}
    <section class="theme-light flex min-h-[60svh] items-center px-5 py-24 lg:px-8 lg:py-32">
        <div class="mx-auto w-full max-w-page">
            <div class="mb-4 text-[13px] tracking-[0.2em] text-accent uppercase">Ошибка 404</div>

            <h1 class="max-w-160 font-display text-3xl leading-[1.15] font-semibold text-pretty lg:text-[38px]">
                Такой страницы <span class="text-accent">не существует</span>
            </h1>

            <p class="mt-5 max-w-2xl leading-relaxed text-ink-muted">
                Адрес введён с ошибкой или страница была удалена. Каталог автомобилей на месте — начните с него.
            </p>

            <a
                href="{{ route('catalog.index') }}"
                class="mt-9 inline-block rounded-full bg-accent-solid px-9 py-4.5 text-[15px] font-semibold tracking-[0.02em] text-on-accent transition hover:-translate-y-0.5 hover:bg-accent-hover"
            >В каталог автомобилей</a>
        </div>
    </section>
@endsection
