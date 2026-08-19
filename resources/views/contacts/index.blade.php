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
    {{-- Микроразметка организации (веха 4.14). Печатается через `@json`
         с `JSON_HEX_TAG` по правилу проекта: сюда попадают адрес, телефон
         и почта, введённые администратором, то есть данные из базы в HTML
         без экранирования Blade. Обоснование флагов разобрано подробно
         в `catalog/show.blade.php`, где стоит тот же тег. --}}
    <script type="application/ld+json">@json($structuredData, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>

    {{-- Страница светлая целиком (веха 4.11). Вёрстка при этом не трогается:
         контакты остаются заглушкой вехи 4.1 до вехи 4.5
         (`showroom-and-about-page.md`), и доведён здесь только фон. --}}
    <x-page-heading eyebrow="Контакты" title="Контакты" intro="Позвоните, напишите в мессенджер или оставьте заявку — перезвоним в рабочее время." class="theme-light" />

    <section class="theme-light px-5 pt-12 lg:px-8">
        <div class="mx-auto grid max-w-page gap-6 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                'Адрес' => $contacts['contacts.address'] ?? null,
                'Телефон' => $contacts['contacts.phone'] ?? null,
                'E-mail' => $contacts['contacts.email'] ?? null,
                {{-- Та же собранная строка, что в подвале и в микроразметке:
                     источник один — настройка `contacts.schedule`. --}}
                'Часы работы' => $schedule->label(),
            ] as $label => $value)
                @if ($value !== null && $value !== '')
                    <div class="rounded-card border border-line bg-surface p-6">
                        <div class="mb-2 text-[13px] tracking-[0.1em] text-ink-muted uppercase">{{ $label }}</div>
                        <div class="text-[15px]">{{ $value }}</div>

                        @if ($label === 'Часы работы' && $schedule->note() !== null)
                            <div class="mt-1 text-[13px] text-ink-muted">{{ $schedule->note() }}</div>
                        @endif
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
                        class="rounded-full border border-line-strong px-5 py-2.5 text-sm transition hover:border-accent/50 hover:text-accent"
                    >{{ $social['label'] }}</a>
                @endforeach
            </div>
        @endif
    </section>

    <x-lead-section title="Написать нам" submit="Отправить" />
@endsection
