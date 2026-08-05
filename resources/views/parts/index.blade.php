{{--
    Запчасти — страница-заглушка вехи 4.1.

    Здесь заголовок, вводный текст, условия поставки из настроек и форма
    подбора на каркасе. Категории запчастей приходят вехой 4.4.

    Форма — тот же `x-lead-form`, но с флагом `parts`: он добавляет марку,
    модель и VIN, по которым `Lead::isPartsRequest()` отличает заявку
    на подбор от остальных. Флаг легко потерять при правке шаблона,
    поэтому на него стоит отдельный тест.

    До вехи 4.4 в публичный релиз страница не идёт.
--}}
@extends('layouts.app')

@section('title', $title.' — '.config('app.name'))
@section('description', 'Подбор оригинальных запчастей и проверенных аналогов по марке, модели и VIN.')

@section('content')
    <x-page-heading eyebrow="Запчасти" :title="$title" :intro="$intro" />

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

    <x-lead-form :parts="true" title="Подобрать запчасть" submit="Отправить запрос" />
@endsection
