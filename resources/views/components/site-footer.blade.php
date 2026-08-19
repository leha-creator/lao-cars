{{--
    Подвал сайта по макету `Home.dc.html` (строки 219–260): пять колонок —
    логотип с адресом, навигация, контакты, соцсети и блок гарантии
    в карточке; ниже — копирайт и политика конфиденциальности.

    На мобильных колонки складываются в одну: пять колонок на 390px
    превращаются в пять полосок по одному слову.
--}}
<footer class="border-t border-line px-5 pt-14 pb-10 lg:px-8 lg:pt-18">
    <div class="mx-auto max-w-page">
        <div class="mb-14 grid gap-10 sm:grid-cols-2 lg:grid-cols-[1.3fr_1fr_1fr_1fr_1.1fr] lg:items-start">
            <div>
                <img
                    src="{{ Vite::asset('resources/images/logo-white.png') }}"
                    alt="ЛАО КАРС"
                    width="440"
                    height="128"
                    class="mb-5 h-[26px] w-auto"
                >

                <p class="max-w-[260px] text-sm leading-relaxed text-ink-muted">
                    Импорт автомобилей и полный цикл автосервиса.
                    @if ($contact('address') !== null)
                        {{ $contact('address') }}.
                    @endif
                </p>
            </div>

            <div>
                <div class="mb-5 text-[13px] tracking-[0.1em] text-ink-muted uppercase">Навигация</div>

                <nav class="flex flex-col gap-3 text-sm">
                    @foreach ($items() as $item)
                        <a href="{{ $item['url'] }}" class="transition-colors hover:text-accent">{{ $item['label'] }}</a>
                    @endforeach
                </nav>
            </div>

            <div>
                <div class="mb-5 text-[13px] tracking-[0.1em] text-ink-muted uppercase">Контакты</div>

                <div class="flex flex-col gap-3 text-sm text-ink-muted">
                    @if ($contact('phone') !== null)
                        <a href="tel:{{ $phoneHref() }}" class="transition-colors hover:text-accent">{{ $contact('phone') }}</a>
                    @endif

                    @if ($contact('email') !== null)
                        <a href="mailto:{{ $contact('email') }}" class="transition-colors hover:text-accent">{{ $contact('email') }}</a>
                    @endif

                    {{-- Часы собираются из структуры расписания (веха 4.14),
                         а не берутся строкой из настройки. Пустое расписание
                         не выводится вовсе — как незаполненная соцсеть:
                         пустая строка в столбце контактов читается как
                         поломка вёрстки, а не как «часы не заданы». --}}
                    @if ($schedule()->label() !== null)
                        <div>{{ $schedule()->label() }}</div>

                        @if ($schedule()->note() !== null)
                            <div class="text-[13px] text-ink-muted/70">{{ $schedule()->note() }}</div>
                        @endif
                    @endif
                </div>
            </div>

            <div>
                <div class="mb-5 text-[13px] tracking-[0.1em] text-ink-muted uppercase">Соцсети</div>

                {{-- Незаполненная соцсеть не выводится вовсе: ссылка на пустой
                     адрес выглядит рабочей и ведёт на текущую страницу. --}}
                <div class="flex flex-col gap-3 text-sm">
                    @forelse ($socials as $social)
                        <a
                            href="{{ $social['url'] }}"
                            class="transition-colors hover:text-accent"
                            target="_blank"
                            rel="noopener"
                        >{{ $social['label'] }}</a>
                    @empty
                        <span class="text-ink-faint">—</span>
                    @endforelse
                </div>
            </div>

            @if ($guaranteeValue('title') !== null)
                <div class="rounded-card border border-line bg-surface p-5.5">
                    <div class="mb-2 text-sm font-semibold">{{ $guaranteeValue('title') }}</div>

                    @if ($guaranteeValue('text') !== null)
                        <div class="mb-3.5 text-[13px] leading-relaxed text-ink-muted">{{ $guaranteeValue('text') }}</div>
                    @endif

                    @if ($guaranteeValue('link_text') !== null && $guaranteeValue('link_url') !== null)
                        <a href="{{ $guaranteeValue('link_url') }}" class="text-[13px] font-semibold text-accent">
                            {{ $guaranteeValue('link_text') }} →
                        </a>
                    @endif
                </div>
            @endif
        </div>

        <div class="flex flex-col gap-3 border-t border-line pt-8 text-[13px] text-ink-faint sm:flex-row sm:justify-between">
            {{-- Год считается, а не пишется константой: захардкоженный
                 устаревает в новогоднюю ночь и никем не замечается. --}}
            <div>© {{ date('Y') }} ЛАО КАРС. Все права защищены.</div>
            <div>Политика конфиденциальности</div>
        </div>
    </div>
</footer>
