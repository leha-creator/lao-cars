{{--
    Устройства, подписанные на push-уведомления (веха 4.7).

    Блок живёт в секции «Уведомления» страницы профиля, сразу под
    переключателем браузерных уведомлений. Место выбрано намеренно:
    включённый флаг без разрешения браузера не даёт НИЧЕГО, и объяснить
    это можно только рядом с самим флагом.

    Состояний у кнопки три, и различать их обязательно:

      · разрешение не запрашивалось — кнопка «Разрешить»;
      · разрешено и подписка есть — отметка «этот браузер подписан»;
      · запрещено — объяснение, что чинится это в настройках браузера,
        а не здесь: отозвать «Блокировать» страница не может, и кнопка,
        которая при нажатии ничего не делает, читается как поломка сайта.

    Четвёртое состояние — «разрешено, но подписки нет» — выглядит как
    «не подписан», и это не упрощение. Так бывает после переустановки
    браузера или очистки данных сайта, и показать его как «всё в порядке»
    значит оставить человека ждать уведомлений, которых не будет.

    Список запрашивает не шаблон, а страница (`EditProfile`): запросы
    к базе из Blade запрещены `ARCHITECTURE.md`.
--}}
<div
    x-data="{
        state: null,
        busy: false,
        async refresh() {
            /* Скрипт панели приезжает отдельной точкой входа Vite
               и на момент разбора разметки может ещё не исполниться. */
            this.state = await (window.laocarsPush?.pushState() ?? Promise.resolve('unsupported'));
        },
        async allow() {
            this.busy = true;

            try {
                this.state = await window.laocarsPush.subscribe();
                /* Подписка только что появилась в базе, а список отрисован
                   до неё: без обновления компонента человек видит пустой
                   список под надписью «этот браузер подписан». */
                $wire.$refresh();
            } finally {
                this.busy = false;
            }
        },
    }"
    x-init="refresh()"
    class="fi-fo-field-wrp"
>
    <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
        <p class="text-sm font-medium text-gray-950 dark:text-white">Устройства</p>

        @if ($devices->isEmpty())
            {{-- Честная фраза вместо пустой таблицы: пустая таблица под
                 заголовком «Устройства» читается как поломка, а фраза
                 объясняет, что делать дальше. --}}
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                Ни одного устройства не подписано.
            </p>
        @else
            <ul class="mt-3 divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($devices as $device)
                    <li class="flex items-center justify-between gap-4 py-2.5">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-gray-950 dark:text-white">
                                {{ $device->deviceLabel() }}
                            </p>

                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                Подписано {{ $device->created_at?->translatedFormat('j F Y') }}
                                @if ($device->last_used_at !== null)
                                    · последнее уведомление {{ $device->last_used_at->diffForHumans() }}
                                @else
                                    · уведомлений ещё не приходило
                                @endif
                            </p>
                        </div>

                        <button
                            type="button"
                            wire:click="revokeDevice({{ $device->getKey() }})"
                            wire:confirm="Отозвать подписку этого устройства?"
                            class="shrink-0 text-sm font-medium text-danger-600 hover:underline dark:text-danger-400"
                        >Отозвать</button>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="mt-4">
            <template x-if="state === 'default' || state === 'unsubscribed'">
                <button
                    type="button"
                    x-on:click="allow()"
                    x-bind:disabled="busy"
                    class="fi-btn fi-btn-size-sm rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-60"
                >Разрешить уведомления в этом браузере</button>
            </template>

            <template x-if="state === 'subscribed'">
                <p class="text-sm text-success-600 dark:text-success-400">Этот браузер подписан.</p>
            </template>

            <template x-if="state === 'denied'">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Уведомления запрещены в настройках браузера. Снять запрет можно только там:
                    значок замка слева от адреса → «Уведомления» → «Разрешить».
                </p>
            </template>

            <template x-if="state === 'insecure'">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Уведомления работают только по HTTPS. Эта страница открыта по обычному
                    HTTP, поэтому подписаться нельзя — исключение сделано только
                    для localhost.
                </p>
            </template>

            <template x-if="state === 'unconfigured'">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Браузерные уведомления не настроены на сервере: не заданы ключи VAPID.
                </p>
            </template>

            <template x-if="state === 'unsupported'">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Этот браузер не умеет показывать push-уведомления.
                </p>
            </template>
        </div>
    </div>
</div>
