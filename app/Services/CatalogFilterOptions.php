<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EngineType;
use App\Models\Brand;
use App\Models\Car;
use App\Models\CarAttribute;
use App\Models\CarAttributeValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Варианты, которые показывает форма фильтра каталога (веха 3.6).
 *
 * Опции строятся из справочников, но показываются только те, по которым
 * что-то найдётся: вариант, дающий ноль автомобилей, — не «пустая
 * выдача», а сломанное доверие к фильтру.
 *
 * Счётчиков рядом с вариантами нет осознанно. Честный счётчик обязан
 * учитывать уже применённые фильтры, то есть считаться заново на каждую
 * комбинацию — это фасетный поиск, а не фильтр каталога MVP. Счётчик без
 * учёта применённых фильтров хуже отсутствия: он обещает «Кроссоверов: 12»,
 * а после клика показывает два.
 *
 * Кеша здесь тоже нет, и это решение, а не недосмотр: пять лёгких запросов
 * на показ каталога дешевле, чем инвалидация, завязанная на события `Car`,
 * `CarAttributeValue` и `Brand` сразу. Триггер пересмотра конкретный —
 * каталог за тысячу карточек либо заметная доля времени ответа на этих
 * запросах.
 */
final class CatalogFilterOptions
{
    /**
     * @return array{
     *     brands: EloquentCollection<int, Brand>,
     *     engines: list<EngineType>,
     *     years: array{min: ?int, max: ?int},
     *     prices: array{min: ?int, max: ?int},
     *     attributes: list<array{attribute: CarAttribute, values: list<array{value: string, label: string}>}>,
     * }
     */
    public function build(): array
    {
        return [
            'brands' => $this->brands(),
            'engines' => $this->engines(),
            ...$this->bounds(),
            'attributes' => $this->attributes(),
        ];
    }

    /**
     * Марки, за которыми числится хоть один доступный автомобиль.
     *
     * Порядок — из справочника (`Brand::ordered()`): сначала заданный
     * вручную, затем алфавит.
     *
     * @return EloquentCollection<int, Brand>
     */
    private function brands(): EloquentCollection
    {
        return Brand::query()
            ->ordered()
            ->whereHas('cars', fn (Builder $cars) => $cars->available())
            ->get();
    }

    /**
     * Типы двигателя, встречающиеся в доступной выдаче.
     *
     * Порядок берётся из порядка кейсов enum-а, а не из выдачи БД:
     * `DISTINCT` порядок не хранит, и список в форме менялся бы местами
     * от одного показа каталога к другому.
     *
     * @return list<EngineType>
     */
    private function engines(): array
    {
        $present = Car::query()
            ->available()
            ->distinct()
            ->pluck('engine_type')
            ->map(fn (mixed $value): ?EngineType => $value instanceof EngineType
                ? $value
                : EngineType::tryFrom((string) $value))
            ->filter()
            ->all();

        return array_values(array_filter(
            EngineType::cases(),
            static fn (EngineType $case): bool => in_array($case, $present, strict: true),
        ));
    }

    /**
     * Границы года и цены — по доступным автомобилям, а не по всем.
     *
     * Одним запросом: четыре агрегата по одной и той же выборке разными
     * запросами были бы четырьмя проходами по таблице.
     *
     * @return array{years: array{min: ?int, max: ?int}, prices: array{min: ?int, max: ?int}}
     */
    private function bounds(): array
    {
        $bounds = Car::query()
            ->available()
            ->selectRaw('min(year) as year_min, max(year) as year_max, min(price) as price_min, max(price) as price_max')
            ->first();

        return [
            'years' => [
                'min' => $this->integer($bounds?->getAttribute('year_min')),
                'max' => $this->integer($bounds?->getAttribute('year_max')),
            ],
            'prices' => [
                'min' => $this->integer($bounds?->getAttribute('price_min')),
                'max' => $this->integer($bounds?->getAttribute('price_max')),
            ],
        ];
    }

    /**
     * Фильтруемые характеристики со списком реально встречающихся значений.
     *
     * Значения собираются одним сгруппированным запросом по доступным
     * автомобилям и пересекаются со списком справочника — порядок берётся
     * из справочника: `DISTINCT` по колонке значений порядок не хранит
     * и, как записано в миграции вехи 3.3, наполняется опечатками.
     *
     * Характеристика без единого встречающегося значения из списка
     * не возвращается вовсе: пустой селект в форме — сломанный контрол.
     *
     * @return list<array{attribute: CarAttribute, values: list<array{value: string, label: string}>}>
     */
    private function attributes(): array
    {
        $attributes = CarAttribute::query()
            ->inFilter()
            ->ordered()
            ->get()
            // Флаг гасится моделью при смене типа, но справочник мог быть
            // наполнен миграцией или массовым обновлением в обход событий.
            ->filter(fn (CarAttribute $attribute): bool => $attribute->type->isFilterable());

        if ($attributes->isEmpty()) {
            return [];
        }

        $present = CarAttributeValue::query()
            ->select('car_attribute_id', 'value')
            // Подзапросом по скоупу, а не повтором списка статусов:
            // «что считается доступным» описано в `Car::available()`
            // и больше нигде.
            ->whereIn('car_id', Car::query()->available()->select('id'))
            ->whereIn('car_attribute_id', $attributes->modelKeys())
            ->groupBy('car_attribute_id', 'value')
            ->get()
            ->groupBy('car_attribute_id')
            ->map(fn ($rows): array => $rows->pluck('value')->all());

        $options = [];

        foreach ($attributes as $attribute) {
            $values = $this->valuesFor($attribute, $present->get($attribute->id, []));

            if ($values === []) {
                continue;
            }

            $options[] = ['attribute' => $attribute, 'values' => $values];
        }

        return $options;
    }

    /**
     * Варианты одной характеристики: значение для URL и подпись для формы.
     *
     * @param  array<int, string>  $present
     * @return list<array{value: string, label: string}>
     */
    private function valuesFor(CarAttribute $attribute, array $present): array
    {
        // Подписи «Да» и «Нет» берутся у `format()`: словарь живёт
        // в `CarAttribute` и больше нигде — вторая копия неизбежно
        // разъедется.
        $candidates = $attribute->type->hasOptions()
            ? ($attribute->options ?? [])
            : ['1', '0'];

        $values = [];

        foreach ($candidates as $candidate) {
            if (! in_array($candidate, $present, strict: true)) {
                continue;
            }

            $values[] = [
                'value' => (string) $candidate,
                'label' => $attribute->format((string) $candidate) ?? (string) $candidate,
            ];
        }

        return $values;
    }

    private function integer(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
