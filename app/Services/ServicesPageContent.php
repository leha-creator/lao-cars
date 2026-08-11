<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ServiceCategory;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;

/**
 * Данные страницы автосервиса (веха 4.4).
 *
 * `ARCHITECTURE.md` разрешает контроллеру читать модель напрямую, когда речь
 * про чистое чтение и прослойка была бы пустым файлом. Здесь она пустой
 * не будет: группировка позиций прайса по категориям, отсев категорий без
 * позиций, две настройки-объекта разной формы, нормализация репитера
 * и заголовки. Граница названа вехой 4.2 дословно — «если данные надо
 * приводить к форме, прежде чем отдать их в Blade, это сервис, даже если
 * запрос один». Прецедент точный: `HomeContent`.
 *
 * У страницы запчастей такого сервиса нет намеренно (решение 19): там один
 * запрос и три текстовых ключа, то есть прослойка была бы пустым файлом,
 * а `ARCHITECTURE.md` приводит РОВНО этот запрос примером допустимого
 * чтения из контроллера.
 *
 * Нормализация настроек живёт здесь по той же причине, что и в `HomeContent`:
 * форма настроек (веха 3.5) пишет свои ключи безусловно, даже пустыми, —
 * «очистить блок» там рабочий сценарий, — а репитер при удалении всех
 * элементов отдаёт `null`, а не `[]`. Шаблон, написанный по сиду, падает
 * на первом же `foreach (null)`, и падает на проде.
 */
final class ServicesPageContent
{
    /**
     * @return array{
     *     title: string,
     *     intro: ?string,
     *     blocks: list<array{category: ServiceCategory, icon: string, anchor: string, note: ?string, items: EloquentCollection<int, Service>}>,
     *     services: EloquentCollection<int, Service>,
     *     disclaimer: ?string,
     *     advantages: list<array{number: ?string, title: string, text: ?string}>,
     * }
     */
    public function build(): array
    {
        $blocks = $this->blocks();

        return [
            // Фолбэк на очищенный заголовок, а не только на отсутствующий
            // ключ: форма настроек пишет пустое значение как есть, и без
            // нормализации страница получила бы пустой H1 при живой
            // настройке — заодно и пустой `<title>` в шапке документа.
            'title' => $this->string(Setting::get('services_page.intro_title')) ?? 'Автосервис',
            'intro' => $this->string(Setting::get('services_page.intro_text')),
            'blocks' => $blocks,
            'services' => $this->flatten($blocks),
            'disclaimer' => $this->string(Setting::get('services_page.price_disclaimer')),
            'advantages' => $this->advantages(),
        ];
    }

    /**
     * Блоки прайса по категориям.
     *
     * Состав и порядок задаёт `ServiceCategory::serviceCategories()`, а не
     * список в этом методе: второй список разошёлся бы с первым на первой же
     * новой категории. Запчасти исключает сам енам (`isParts()`), а не
     * условие в цикле.
     *
     * Категория без опубликованных позиций выпадает целиком — правило
     * проекта «блок, управляемый данными, при пустом значении не рендерится
     * вовсе». Здесь оно работает дважды: заголовок «Шиномонтаж» над пустым
     * местом читается как поломка, а ссылка якорной навигации на
     * несуществующий якорь не работает молча. Оба списка шаблон строит
     * из ЭТОГО массива, а не собирает независимо.
     *
     * @return list<array{category: ServiceCategory, icon: string, anchor: string, note: ?string, items: EloquentCollection<int, Service>}>
     */
    private function blocks(): array
    {
        $notes = Setting::get('services_page.notes');
        $notes = is_array($notes) ? $notes : [];

        // Один запрос на всю страницу, группировка в PHP. Запрос на категорию
        // означал бы четыре запроса ради четырёх блоков плюс пятый на селект
        // формы — и все пять отличались бы одним `where`.
        $published = Service::query()->published()->ordered()->get();

        if ($published->isEmpty()) {
            // WARN на каждый рендер — осознанно, по прецеденту `HomeContent`
            // с пустой подборкой: это конфигурационная ошибка, которая
            // чинится один раз в админке, а тихий пропуск даёт страницу
            // автосервиса без автосервиса. Снаружи она при этом выглядит
            // работающей, поэтому заметить её больше нечем.
            Log::warning('[Автосервис] на странице нет ни одной опубликованной позиции прайса', [
                'hint' => 'опубликуйте услуги в разделе «Услуги и запчасти» админки',
            ]);
        }

        $blocks = [];

        foreach (ServiceCategory::serviceCategories() as $category) {
            $items = $published->where('category', $category);

            if ($items->isEmpty()) {
                continue;
            }

            $blocks[] = [
                'category' => $category,
                'icon' => $category->icon(),
                'anchor' => $category->anchor(),
                // Пустое описание убирает абзац, но НЕ блок: прайс важнее
                // текста, и категория без описания остаётся на странице.
                'note' => $this->string($notes[$category->value] ?? null),
                'items' => $items,
            ];
        }

        return $blocks;
    }

    /**
     * Плоский список позиций для селекта «Интересует» в форме записи.
     *
     * Собирается из уже готовых блоков, а не отдельным запросом: набор обязан
     * совпадать с прайсом до позиции — иначе посетитель выбирает в форме то,
     * чего на странице нет, или наоборот. Порядок при этом наследуется
     * от блоков, поэтому `<optgroup>` в шаблоне выстраиваются в том же
     * порядке, что и категории.
     *
     * @param  list<array{category: ServiceCategory, icon: string, anchor: string, note: ?string, items: EloquentCollection<int, Service>}>  $blocks
     * @return EloquentCollection<int, Service>
     */
    private function flatten(array $blocks): EloquentCollection
    {
        /** @var EloquentCollection<int, Service> $services */
        $services = new EloquentCollection;

        foreach ($blocks as $block) {
            $services = $services->concat($block['items']);
        }

        return $services;
    }

    /**
     * Блок «Почему сюда». Формат намеренно тот же, что у `home.advantages`,
     * и элемент без заголовка так же выпадает: карточка из одного номера
     * выглядит как поломка вёрстки.
     *
     * @return list<array{number: ?string, title: string, text: ?string}>
     */
    private function advantages(): array
    {
        $advantages = [];

        foreach ($this->listFrom(Setting::get('services_page.advantages')) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = $this->string($item['title'] ?? null);

            if ($title === null) {
                continue;
            }

            $advantages[] = [
                'number' => $this->string($item['number'] ?? null),
                'title' => $title,
                'text' => $this->string($item['text'] ?? null),
            ];
        }

        return $advantages;
    }

    /**
     * Значение репитера настроек в виде списка.
     *
     * Удаление всех элементов в форме настроек даёт `null`, а не `[]`, —
     * это и есть причина, по которой нормализация живёт в сервисе.
     *
     * @return list<mixed>
     */
    private function listFrom(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Непустая строка или `null`.
     *
     * Проверка пустоты строгая, а не `empty()`: правило `RULES.md` —
     * `empty('0')` в PHP истинно.
     */
    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
