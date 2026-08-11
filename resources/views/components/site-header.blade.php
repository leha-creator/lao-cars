{{--
    Шапка сайта по макету: `Catalog.dc.html` (внутренние страницы) и
    `Home.dc.html` (вариант поверх хиро).

    Состав меню берётся из `App\Support\SiteMenu` и намеренно расходится
    с макетом: там «Услуги» и «Блог», здесь «Автосервис» и «Запчасти».
    Причина записана в PHPDoc `SiteMenu`.

    Мобильное меню раскрывает Alpine, но ссылки внутри — обычные `<a>`,
    а телефон и кнопка заявки вынесены в саму шапку, снаружи бургера:
    между отрисовкой страницы и стартом Alpine есть окно, и в это окно
    посетитель не должен терять контакт с компанией.
--}}
<header
    @class([
        'z-50',
        // Поверх хиро (веха 4.2): без липкости, фон — градиент, чтобы
        // фотография под шапкой оставалась видимой.
        'relative bg-gradient-to-b from-page/92 to-page/0' => $overlay,
        // Внутренние страницы: липкая, полупрозрачная с размытием.
        'sticky top-0 border-b border-white/10 bg-page/90 backdrop-blur-[14px]' => ! $overlay,
    ])
>
    <div class="mx-auto flex max-w-page items-center justify-between gap-6 px-5 py-3 lg:px-8 lg:py-4">
        <a href="{{ route('home') }}" class="flex shrink-0 items-center" aria-label="ЛАО КАРС — на главную">
            {{-- Размеры проставлены явно: без них шапка меняет высоту
                 в момент загрузки логотипа и уводит содержимое вниз. --}}
            <img
                src="{{ Vite::asset('resources/images/logo-white.png') }}"
                alt="ЛАО КАРС"
                width="440"
                height="128"
                class="h-[26px] w-auto lg:h-[30px]"
            >
        </a>

        <nav class="hidden min-w-0 shrink items-center gap-6 text-sm whitespace-nowrap lg:flex xl:gap-7">
            @foreach ($items() as $item)
                <a
                    href="{{ $item['url'] }}"
                    @class([
                        'transition-opacity',
                        'text-accent' => $isActive($item['name']),
                        'opacity-85 hover:opacity-100' => ! $isActive($item['name']),
                    ])
                    @if ($isActive($item['name'])) aria-current="page" @endif
                >{{ $item['label'] }}</a>
            @endforeach
        </nav>

        <div class="flex shrink-0 items-center gap-2.5 lg:gap-4">
            @if ($phone !== null)
                {{-- На десктопе телефон читается текстом, на мобильном
                     превращается в кнопку-иконку: место в шапке 390px
                     занимают логотип и бургер. --}}
                <a href="tel:{{ $phoneHref() }}" class="hidden text-sm opacity-90 transition-opacity hover:opacity-100 lg:inline">
                    {{ $phone }}
                </a>

                {{-- Иконка, а не символ `☎`: он глиф шрифта, и часть систем
                     подставляет вместо него цветное эмодзи, которое не
                     слушается `text-accent` и не масштабируется вместе
                     с кнопкой. Ломается это только у части посетителей —
                     ровно у тех, у кого мы этого не видим.

                     Подпись даёт `aria-label` кнопки: сама иконка
                     `aria-hidden`, иначе скринридер прочитал бы её дважды. --}}
                <a
                    href="tel:{{ $phoneHref() }}"
                    class="flex size-[42px] items-center justify-center rounded-field border border-white/10 bg-white/6 lg:hidden"
                    aria-label="Позвонить: {{ $phone }}"
                >
                    <x-ui.icon name="phone" class="size-[18px]" />
                </a>
            @endif

            <a
                href="#lead-form"
                class="hidden rounded-full bg-accent px-6 py-3 text-sm font-semibold tracking-[0.02em] text-page transition hover:-translate-y-px hover:bg-accent-hover lg:inline-block"
            >Оставить заявку</a>

            {{-- Мобильное меню. `x-cloak` прячет панель до инициализации
                 Alpine — иначе на медленном соединении она моргает
                 раскрытой на весь экран. --}}
            <div
                x-data="{ open: false }"
                x-effect="document.body.classList.toggle('overflow-hidden', open)"
                @keydown.escape.window="open = false"
                class="lg:hidden"
            >
                <button
                    type="button"
                    x-on:click="open = true"
                    class="flex size-[42px] flex-col items-center justify-center gap-[5px] rounded-field bg-accent"
                    aria-label="Открыть меню"
                >
                    <span class="block h-0.5 w-[17px] rounded-sm bg-page"></span>
                    <span class="block h-0.5 w-[17px] rounded-sm bg-page"></span>
                    <span class="block h-0.5 w-[17px] rounded-sm bg-page"></span>
                </button>

                <div
                    x-show="open"
                    x-cloak
                    x-transition.opacity
                    class="fixed inset-0 z-100 flex flex-col bg-page/98 p-5 backdrop-blur-[10px]"
                >
                    <div class="mb-11 flex items-center justify-between">
                        <img
                            src="{{ Vite::asset('resources/images/logo-white.png') }}"
                            alt="ЛАО КАРС"
                            width="440"
                            height="128"
                            class="h-[26px] w-auto"
                        >

                        {{-- `flex` с центровкой обязателен: у кнопки задан
                             только размер, и без него SVG прижимается
                             к левому верхнему углу — при живой кнопке
                             и правильном размере. --}}
                        <button
                            type="button"
                            x-on:click="open = false"
                            class="flex size-[42px] items-center justify-center rounded-field border border-white/10 bg-white/6"
                            aria-label="Закрыть меню"
                        >
                            <x-ui.icon name="close" class="size-[18px]" />
                        </button>
                    </div>

                    <nav class="flex flex-col">
                        @foreach ($items() as $item)
                            <a
                                href="{{ $item['url'] }}"
                                x-on:click="open = false"
                                @class([
                                    'border-b border-white/10 py-3.5 font-display text-2xl font-medium last:border-b-0',
                                    'text-accent' => $isActive($item['name']),
                                ])
                            >{{ $item['label'] }}</a>
                        @endforeach
                    </nav>

                    <div class="mt-auto flex flex-col gap-3.5">
                        @if ($phone !== null)
                            <a href="tel:{{ $phoneHref() }}" class="font-display text-xl text-accent">{{ $phone }}</a>
                        @endif

                        <a
                            href="#lead-form"
                            x-on:click="open = false"
                            class="rounded-full bg-accent py-4 text-center text-sm font-semibold text-page"
                        >Оставить заявку</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>
