@use(App\Enums\CarStatus)

@props([
    'car',
    // В блоке похожих карточка вложена в раздел со своим h2, и её заголовок
    // обязан быть h3: иерархия заголовков — не оформление, по ней ходят
    // скринридеры и по ней страницу разбирает поисковик.
    'heading' => 'h2',
])

@php
    // Строка характеристик собирается из заполненных полей: пустое значение
    // выпадает целиком, а не оставляет висящий разделитель «·».
    //
    // Пробег null — это не «ноль километров», а автомобиль под заказ,
    // у которого пробега нет вовсе (комментарий миграции вехи 3.2).
    $specs = array_values(array_filter([
        $car->engine_type?->label(),
        // Слово «привод» дописывает карточка, а не enum: подпись «Полный»
        // полна в форме админки, где поле уже названо «Привод», и теряет
        // смысл в свободной строке «Электро · Полный · 67 769 км».
        $car->drive === null ? null : $car->drive->label().' привод',
        $car->mileage !== null
            ? number_format($car->mileage, 0, ',', ' ').' км'
            : 'Новый',
    ]));
@endphp

{{--
    Карточка автомобиля по макету (`Home.dc.html`, строки 97–109;
    в `Catalog.dc.html` она же). Один компонент на список каталога
    и на блок похожих — до вехи 4.1 это были две копии одной разметки,
    и веха 4.3 сделала бы третью.
--}}
<a
    href="{{ route('catalog.show', $car) }}"
    class="group block overflow-hidden rounded-card border border-line bg-surface transition duration-250 hover:-translate-y-1.5 hover:border-accent/35 hover:shadow-[0_20px_40px_rgba(0,0,0,0.35)]"
>
    {{-- Соотношение сторон держит контейнер, поэтому карточки не прыгают,
         пока грузятся фотографии.

         `width`/`height` здесь НЕ ставятся, хотя с вехи 4.14 размеры
         в таблице есть: записаны размеры ОРИГИНАЛА, а `src` тут —
         превью другой ширины. Пропорция совпадает, числа нет, и объявлять
         чужой размер собственным — та же ложь в разметке, из-за которой
         в проекте запрещён `srcset` без настоящих ширин. Место и так
         зарезервировано контейнером, так что терять нечего. --}}
    <div class="aspect-16/9 bg-photo">
        @if ($car->mainPhoto !== null)
            <img
                src="{{ $car->mainPhoto->thumb_url }}"
                alt="{{ $car->mainPhoto->alt }}"
                loading="lazy"
                class="size-full object-cover"
            >
        @endif
    </div>

    <div class="p-6 lg:p-7">
        <div class="mb-2.5 flex items-start justify-between gap-3">
            <{{ $heading }} class="text-[17px] font-semibold lg:text-[19px]">
                {{ $car->brand->name }} {{ $car->model }}, {{ $car->year }}
            </{{ $heading }}>

            {{-- Статус-тег: pill с обводкой в цвет статуса, без заливки
                 (UI Kit §04). Состояний три, а не два.

                 Акцентный — только «в наличии»: статус, ради которого
                 человек и открывает каталог, и единственный акцентный
                 цвет на сайт (UI Kit §02). «В пути» второго акцента
                 не получает — он выделен яркостью обводки и текста
                 в пределах той же нейтральной шкалы. Свести его обратно
                 к приглушённому варианту нельзя: он станет визуально
                 неотличим от «Под заказ», то есть вкладка появится,
                 а карточка её не подтвердит. --}}
            <span
                @class([
                    'shrink-0 rounded-full border px-2.5 py-1 text-[11px] tracking-[0.08em] uppercase',
                    'border-accent/40 text-accent' => $car->status === CarStatus::InStock,
                    'border-line-loud text-ink' => $car->status === CarStatus::InTransit,
                    'border-line-strong text-ink-muted' => ! in_array($car->status, [CarStatus::InStock, CarStatus::InTransit], true),
                ])
            >{{ $car->status->label() }}</span>
        </div>

        @if ($specs !== [])
            <div class="mb-4 text-sm text-ink-muted">{{ implode(' · ', $specs) }}</div>
        @endif

        <div class="font-display text-xl font-semibold text-accent lg:text-[22px]">
            @if ($car->price !== null)
                {{ number_format((int) $car->price, 0, ',', ' ') }} ₽
            @else
                Цена по запросу
            @endif
        </div>
    </div>
</a>
