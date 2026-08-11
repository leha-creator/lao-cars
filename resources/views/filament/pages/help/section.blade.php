{{--
    Список статей одного раздела справки.

    Оформление — только штатными классами и компонентами панели. Своя тема
    Filament (вторая сборка CSS) ради справки не заводится, а утилит Tailwind
    в вендорной теме нет вовсе: класс `grid` или `gap-4` в разметке проходит
    молча и не даёт ни строки CSS. Секции сложены вертикально не «потому что
    так проще» — `.fi-page-content` сама является гридом с межстрочным
    отступом, и каждая секция получает его без единого своего правила.

    Состав списка уже отфильтрован `HelpContent::visible()`: закрытых для роли
    заголовков здесь нет по построению.
--}}

@php
    use App\Filament\Pages\Help\Article;
    use Filament\Support\Icons\Heroicon;
@endphp

<x-filament-panels::page>
    @forelse ($articles as $article)
        <x-filament::section
            :heading="$article->title"
            :description="$article->summary"
        >
            <x-slot name="afterHeader">
                <x-filament::link
                    :href="Article::getUrl(['article' => $article->slug])"
                    :icon="Heroicon::OutlinedArrowRight"
                    icon-position="after"
                >
                    Открыть
                </x-filament::link>
            </x-slot>
        </x-filament::section>
    @empty
        {{--
            Пустой список обязан выглядеть как пустой список, а не как
            поломка вёрстки. Сегодня такого случая нет — обе роли видят
            статьи в обоих разделах, — но фраза стоит нескольких строк,
            а её отсутствие стоит пустой страницы под заголовком.
        --}}
        <x-filament::empty-state
            heading="Здесь пока нет статей для вас"
            description="Состав справки зависит от того, какие разделы панели вам доступны. Загляните в соседний раздел справки или спросите администратора."
            :icon="Heroicon::OutlinedBookOpen"
        />
    @endforelse
</x-filament-panels::page>
