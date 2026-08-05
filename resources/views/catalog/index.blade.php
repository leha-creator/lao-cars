{{--
    Список каталога — функциональный шаблон без дизайна.

    Вёрстка по макету приходит вехами 4.1 (UI Kit и токены) и 4.3
    (страницы каталога). Задача этого шаблона другая: фильтры должны
    проверяться на живой странице, а не только в тестах — что параметр
    доезжает до выборки, переживает пагинацию и возвращается в форму
    выбранным.

    Форма — обычная GET-форма без Alpine. Фильтры обязаны работать без JS,
    потому что именно так их видит поисковый робот; Alpine веха 4.3 кладёт
    сверху.

    Пока вёрстки нет, каталог не должен попадать в публичный релиз: на
    проде эта страница выглядит как сломанный сайт.
--}}
@extends('layouts.app')

@section('title', 'Каталог автомобилей — '.config('app.name'))
@section('description', 'Автомобили из Китая и Европы в наличии и под заказ: подбор по марке, типу двигателя, году и цене.')

@section('canonical')
    <link rel="canonical" href="{{ $canonical }}">
@endsection

@if ($filtered)
    {{-- Комбинация фильтров или неумолчательная сортировка — свой URL,
         которых сотни. В индекс идёт только чистый список и его страницы. --}}
    @section('robots', 'noindex,follow')
@endif

@section('content')
    @php
        $years = $options['years'];
        $yearRange = $years['min'] !== null && $years['max'] !== null
            ? range($years['max'], $years['min'])
            : [];
    @endphp

    <main class="mx-auto max-w-5xl px-6 py-10">
        <h1 class="text-2xl font-semibold">Каталог автомобилей</h1>

        <form method="GET" action="{{ route('catalog.index') }}" class="my-6 flex flex-wrap gap-4">
            <label>
                Марка
                <select name="brand">
                    <option value="">Все марки</option>
                    @foreach ($options['brands'] as $brand)
                        <option value="{{ $brand->slug }}" @selected($criteria->brand === $brand->slug)>
                            {{ $brand->name }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label>
                Двигатель
                <select name="engine">
                    <option value="">Любой</option>
                    @foreach ($options['engines'] as $engine)
                        <option value="{{ $engine->value }}" @selected($criteria->engine === $engine)>
                            {{ $engine->label() }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label>
                Год от
                <select name="year_from">
                    <option value="">Любой</option>
                    @foreach ($yearRange as $year)
                        <option value="{{ $year }}" @selected($criteria->yearFrom === $year)>{{ $year }}</option>
                    @endforeach
                </select>
            </label>

            <label>
                Год до
                <select name="year_to">
                    <option value="">Любой</option>
                    @foreach ($yearRange as $year)
                        <option value="{{ $year }}" @selected($criteria->yearTo === $year)>{{ $year }}</option>
                    @endforeach
                </select>
            </label>

            <label>
                Цена от
                <input type="number" name="price_from" min="0" value="{{ $criteria->priceFrom }}"
                       placeholder="{{ $options['prices']['min'] }}">
            </label>

            <label>
                Цена до
                <input type="number" name="price_to" min="0" value="{{ $criteria->priceTo }}"
                       placeholder="{{ $options['prices']['max'] }}">
            </label>

            <label>
                Наличие
                {{-- Вариантов два, а не три: «Продан» фильтром не выбирается,
                     проданные из выдачи убирает скоуп available(). --}}
                <select name="status">
                    <option value="">Все</option>
                    @foreach ([\App\Enums\CarStatus::InStock, \App\Enums\CarStatus::OnOrder] as $status)
                        <option value="{{ $status->value }}" @selected($criteria->status === $status)>
                            {{ $status->label() }}
                        </option>
                    @endforeach
                </select>
            </label>

            @foreach ($options['attributes'] as $item)
                @php($attribute = $item['attribute'])
                <label>
                    {{ $attribute->label }}
                    <select name="attr[{{ $attribute->key }}]">
                        <option value="">Любое</option>
                        @foreach ($item['values'] as $value)
                            <option value="{{ $value['value'] }}"
                                @selected(($criteria->attributes[$attribute->key] ?? null) === $value['value'])>
                                {{ $value['label'] }}
                            </option>
                        @endforeach
                    </select>
                </label>
            @endforeach

            <label>
                Сортировка
                <select name="sort">
                    @foreach (\App\Enums\CatalogSort::cases() as $sort)
                        <option value="{{ $sort->value }}" @selected($criteria->sort === $sort)>
                            {{ $sort->label() }}
                        </option>
                    @endforeach
                </select>
            </label>

            <button type="submit">Показать</button>

            @if ($filtered)
                <a href="{{ route('catalog.index') }}">Сбросить</a>
            @endif
        </form>

        <p class="text-sm text-gray-500">Найдено: {{ $cars->total() }}</p>

        <div class="my-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($cars as $car)
                <article class="border p-4">
                    <a href="{{ route('catalog.show', $car) }}">
                        @if ($car->mainPhoto !== null)
                            <img src="{{ Storage::disk('public')->url($car->mainPhoto->thumb_path ?? $car->mainPhoto->path) }}"
                                 alt="{{ $car->mainPhoto->alt }}" class="max-w-full">
                        @endif

                        <h2 class="font-medium">{{ $car->brand->name }} {{ $car->model }}, {{ $car->year }}</h2>
                    </a>

                    <p>
                        @if ($car->price !== null)
                            {{ number_format((int) $car->price, 0, ',', ' ') }} ₽
                        @else
                            Цена по запросу
                        @endif
                    </p>

                    <p class="text-sm text-gray-500">{{ $car->status->label() }}</p>
                </article>
            @empty
                <p>
                    Ничего не найдено.
                    <a href="{{ route('catalog.index') }}">Сбросить фильтры</a>
                </p>
            @endforelse
        </div>

        {{ $cars->links() }}
    </main>
@endsection
