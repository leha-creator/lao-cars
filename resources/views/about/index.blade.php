{{--
    О компании (веха 4.5), собрана по экрану About макета v1
    (`assets/Макет сайта «ЛАО КАРС»/About.dc.html`).

    Данные готовит `App\Services\AboutPageContent`: тексты из группы настроек
    `about_page.*`, команда и отзывы — из моделей `Employee` и `Review`.
    Blade в базу не ходит.

    Из макета взята РАСКЛАДКА и порядок блоков (история → миссия → команда →
    отзывы), но не цвета: макет рисовался под тёмную тему, светлых значений
    в нём нет вовсе. Палитра берётся из токенов, страница светлая целиком —
    требование `docs/design-system.md` («„Шоу-рум“ и „О компании“ верстаются
    сразу в новой палитре») записано там до этой вехи именно затем, чтобы
    страницу не сделали тёмной по образцу соседних шаблонов.

    Три вещи из макета сюда НЕ переносятся, и каждая не мелочь:

    - **фотографический хиро страницы.** В макете H1 лежит на снимке команды
      или шоу-рума с градиентной маской. Снимка нет: тот, что пришёл вехой 4.5
      в полосу доверия на главной, — чужой дилерский центр и подлежит замене
      (`docs/homepage.md`). Ставить его вторым экраном значило бы повторить
      ту же неправду крупнее. Вместо хиро — `x-page-heading`, тот же
      компонент, что у остальных внутренних страниц, и рецепт
      `docs/design-system.md` описывает ровно это;
    - **состав меню шапки** («Каталог · Услуги · Блог · Контакты»). Навигация
      живёт в `App\Support\SiteMenu`, блог из проекта снят целиком. Макет v1
      рисовался до этих решений, и его структура просачивается по частям.
      Сторож — `LayoutTest`;
    - **H1 «История, миссия и люди „ЛАО КАРС“»** и заголовки блоков как
      константы. H1 приходит из настройки `about_page.intro_title` по тому же
      правилу, что и на `/services` и `/parts`: заменить настройку константой
      из макета значит отобрать у заказчика поле, которое ему уже отдали
      на двух других страницах. Сторож — `AboutPageTest`.

    История в макете — два абзаца прозой, здесь — репитер настроек по годам.
    Расхождение осознанное: прозу заказчик правит целиком и наощупь, а вехи
    добавляет по одной, и форма репитера уже есть у трёх блоков главной.

    Блок без данных не рендерится вовсе — правило проекта. Исключение ровно
    одно и на уровень ниже: пустая КАРТОЧКА сотрудника не рендерится, а
    карточка без ФОТО рендерится с заглушкой. Это обещано сотруднику
    в справке (`resources/help/team-page.md`), и без заглушки статья
    расходится с поведением в момент открытия страницы.
--}}
@extends('layouts.app')

@section('title', $title.' — '.config('app.name'))
@section('description', 'История, миссия и команда ЛАО КАРС: импорт автомобилей из Китая и Европы, собственный автосервис и детейлинг в Москве.')

