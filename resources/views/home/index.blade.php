{{--
    Главная — заглушка на каркасе вехи 4.1.

    Содержимое собирает веха 4.2: бегущая строка в `@section('above-header')`,
    хиро с фоновым изображением и прозрачной шапкой (`$headerOverlay = true`),
    промо-баннер, подборка авто с отметкой «на главной», блок преимуществ
    и блок двух направлений. Все данные для них уже лежат в настройках
    (`home.ticker`, `home.promo`, `home.advantages`) и в каталоге.

    До вехи 4.2 главная в публичный релиз не идёт.
--}}
@extends('layouts.app')

@section('title', config('app.name').' — импорт автомобилей и автосервис')
@section('description', 'Импорт автомобилей из Китая и Европы под ключ, собственный автосервис, детейлинг и подбор запчастей в Москве.')

@section('content')
    <section class="px-5 py-20 lg:px-8 lg:py-28">
        <div class="mx-auto max-w-page">
            <div class="mb-5 text-[13px] tracking-[0.22em] text-accent uppercase">Импорт · Сервис · Экспертиза</div>

            <h1 class="mb-6 max-w-4xl font-display text-4xl leading-[1.14] font-semibold text-pretty lg:text-[56px]">
                Автомобили из <span class="text-accent">Китая и Европы</span>. Под ключ.
            </h1>

            <p class="mb-10 max-w-xl text-lg leading-relaxed text-ink-muted">
                Подбор, растаможка и доставка автомобиля любой сложности — и полный автосервис
                для вашего парка. Один партнёр от заказа до обслуживания.
            </p>

            <div class="flex flex-wrap gap-4">
                <a
                    href="{{ route('catalog.index') }}"
                    class="rounded-full bg-accent px-9 py-4.5 text-[15px] font-semibold tracking-[0.02em] text-page transition hover:-translate-y-0.5 hover:bg-accent-hover"
                >Подобрать авто</a>

                <a
                    href="#lead-form"
                    class="rounded-full border border-white/35 px-9 py-4.5 text-[15px] font-semibold tracking-[0.02em] transition hover:-translate-y-0.5 hover:border-white/70"
                >Оставить заявку</a>
            </div>
        </div>
    </section>

    {{-- Форма без источника: живая точка для сценария «общая форма»,
         в списке лидов такая заявка помечается «Общая форма». --}}
    <x-lead-form title="Оставить заявку" submit="Отправить заявку" />
@endsection
