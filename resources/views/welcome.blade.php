{{--
    Временная заглушка главной страницы.

    Стартовая страница скелета Laravel убрана: она тянула шрифт Instrument Sans
    с fonts.bunny.net через директиву @fonts и держала копию Tailwind инлайном.
    Реальная главная — hero, подборка моделей, блок преимуществ, форма быстрой
    заявки — собирается в вехах 3.3 и 4.x.

    Страница держится на базовом layout осознанно: так каркас, сборка Vite и
    Alpine проверяются на живой странице, а не только в тестах.
--}}
@extends('layouts.app')

@section('title', config('app.name').' — импорт автомобилей и автосервис')
@section('description', 'Импорт автомобилей из Китая и Европы, техобслуживание, ремонт и детейлинг.')

@section('content')
    <main class="mx-auto flex min-h-screen max-w-2xl flex-col justify-center gap-4 px-6">
        <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">
            {{ config('app.name') }}
        </h1>

        <p class="text-gray-600">
            Каркас проекта собран. Публичная часть появится в вехах 3.3 и 4.x.
        </p>

        <div x-data="{ ready: true }" class="text-sm text-gray-500">
            <span x-show="ready">Alpine подключён.</span>
        </div>

        <p>
            <a href="/admin" class="text-sm font-medium text-amber-700 underline underline-offset-4">
                Админ-панель
            </a>
        </p>

        {{-- Форма без источника: живая точка для сценария «общая форма»,
             в списке лидов такая заявка помечается «Общая форма».
             Формы автосервиса и подбора запчастей монтируются вехами 4.4
             и 4.5 — компонент оба сценария уже поддерживает. --}}
        <x-lead-form title="Обратный звонок" submit="Жду звонка" />
    </main>
@endsection
