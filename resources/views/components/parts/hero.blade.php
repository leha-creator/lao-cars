@props([
    'hero',
    'page' => false,
])

{{--
    Первый экран страницы запчастей — и он же блок каталога автозапчастей
    на главной. Данные готовит `App\Services\PartsHeroContent`; сюда блок
    приходит уже собранным и только с заполненным заголовком.

    Разметка ОДНА на обе страницы, а различия заданы пропсом `page`:
    на странице запчастей это первый экран (H1, изображение — LCP, секция
    подтянута под прозрачную шапку), на главной — обычная секция посреди
    страницы (H2, ленивая загрузка, якорь `parts-catalog`). Вторая копия
    разметки разъехалась бы с первой на первой же правке текста кнопки.

    Секция тёмная на обеих страницах и `theme-light` НЕ получает: под
    текстом лежит фотография с маской, а не фон страницы, и чернила обязаны
    остаться светлыми. На главной она при этом стоит между двумя светлыми
    секциями — это намеренно.

    ПОДТЯЖКА ПОД ШАПКУ. Отрицательный отступ секции равен собственной
    высоте шапки: 66px на мобильном, 76px от `lg`. Те же числа стоят
    у хиро главной в `home/index.blade.php`, и общего источника у них нет:
    изменится высота шапки — править в ОБОИХ местах, иначе над фотографией
    появится полоса фона или содержимое наедет под шапку. Тем же числом
    содержимое отодвинуто вниз, чтобы текст не оказался под меню.

    Высота задана минимальная и в `svh` (правила `RULES.md`): фиксированная
    в паре с `overflow-hidden` обрезала бы длинное описание молча. Она
    заметно ниже хиро главной — под блоком идут карточки запчастей,
    и первый экран не должен прятать их целиком.

    Маска обязательна и при «удачном» снимке: фон выбирает заказчик
    из медиабиблиотеки, и сегодняшний тёмный кадр завтра сменится светлым.
    Читаемость не должна зависеть от того, что загрузили.

    `srcset` не задан намеренно: у файла медиабиблиотеки одна ширина,
    а дескриптор без настоящих ширин заставляет браузер выбрать не тот файл.

    Без фона блок остаётся на тёмной заливке — изображение необязательно.
--}}
<section
    @unless ($page) id="parts-catalog" @endunless
    {{ $attributes->class([
        'relative flex flex-col overflow-hidden bg-deep',
        '-mt-[66px] min-h-[62svh] lg:-mt-[76px] lg:min-h-[560px]' => $page,
        'min-h-[420px] lg:min-h-[520px]' => ! $page,
    ]) }}
>
    @if ($hero['image_url'] !== null)
        <div class="absolute inset-0">
            {{-- Изображение декоративное: смысл несёт текст поверх него,
                 поэтому пустой `alt` и `aria-hidden`.

                 На странице запчастей это LCP-элемент: `fetchpriority="high"`
                 и никакого `loading`. На главной блок стоит глубоко ниже
                 первого экрана, и там наоборот — ленивая загрузка. --}}
            <img
                src="{{ $hero['image_url'] }}"
                @if ($page) fetchpriority="high" @else loading="lazy" @endif
                alt=""
                aria-hidden="true"
                class="size-full object-cover"
            >

            {{-- Стопы — те же, что у маски хиро главной. --}}
            <div class="absolute inset-0 bg-gradient-to-b from-page/10 via-page/55 via-55% to-page/96"></div>
        </div>
    @endif

    <div @class([
        'relative z-2 flex flex-1 items-end px-5 pb-12 lg:px-8 lg:pb-16',
        'pt-[90px] lg:pt-[108px]' => $page,
        'pt-12 lg:pt-16' => ! $page,
    ])>
        <div class="mx-auto w-full max-w-page">
            <div class="mb-4 text-[13px] tracking-[0.2em] text-accent uppercase">Запчасти</div>

            @if ($page)
                <h1 class="mb-5 max-w-3xl font-display text-[30px] leading-[1.16] font-semibold text-pretty sm:text-[38px] lg:text-[46px]">{{ $hero['title'] }}</h1>
            @else
                <h2 class="mb-5 max-w-3xl font-display text-3xl leading-[1.15] font-semibold text-pretty lg:text-[38px]">{{ $hero['title'] }}</h2>
            @endif

            @if ($hero['text'] !== null)
                <p class="max-w-[38rem] text-lg leading-[1.65] text-ink-muted text-pretty">{{ $hero['text'] }}</p>
            @endif

            {{-- Кнопка приходит уже проверенной: без подписи, без адреса
                 или с адресом не по http(s) сервис отдаёт `null`. Внешний
                 адрес открывается в новой вкладке, и `noopener` там
                 обязателен — без него открытая страница получает доступ
                 к нашей вкладке через `window.opener`. --}}
            @if ($hero['button'] !== null)
                <div class="mt-8">
                    <a
                        href="{{ $hero['button']['url'] }}"
                        @if ($hero['button']['external']) target="_blank" rel="noopener noreferrer" @endif
                        class="inline-block rounded-full bg-accent-solid px-9 py-4.5 text-[15px] font-semibold tracking-[0.02em] text-on-accent transition hover:-translate-y-0.5 hover:bg-accent-hover"
                    >{{ $hero['button']['text'] }}</a>
                </div>
            @endif
        </div>
    </div>
</section>
