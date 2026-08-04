<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CatalogSort;
use App\Models\Brand;
use App\Models\Car;
use App\Models\CarAttribute;
use App\Support\AttributeFilterIndex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Сборка запроса каталога по набору критериев (веха 3.6).
 *
 * Принимает DTO, а не `Request`, — это прямое правило `ARCHITECTURE.md`
 * («Сервис не должен знать, пришли данные из HTTP-формы, консольной
 * команды или теста»), и оно же делает возможными тесты фильтра без
 * поднятия HTTP.
 */
final class CatalogFilter
{
    /**
     * Применить критерии к запросу каталога.
     *
     * Скоуп `available()` применяется всегда и первым, до любых
     * пользовательских условий: проданные автомобили не выпадают
     * из фильтра — они выпадают из выдачи. Параметр `status` может лишь
     * сузить доступное до «в наличии» или «под заказ».
     *
     * @param  Builder<Car>  $query
     * @return Builder<Car>
     */
    public function apply(Builder $query, CatalogCriteria $criteria): Builder
    {
        $query->available();

        if ($criteria->brand !== null) {
            // Подзапросом по `brand_id`, а не `whereHas('brand')`: это
            // дешевле и позволяет индексу `(brand_id, created_at)`
            // отработать. Slug → id разрешается одним запросом.
            $brandId = Brand::query()->where('slug', $criteria->brand)->value('id');

            // Несуществующая марка даёт пустую выдачу, а не игнорируется:
            // молча снятый фильтр показал бы весь каталог там, где
            // пользователь ждёт сужения.
            $query->where('brand_id', $brandId ?? 0);
        }

        if ($criteria->engine !== null) {
            $query->where('engine_type', $criteria->engine);
        }

        if ($criteria->status !== null) {
            $query->where('status', $criteria->status);
        }

        if ($criteria->yearFrom !== null) {
            $query->where('year', '>=', $criteria->yearFrom);
        }

        if ($criteria->yearTo !== null) {
            $query->where('year', '<=', $criteria->yearTo);
        }

        // Автомобили без цены под фильтр по цене не попадают: `NULL`
        // не проходит сравнение, и это верно — «цена по запросу»
        // не принадлежит ни одному диапазону.
        if ($criteria->priceFrom !== null) {
            $query->where('price', '>=', $criteria->priceFrom);
        }

        if ($criteria->priceTo !== null) {
            $query->where('price', '<=', $criteria->priceTo);
        }

        $this->applyAttributes($query, $criteria->attributes);
        $this->applySort($query, $criteria->sort);

        Log::debug('[CatalogFilter] фильтр применён', [
            'brand' => $criteria->brand,
            'engine' => $criteria->engine?->value,
            'year' => [$criteria->yearFrom, $criteria->yearTo],
            'price' => [$criteria->priceFrom, $criteria->priceTo],
            'status' => $criteria->status?->value,
            'attributes' => array_keys($criteria->attributes),
            'sort' => $criteria->sort->value,
        ]);

        return $query;
    }

    /**
     * Условия по динамическим характеристикам.
     *
     * На каждый ключ — отдельный `whereHas`, то есть `AND` между
     * характеристиками и `EXISTS` внутри.
     *
     * @param  Builder<Car>  $query
     * @param  array<string, string>  $attributes
     */
    private function applyAttributes(Builder $query, array $attributes): void
    {
        if ($attributes === []) {
            return;
        }

        // Справочник читается одним запросом на вызов и кладётся
        // в `keyBy('key')` — тем же приёмом, что в
        // `Car::syncAttributeValues()`, и по той же причине: поиск
        // по ключу внутри цикла дал бы запрос на каждую характеристику.
        $known = CarAttribute::query()->get()->keyBy('key');

        foreach ($attributes as $key => $value) {
            $attribute = $known->get($key);

            if (! $attribute instanceof CarAttribute) {
                // Битая или устаревшая ссылка — обычное дело, WARN тут
                // забил бы лог.
                Log::debug('[CatalogFilter] характеристики нет в справочнике, параметр отброшен', [
                    'key' => $key,
                ]);

                continue;
            }

            if ($attribute->show_in_filter !== true) {
                // Через URL фильтровать по непомеченной характеристике
                // нельзя — это контракт, а не защита.
                Log::debug('[CatalogFilter] характеристика не помечена «в фильтре», параметр отброшен', [
                    'key' => $key,
                ]);

                continue;
            }

            if (! $attribute->type->isFilterable()) {
                // Противоречие в справочнике: флаг стоит, а тип фильтровать
                // не даёт. Чинится в админке, поэтому уровень WARN.
                Log::warning('[CatalogFilter] характеристика помечена «в фильтре», но её тип нефильтруем', [
                    'key' => $key,
                    'type' => $attribute->type->value,
                ]);

                continue;
            }

            // Значение вне списка `options` не отбрасывается — оно даёт
            // честный ноль: молча снятый фильтр показал бы «нашлось 11»
            // там, где пользователь ждёт сужения.
            $query->whereHas('attributeValues', function (Builder $values) use ($attribute, $value): void {
                $values
                    // Без условия по характеристике индекс не берётся:
                    // `car_attribute_id` — первая колонка индекса.
                    ->where('car_attribute_id', $attribute->id)
                    // Длина префикса пишется в SQL литералом, а не
                    // биндом: планировщик сопоставляет выражение
                    // с определением индекса структурно, и `left(value, $1)`
                    // индексу `left(value, 64)` не соответствует.
                    // Пользовательский ввод здесь не участвует — это
                    // константа проекта.
                    ->whereRaw(
                        'left(value, '.AttributeFilterIndex::PREFIX_LENGTH.') = ?',
                        [AttributeFilterIndex::prefix($value)],
                    )
                    // Точное равенство поверх префикса: без него выдача
                    // врёт на значениях длиннее префикса.
                    ->where('value', $value);
            });
        }
    }

    /**
     * Порядок выдачи.
     *
     * У каждой сортировки есть tie-breaker по `id`: `LIMIT/OFFSET` без
     * полного порядка не обязан быть согласованным между запросами, и при
     * совпадающих `created_at` (импорт партии карточек одним прогоном —
     * ровно этот случай) одна карточка появляется и на первой странице,
     * и на второй, а другая не появляется нигде.
     *
     * `NULLS LAST` задаётся явно в обоих направлениях: в PostgreSQL
     * умолчание зависит от направления, и «сначала дорогие» без явного
     * указания открывалось бы автомобилями без цены.
     *
     * @param  Builder<Car>  $query
     */
    private function applySort(Builder $query, CatalogSort $sort): void
    {
        // Выражение выбирается `match`-ем по enum-у: пользовательский ввод
        // в raw-SQL не попадает по построению.
        $query->orderByRaw(match ($sort) {
            CatalogSort::Newest => 'cars.created_at DESC, cars.id DESC',
            CatalogSort::PriceAsc => 'cars.price ASC NULLS LAST, cars.id DESC',
            CatalogSort::PriceDesc => 'cars.price DESC NULLS LAST, cars.id DESC',
        });
    }
}
