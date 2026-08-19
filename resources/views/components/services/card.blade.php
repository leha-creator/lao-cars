@props([
    'service',
    // Карточка ведёт к форме и подставляет позицию в селект «Интересует».
    // На посадочной запчастей селекта нет вовсе — там карточка просто
    // рассказывает, что подбираем.
    'pick' => false,
    // «По запросу» вместо пустой цены. На странице автосервиса фолбэк несёт
    // смысл — позиция стоит рядом с позициями, у которых суммы есть.
    // На посадочной запчастей пустая цена у ВСЕХ позиций, и пять одинаковых
    // подписей повторили бы вводный текст пять раз.
    'priceFallback' => true,
])

{{--
    Карточка позиции с фотографией — веха 4.13.

    Разметка одна на страницу автосервиса и на посадочную запчастей, и это
    не экономия строк: два по-разному набранных вида одной карточки
    разъедутся на первой же правке одной из них.

    Раскладку повторяет карточка этапа покупки вехи 4.9 (`aspect-4/3`
    с `object-cover`, ниже `p-7` с текстом): прецедент работает на светлой
    ветке с вехи 4.12, и брать готовое дешевле, чем изобретать третий вид
    карточки на одной странице.

    ФОТОГРАФИЯ НЕОБЯЗАТЕЛЬНА. Позиция без кадра рисуется той же карточкой
    без верхней части — так карточки с фотографией и без неё стоят в одной
    сетке, не расходясь по ширине и обводке. Порядок («сначала с фотографией»)
    задаёт `Service::ordered()`, то есть SQL, а не шаблон.

    Изображение выдаётся через `url`, а не `thumb_url`, и `srcset`
    не задаётся: `ImageProcessor` ограничивает ширину сверху, но не
    апскейлит, а настоящих ширин вариантов у записи библиотеки нет —
    правило `RULES.md` про дескрипторы, которым нечем подтвердиться.
--}}
<article
    x-data="{ open: false }"
    {{ $attributes->class(['relative flex flex-col overflow-hidden rounded-card border border-line bg-surface transition duration-250 hover:border-accent/30']) }}
>
    @if ($service->imageUrl !== null)
        {{-- Кадр иллюстрирует услугу, поэтому `alt` настоящий, а не пустой
             с `aria-hidden`. `loading="lazy"` — карточки стоят ниже первого
             экрана, и правило LCP из `RULES.md` к ним не относится. --}}
        <img
            src="{{ $service->imageUrl }}"
            loading="lazy"
            alt="{{ $service->title }}"
            class="aspect-4/3 w-full object-cover"
        >
    @endif

    <div class="flex flex-1 flex-col p-7">
        <h3 class="text-[17px] font-semibold">
            @if ($pick)
                {{-- Ссылка «растянута» на всю карточку псевдоэлементом
                     (`after:absolute after:inset-0`), а не обёрнута вокруг
                     неё: кнопка «Подробнее» ниже — интерактивный элемент,
                     и внутри `<a>` она даёт невалидную разметку
                     с разъезжающимся клавиатурным фокусом.

                     Ссылка обычная, поэтому без скрипта клик доводит
                     до формы, и посетитель выбирает позицию сам. Alpine
                     лишь подставляет её в селект, поэтому `x-on:click`
                     идёт без `.prevent`. Полная форма `x-on:click`,
                     а не `@click`: `@` — префикс директив Blade
                     (правило `ARCHITECTURE.md`). --}}
                <a
                    href="#lead-form"
                    x-on:click="pick({{ $service->getKey() }})"
                    class="transition-colors after:absolute after:inset-0 hover:text-accent"
                >{{ $service->title }}</a>
            @else
                {{ $service->title }}
            @endif
        </h3>

        @if (filled($service->description))
            <p class="mt-3 text-sm leading-relaxed text-ink-muted">{{ $service->description }}</p>
        @endif

        @if ($service->hasPrice() || $priceFallback)
            {{-- «По запросу» — не цена, и набирать её акцентом наравне
                 с суммой значит обещать то, чего в карточке нет. Развилка
                 идёт по `hasPrice()`, а не по сравнению вывода со строкой
                 «по запросу»: иначе формулировка жила бы в двух местах. --}}
            <div @class([
                'mt-4 text-sm',
                'font-display font-semibold text-accent' => $service->hasPrice(),
                'font-medium text-ink-muted' => ! $service->hasPrice(),
            ])>{{ $service->priceLabel() }}</div>
        @endif

        <x-services.details :service="$service" />
    </div>
</article>
