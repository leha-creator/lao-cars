{{--
    Пагинация каталога (веха 4.3) по макету `Catalog.dc.html`, строки 148–153:
    квадраты 40×40 с радиусом поля, активная страница залита акцентом,
    остальные — обводкой.

    Вид собственный, а не опубликованный в `resources/views/vendor/pagination/`,
    и передаётся именем в `links('pagination.catalog')`, а не ставится через
    `Paginator::defaultView()`. Причины две, и обе — про молчаливые поломки:

    1. `Paginator::defaultView()` — глобальная статика. Она подменяет вид
       у любого Livewire-компонента с `WithPagination` и возвращает прежний
       только при штатном уничтожении, которого при HTTP-запросе к странице
       Filament не происходит. Сторож с подробным объяснением уже стоит
       в `Pest.php` — заводить причину для его срабатывания незачем.
    2. Публикация в `vendor/pagination/tailwind.blade.php` — то же самое
       под другим именем: файл с вендорным именем перехватывает вид у всего
       приложения, а в проекте живёт Filament со своей пагинацией.

    Вендорный вид отсюда больше не используется вовсе — он светлый
    (`bg-white`, `text-gray-800`, `ring-gray-300`) и держал в сборке палитру
    Tailwind по умолчанию через `@source` на вендорную папку. Эта строка
    уходит из `app.css` вместе с обнулением палитры.

    Стрелки не рендерятся мёртвыми: на первой странице нет «назад»,
    на последней — «вперёд». Серый неактивный квадрат — приглашение
    кликнуть в никуда.
--}}
@if ($paginator->hasPages())
    <nav class="mt-12 flex justify-center lg:mt-16" aria-label="Навигация по страницам каталога">
        <div class="flex flex-wrap items-center justify-center gap-2.5">
            @if (! $paginator->onFirstPage())
                {{-- `rel="prev"`/`rel="next"` — подсказка роботу о порядке
                     страниц выдачи. Пагинация в `noindex` не уводится:
                     страница 2 обязана индексироваться. --}}
                <a
                    href="{{ $paginator->previousPageUrl() }}"
                    rel="prev"
                    aria-label="Предыдущая страница"
                    class="flex size-10 items-center justify-center rounded-field border border-line-strong text-sm text-ink-muted transition hover:border-accent/50 hover:text-accent"
                >←</a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    {{-- Разрыв в ряду страниц. `aria-hidden`: три точки
                         не несут смысла на слух. --}}
                    <span class="flex size-10 items-center justify-center text-sm text-ink-faint" aria-hidden="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span
                                aria-current="page"
                                class="flex size-10 items-center justify-center rounded-field bg-accent-solid text-sm font-semibold text-on-accent"
                            >{{ $page }}</span>
                        @else
                            {{-- Подпись ссылки — «Страница 2», а не голая цифра:
                                 в списке ссылок скринридера «2» ни о чём
                                 не говорит. --}}
                            <a
                                href="{{ $url }}"
                                aria-label="Страница {{ $page }}"
                                class="flex size-10 items-center justify-center rounded-field border border-line-strong text-sm text-ink-muted transition hover:border-accent/50 hover:text-accent"
                            >{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a
                    href="{{ $paginator->nextPageUrl() }}"
                    rel="next"
                    aria-label="Следующая страница"
                    class="flex size-10 items-center justify-center rounded-field border border-line-strong text-sm text-ink-muted transition hover:border-accent/50 hover:text-accent"
                >→</a>
            @endif
        </div>
    </nav>
@endif
