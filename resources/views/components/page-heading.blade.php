@props([
    'title' => null,
    'eyebrow' => null,
    'intro' => null,
])

{{--
    Шапка внутренней страницы: надзаголовок, H1 и вводный текст.

    Заведён вехой 4.1 вместе с четырьмя страницами разом — три копии
    одного и того же блока разъехались бы на первом же изменении ритма
    заголовков, а вехам 4.4 и 4.5 он достаётся готовым.

    Заголовок принимается двумя способами (веха 4.3). Строкой `title` —
    для простых случаев, как в вехе 4.1. Слотом — там, где внутри H1 есть
    акцентное слово: в каталоге это «Автомобили в наличии и <span>под
    заказ</span>», а строкой разметку не выразишь. Своя секция заголовка
    в шаблоне каталога была бы четвёртой копией того же блока — ровно
    того, ради чего компонент и заводился.
--}}
<section class="border-b border-white/10 px-5 pt-12 pb-10 lg:px-8 lg:pt-18 lg:pb-12">
    <div class="mx-auto max-w-page">
        @if ($eyebrow !== null)
            <div class="mb-4 text-[13px] tracking-[0.2em] text-accent uppercase">{{ $eyebrow }}</div>
        @endif

        <h1 class="font-display text-3xl font-semibold lg:text-[38px]">{{ $slot->isEmpty() ? $title : $slot }}</h1>

        @if ($intro !== null && $intro !== '')
            <p class="mt-5 max-w-2xl leading-relaxed text-ink-muted">{{ $intro }}</p>
        @endif
    </div>
</section>
