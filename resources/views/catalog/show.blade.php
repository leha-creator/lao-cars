{{--
    Карточка автомобиля — функциональный шаблон без дизайна.

    Вёрстка по макету, мета-теги и микроразметка Product/Vehicle приходят
    вехой 4.3. Здесь проверяется другое: что привязка по slug работает,
    сетка характеристик собирается с заголовками групп, а блок похожих
    не пустеет на автомобиле без цены.

    Форма заявки на конкретный автомобиль — компонент `x-lead-form`
    вехи 3.7, тоже без дизайна.

    Пока вёрстки нет, каталог не должен попадать в публичный релиз.
--}}
@extends('layouts.app')

@section('title', $car->brand->name.' '.$car->model.', '.$car->year.' — '.config('app.name'))
@section('description', 'Купить '.$car->brand->name.' '.$car->model.' '.$car->year.' года в ЛАО КАРС.')

@section('content')
    <main class="mx-auto max-w-5xl px-6 py-10">
        <p class="text-sm">
            <a href="{{ route('catalog.index') }}">← Каталог</a>
        </p>

        <h1 class="text-2xl font-semibold">
            {{ $car->brand->name }} {{ $car->model }}, {{ $car->year }}
        </h1>

        <p class="text-xl">
            @if ($car->price !== null)
                {{ number_format((int) $car->price, 0, ',', ' ') }} ₽
            @else
                Цена по запросу
            @endif
        </p>

        <p class="text-sm text-gray-500">{{ $car->status->label() }}</p>

        <h2 class="mt-6 font-medium">Характеристики</h2>

        <dl>
            <dt>Год выпуска</dt>
            <dd>{{ $car->year }}</dd>

            <dt>Двигатель</dt>
            <dd>
                {{ $car->engine_type->label() }}
                @if ($car->engine_type->hasVolume() && $car->engine_volume !== null)
                    , {{ $car->engine_volume }} л
                @endif
                @if ($car->engine_power !== null)
                    , {{ $car->engine_power }} л.с.
                @endif
            </dd>

            <dt>Привод</dt>
            <dd>{{ $car->drive->label() }}</dd>

            <dt>Пробег</dt>
            {{-- null — не «ноль километров», а «новый»: у авто под заказ
                 пробега нет вовсе (комментарий миграции вехи 3.2). --}}
            <dd>{{ $car->mileage !== null ? number_format($car->mileage, 0, ',', ' ').' км' : 'Новый' }}</dd>
        </dl>

        @foreach ($car->cardAttributes() as $group => $values)
            {{-- Безымянная группа выводится блоком без заголовка. Ключ — '',
                 а не null: PHP приводит null к пустой строке (PHPDoc
                 Car::cardAttributes()). --}}
            @if ($group !== '')
                <h3 class="mt-4 font-medium">{{ $group }}</h3>
            @endif

            <dl>
                @foreach ($values as $value)
                    <dt>{{ $value->attribute->label }}</dt>
                    <dd>{{ $value->formatted }}</dd>
                @endforeach
            </dl>
        @endforeach

        @if ($car->history !== null && $car->history !== '')
            <h2 class="mt-6 font-medium">История</h2>
            <p>{{ $car->history }}</p>
        @endif

        @if ($car->description !== null && $car->description !== '')
            <h2 class="mt-6 font-medium">Описание</h2>
            <p>{{ $car->description }}</p>
        @endif

        @if ($car->photos->isNotEmpty())
            <h2 class="mt-6 font-medium">Фотографии</h2>

            <div class="grid gap-4 sm:grid-cols-3">
                @foreach ($car->photos as $photo)
                    <img src="{{ Storage::disk($photo->disk)->url($photo->path) }}"
                         alt="{{ $photo->alt }}" class="max-w-full">
                @endforeach
            </div>
        @endif

        {{-- Источник заявки — сам автомобиль: в списке лидов менеджер
             увидит «Авто: <марка> <модель>», а не «Общая форма». --}}
        <x-lead-form :source="$car" title="Оставить заявку на этот автомобиль" />

        @if ($similar->isNotEmpty())
            <h2 class="mt-8 font-medium">Похожие автомобили</h2>

            <div class="grid gap-6 sm:grid-cols-3">
                @foreach ($similar as $item)
                    {{-- Карточка вложена в раздел со своим h2, поэтому её
                         заголовок — h3. --}}
                    <x-car-card :car="$item" heading="h3" />
                @endforeach
            </div>
        @endif
    </main>
@endsection
