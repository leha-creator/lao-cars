{{--
    Запчасти (веха 4.4).

    Макета v2 для этой страницы нет — она верстается из готовых блоков
    UI Kit, а не переносится один в один, как страница автосервиса.

    Категории выводятся КАРТОЧКАМИ, а не прайсом: у всех позиций цена пустая
    («уточняем после запроса»), и пять одинаковых подписей «по запросу»
    в столбик — шум, а не информация. Цена показывается, только если
    заполнена, — и здесь нет фолбэка «по запросу» намеренно: на странице
    автосервиса он несёт смысл (позиция в прайсе, где у соседей суммы есть),
    а тут повторил бы вводный текст пять раз.

    Форма источник НЕ проставляет. Заявку на подбор отличают поля автомобиля
    (`Lead::isPartsRequest()` — марка и VIN), а категория без артикула
    менеджеру не добавляет ничего: он всё равно идёт к поставщику по VIN.
    Второй селект в форме, где уже три дополнительных поля, стоит дороже,
    чем даёт. Триггер пересмотра: просьба менеджеров разделять заявки
    по категориям запчастей.

    Флаг `:parts` — единственное, что отличает эту форму от остальных,
    и потерять его при правке шаблона проще всего, поэтому на него стоит
    отдельный тест.
--}}
@extends('layouts.app')

@section('title', $title.' — '.config('app.name'))
@section('description', 'Подбор оригинальных запчастей и проверенных аналогов по марке, модели и VIN.')

@section('content')
    <x-page-heading eyebrow="Запчасти" :title="$title" :intro="$intro" />

    {{-- Пустая категория убирает секцию целиком: заголовок над пустой сеткой
         читается как поломка. Причину при этом пишет WARN в лог
         (`PartsController`), а не молчание. --}}
    @if ($categories->isNotEmpty())
        <section class="px-5 pt-12 lg:px-8 lg:pt-16">
            <div class="mx-auto max-w-page">
                <h2 class="mb-8 font-display text-[26px] leading-[1.2] font-semibold lg:mb-10 lg:text-3xl">
                    Что подбираем
                </h2>

                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($categories as $category)
                        <div class="rounded-card border border-white/8 bg-surface p-7 transition duration-250 hover:-translate-y-1 hover:border-accent/30">
                            <h3 class="text-[17px] font-semibold">{{ $category->title }}</h3>

                            @if ($category->description !== null && $category->description !== '')
                                <p class="mt-3 text-sm leading-relaxed text-ink-muted">{{ $category->description }}</p>
                            @endif

                            @if ($category->hasPrice())
                                <div class="mt-4 font-display text-sm font-semibold text-accent">{{ $category->priceLabel() }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($deliveryTerms !== null && $deliveryTerms !== '')
        <section class="px-5 pt-12 lg:px-8">
            <div class="mx-auto max-w-page">
                <div class="max-w-2xl rounded-card border border-white/10 bg-surface p-6 lg:p-8">
                    <div class="mb-2 text-sm font-semibold">Условия поставки</div>
                    <p class="text-sm leading-relaxed text-ink-muted">{{ $deliveryTerms }}</p>
                </div>
            </div>
        </section>
    @endif

    <x-lead-section :parts="true" title="Подобрать запчасть" submit="Отправить запрос" />
@endsection
