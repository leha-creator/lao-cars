{{--
    Контакты — страница-заглушка вехи 4.1.

    Здесь контакты из настроек и форма обратной связи на каркасе. Полная
    вёрстка приходит вехой 4.5: макета для этой страницы нет, она собирается
    по UI Kit.

    Карты в MVP нет осознанно — Яндекс.Карты отложены за его пределы
    (`DESCRIPTION.md`), до подключения адрес заменяет карту.

    До вехи 4.5 в публичный релиз страница не идёт.
--}}
@extends('layouts.app')

@section('title', 'Контакты — '.config('app.name'))
@section('description', 'Адрес, телефон, почта и мессенджеры ЛАО КАРС. Форма обратной связи — ответим в рабочее время.')

@section('content')
    <x-page-heading eyebrow="Контакты" title="Контакты" intro="Позвоните, напишите в мессенджер или оставьте заявку — перезвоним в рабочее время." />

    <section class="px-5 pt-12 lg:px-8">
        <div class="mx-auto grid max-w-page gap-6 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                'Адрес' => $contacts['contacts.address'] ?? null,
                'Телефон' => $contacts['contacts.phone'] ?? null,
                'E-mail' => $contacts['contacts.email'] ?? null,
                'Часы работы' => $contacts['contacts.work_hours'] ?? null,
            ] as $label => $value)
                @if ($value !== null && $value !== '')
                    <div class="rounded-card border border-white/10 bg-surface p-6">
                        <div class="mb-2 text-[13px] tracking-[0.1em] text-ink-muted uppercase">{{ $label }}</div>
                        <div class="text-[15px]">{{ $value }}</div>
                    </div>
                @endif
            @endforeach
        </div>

        @if ($socials !== [])
            <div class="mx-auto mt-8 flex max-w-page flex-wrap gap-3">
                @foreach ($socials as $social)
                    <a
                        href="{{ $social['url'] }}"
                        target="_blank"
                        rel="noopener"
                        class="rounded-full border border-white/20 px-5 py-2.5 text-sm transition hover:border-accent/50 hover:text-accent"
                    >{{ $social['label'] }}</a>
                @endforeach
            </div>
        @endif
    </section>

    <x-lead-form title="Написать нам" submit="Отправить" />
@endsection
