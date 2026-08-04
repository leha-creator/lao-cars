<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CarStatus;
use App\Enums\CatalogSort;
use App\Enums\EngineType;

/**
 * Фильтр каталога, приведённый к типам, — то, что осталось от адресной
 * строки после валидации и нормализации.
 *
 * `fromArray()` — единственное место в проекте, где имена GET-параметров
 * (`year_from`, `price_to`, `attr[...]`) превращаются в поля. Имя параметра,
 * размазанное по контроллеру, шаблону и сервису, разъезжается на первом же
 * переименовании и делает это молча: страница отрабатывает, фильтр
 * не применяется.
 *
 * Применяет фильтр `CatalogFilter` — имена намеренно непохожие:
 * `CatalogFilter` и `CatalogFilters` в одном `use`-блоке путаются
 * на первом же чтении.
 */
final readonly class CatalogCriteria
{
    /**
     * @param  ?string  $brand  slug марки, а не id: id в адресной строке —
     *                          мусор, который к тому же нельзя перенести
     *                          между окружениями
     * @param  array<string, string>  $attributes  значения динамических
     *                                             характеристик по машинному ключу
     */
    public function __construct(
        public ?string $brand = null,
        public ?EngineType $engine = null,
        public ?int $yearFrom = null,
        public ?int $yearTo = null,
        public ?int $priceFrom = null,
        public ?int $priceTo = null,
        public ?CarStatus $status = null,
        public array $attributes = [],
        public CatalogSort $sort = CatalogSort::Newest,
    ) {}

    /**
     * Собрать критерии из провалидированных GET-параметров.
     *
     * Перевёрнутый диапазон (`year_from=2025&year_to=2020`) нормализуется
     * перестановкой, а не отклоняется правилом `gte`: это обычная ошибка
     * пользователя в двух селектах, и правильный ответ на неё — показать
     * автомобили 2020–2025, а не выкинуть человека на чистый каталог.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        [$yearFrom, $yearTo] = self::ascending(
            self::integer($validated['year_from'] ?? null),
            self::integer($validated['year_to'] ?? null),
        );

        [$priceFrom, $priceTo] = self::ascending(
            self::integer($validated['price_from'] ?? null),
            self::integer($validated['price_to'] ?? null),
        );

        $engine = self::text($validated['engine'] ?? null);
        $status = self::text($validated['status'] ?? null);
        $sort = self::text($validated['sort'] ?? null);

        return new self(
            brand: self::text($validated['brand'] ?? null),
            engine: $engine === null ? null : EngineType::tryFrom($engine),
            yearFrom: $yearFrom,
            yearTo: $yearTo,
            priceFrom: $priceFrom,
            priceTo: $priceTo,
            status: $status === null ? null : CarStatus::tryFrom($status),
            attributes: self::attributes($validated['attr'] ?? null),
            sort: ($sort === null ? null : CatalogSort::tryFrom($sort)) ?? CatalogSort::Newest,
        );
    }

    /**
     * Задан ли хоть один фильтр или неумолчательная сортировка.
     *
     * По этому признаку страница получает `robots: noindex,follow`:
     * комбинаций фильтров сотни, и каждая даёт свой URL. Номер страницы
     * в расчёт не входит — страница 2 обязана индексироваться, иначе
     * карточки с неё не попадут в индекс никогда.
     */
    public function hasActiveFilters(): bool
    {
        return $this->brand !== null
            || $this->engine !== null
            || $this->yearFrom !== null
            || $this->yearTo !== null
            || $this->priceFrom !== null
            || $this->priceTo !== null
            || $this->status !== null
            || $this->attributes !== []
            || ! $this->sort->isDefault();
    }

    /**
     * Значения динамических характеристик: `['body_type' => 'Кроссовер']`.
     *
     * Нечисловые ключи и нестроковые значения отбрасываются молча —
     * `attr[0]=x` в контракт не входит, а ключ характеристики по правилу
     * формы вехи 3.4 всегда начинается с буквы.
     *
     * @return array<string, string>
     */
    private static function attributes(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $attributes = [];

        foreach ($values as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                continue;
            }

            $value = trim($value);

            // Проверка строгая: `empty('0')` в PHP истинно, а '0' —
            // валидное значение булевой характеристики («Нет»).
            if ($value === '') {
                continue;
            }

            $attributes[$key] = $value;
        }

        return $attributes;
    }

    /**
     * Границы диапазона по возрастанию.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private static function ascending(?int $from, ?int $to): array
    {
        if ($from !== null && $to !== null && $from > $to) {
            return [$to, $from];
        }

        return [$from, $to];
    }

    private static function integer(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