@section('content')
    {{-- Страница светлая целиком, как остальные внутренние (веха 4.11).
         `theme-light` вешается на КАЖДУЮ секцию и на сам `x-page-heading`
         (он принимает атрибуты с места вызова), а не на обёртку вокруг них:
         обёртка получила бы боковые поля страницы и оставила бы по краям
         тёмные полосы. Секцию заявки внизу красит сам компонент
         по своей раскладке и `theme-light` не принимает.

         Чередование оттенков сверху вниз: шапка и история на `page`,
         миссия на `page-alt`, команда снова на `page`, отзывы на `page-alt`.
         Больше двух оттенков на экране правило дизайн-системы запрещает —
         здесь их ровно два. --}}
    <x-page-heading eyebrow="О компании" :title="$title" :intro="$intro" class="theme-light" />

    @if ($history !== [])
        <section class="theme-light px-5 py-16 lg:px-8 lg:py-24">
            <div class="mx-auto max-w-page">
                <div class="mb-4 text-[13px] tracking-[0.2em] text-accent uppercase">Наша история</div>

                <h2 class="mb-12 font-display text-3xl leading-[1.15] font-semibold text-pretty lg:mb-14 lg:text-[34px]">
                    От первых поставок до полного цикла владения
                </h2>

                {{-- Сетка, а не вертикальная лента с линией: вех четыре,
                     и линия на четыре точки — это украшение, которое ломается
                     на пяти и на трёх. Год стоит акцентом над заголовком,
                     как номер в этапах покупки на главной, — тот же приём
                     на тех же данных. --}}
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($history as $item)
                        <article class="rounded-card border border-line bg-surface p-7">
                            {{-- Год необязателен, в отличие от заголовка:
                                 веха без даты остаётся вехой, а карточка
                                 из одного года — нет. Сервис отбрасывает
                                 элементы без заголовка, поэтому здесь
                                 проверка только на год. --}}
                            @if ($item['year'] !== null)
                                <div class="mb-3 font-display text-2xl font-semibold text-accent">{{ $item['year'] }}</div>
                            @endif

                            <h3 class="mb-2 text-[17px] font-semibold">{{ $item['title'] }}</h3>

                            @if ($item['text'] !== null)
                                <p class="text-sm leading-[1.7] text-ink-muted">{{ $item['text'] }}</p>
                            @endif
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($mission !== null)
        <section class="theme-light bg-page-alt px-5 py-16 lg:px-8 lg:py-24">
            <div class="mx-auto max-w-page">
                <div class="mb-4 text-[13px] tracking-[0.2em] text-accent uppercase">Миссия</div>

                {{-- Заголовок и текст выводятся порознь: сервис убирает блок,
                     только когда пусты ОБА, и одиночный текст без заголовка
                     здесь штатный случай, а не поломка. --}}
                @if ($mission['title'] !== null)
                    <h2 class="max-w-3xl font-display text-3xl leading-[1.15] font-semibold text-pretty lg:text-[34px]">
                        {{ $mission['title'] }}
                    </h2>
                @endif

                @if ($mission['text'] !== null)
                    <p @class([
                        'max-w-3xl text-[15px] leading-[1.8] text-ink-muted',
                        'mt-6' => $mission['title'] !== null,
                    ])>{{ $mission['text'] }}</p>
                @endif
            </div>
        </section>
    @endif

    {{--
        Команда — первый и единственный потребитель модели `Employee`.
        Пустая выдача убирает секцию целиком, вместе с заголовком: заголовок
        «Команда» над пустой сеткой читается как поломка, а не как «пока
        никого не завели». WARN про это пишет сервис.
    --}}
    @if ($employees->isNotEmpty())
        <section class="theme-light px-5 py-16 lg:px-8 lg:py-24">
            <div class="mx-auto max-w-page">
                <div class="mb-4 text-[13px] tracking-[0.2em] text-accent uppercase">Команда</div>

                <h2 class="mb-12 font-display text-3xl leading-[1.15] font-semibold text-pretty lg:mb-14 lg:text-[34px]">
                    Специалисты, которые ведут <span class="text-accent">вашу сделку</span>
                </h2>

                <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($employees as $employee)
                        <article>
                            {{--
                                Карточка без фотографии показывает ЗАГЛУШКУ,
                                а не схлопывается до текста, и это не вкус.
                                Справка сотруднику обещает дословно:
                                «Карточка без фотографии выглядит нормально —
                                на её месте показывается заглушка»
                                (`resources/help/team-page.md`). Приём
                                карточки отзыва на главной (нет фото — нет
                                тега `<img>`) скопировать сюда нельзя:
                                он превратил бы статью справки в ложь
                                в момент открытия страницы, а сетка из
                                четырёх карточек разной высоты выглядела бы
                                сломанной.

                                Заглушка и снимок делят ОДНУ геометрию —
                                обёртка задаёт высоту и скругление, а не
                                каждый из двух по отдельности: иначе они
                                разъедутся при первой правке одного из них.
                                Токен `bg-photo` тот же, что под фото отзыва,
                                и он же стоит подложкой в макете
                                (`oklch(0.87 0.01 60)`).
                            --}}
                            <div class="mb-5 h-70 overflow-hidden rounded-card bg-photo">
                                @if ($employee->photo_url !== null)
                                    {{-- Снимок декоративный: имя и должность
                                         стоят под ним текстом, и `alt`
                                         с именем прочитал бы их дважды. --}}
                                    <img
                                        src="{{ $employee->photo_url }}"
                                        loading="lazy"
                                        alt=""
                                        aria-hidden="true"
                                        class="size-full object-cover"
                                    >
                                @else
                                    {{-- Первая буква имени, а не значок:
                                         пустой прямоугольник читается как
                                         не загрузившаяся картинка, то есть
                                         как поломка. Буква сообщает, что
                                         фотографии нет, — и это ровно то,
                                         что обещает справка. Скринридеру
                                         она не нужна: имя стоит ниже
                                         текстом, поэтому `aria-hidden`. --}}
                                    <div
                                        aria-hidden="true"
                                        class="flex size-full items-center justify-center font-display text-4xl font-semibold text-ink-faint"
                                    >{{ mb_substr($employee->name, 0, 1) }}</div>
                                @endif
                            </div>

                            <div class="text-[17px] font-semibold">{{ $employee->name }}</div>

                            {{-- Без проверки на пустоту, в отличие от `bio`:
                                 колонка `position` объявлена NOT NULL,
                                 и поле обязательно в форме админки. --}}
                            <div class="mt-1 text-[13px] text-ink-faint">{{ $employee->position }}</div>

                            @if ($employee->bio !== null)
                                <p class="mt-3 text-sm leading-[1.7] text-ink-muted">{{ $employee->bio }}</p>
                            @endif
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{--
        Отзывы — ВСЕ опубликованные, без лимита главной: `config/home.php`
        резервирует полный список за этой страницей дословно. Разметка
        карточки повторяет главную намеренно — те же данные и та же модель,
        и вторая вёрстка одного и того же разъехалась бы с первой.
    --}}
    @if ($reviews->isNotEmpty())
        <section class="theme-light bg-page-alt px-5 py-16 lg:px-8 lg:py-24">
            <div class="mx-auto max-w-page">
                <div class="mb-4 text-[13px] tracking-[0.2em] text-accent uppercase">Отзывы</div>

                <h2 class="mb-12 font-display text-3xl leading-[1.15] font-semibold text-pretty lg:mb-14 lg:text-[34px]">
                    Что говорят <span class="text-accent">клиенты</span>
                </h2>

                <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($reviews as $review)
                        <article class="flex flex-col gap-4 rounded-card border border-line bg-surface p-7">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    {{-- Фото необязательно: `media_id`
                                         объявлен `nullOnDelete()`, и
                                         удалённое изображение оставляет
                                         отзыв без фото, а не роняет страницу.
                                         Заглушки здесь нет намеренно, в
                                         отличие от карточки сотрудника:
                                         справка обещает её только команде,
                                         а кружок с буквой рядом с именем
                                         читался бы как аватар пользователя
                                         сайта, которого у нас нет. --}}
                                    @if ($review->photo_url !== null)
                                        <img
                                            src="{{ $review->photo_url }}"
                                            width="44"
                                            height="44"
                                            loading="lazy"
                                            alt=""
                                            aria-hidden="true"
                                            class="size-11 shrink-0 rounded-full bg-photo object-cover"
                                        >
                                    @endif

                                    <div class="min-w-0">
                                        <div class="text-[15px] font-semibold">{{ $review->author_name }}</div>

                                        @if ($review->author_context !== null)
                                            <div class="text-[13px] text-ink-faint">{{ $review->author_context }}</div>
                                        @endif
                                    </div>
                                </div>

                                {{-- Ряд символов скринридер прочитал бы
                                     как мусор («звезда звезда звезда…»),
                                     поэтому оценка объявлена целиком:
                                     `role="img"` плюс `aria-label`
                                     заменяют содержимое одной фразой.
                                     Приём тот же, что на главной. --}}
                                @if ($review->rating !== null)
                                    <div
                                        role="img"
                                        aria-label="Оценка: {{ $review->rating }} из 5"
                                        class="shrink-0 text-sm tracking-[0.08em] text-accent"
                                    >{{ str_repeat('★', $review->rating).str_repeat('☆', 5 - $review->rating) }}</div>
                                @endif
                            </div>

                            <p class="text-[15px] leading-[1.7]">{{ $review->body }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{--
        Секция заявки обязательна, и это не вопрос вкуса и не «за компанию
        с остальными страницами». Кнопка «Оставить заявку» в шапке ведёт
        на `#lead-form` НА КАЖДОЙ странице сайта (`site-header.blade.php` —
        и десктоп, и мобильное меню), а якорь появляется только вместе
        с `x-lead-section`. Страница без секции даёт мёртвую кнопку в шапке:
        без ошибок, без записи в лог, заметно только кликом.

        Заголовок отличается от умолчания: «Познакомиться» вместо «Оставить
        заявку» — на странице, где человек только что прочитал про команду
        и историю, приглашение звучит уместнее, чем требование. Формулировка
        остаётся открытым вопросом к заказчику; сам вопрос «нужна ли форма»
        снят выше.
    --}}
    <x-lead-section title="Познакомиться" submit="Отправить заявку" />
@endsection
