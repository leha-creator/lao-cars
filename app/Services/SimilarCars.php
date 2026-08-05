<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Car;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Подбор похожих автомобилей для карточки (веха 3.6).
 *
 * Два запроса, а не один умный: сначала та же марка, ближайшие по цене;
 * если набралось меньше нужного — добор из других марок в коридоре ±30%
 * от цены. Один запрос с `UNION` и вычисляемым приоритетом был бы
 * «правильнее» на бумаге и нечитаемым на практике, а два простых запроса
 * на карточке автомобиля стоят ровно ничего.
 */
final class SimilarCars
{
    private const float PRICE_CORRIDOR = 0.3;

    /**
     * @return Collection<int, Car>
     */
    public function for(Car $car, ?int $limit = null): Collection
    {
        $limit ??= (int) config('catalog.similar_limit');

        $sameBrand = $this->candidates($car)
            ->where('brand_id', $car->brand_id)
            ->limit($limit)
            ->get();

        if ($sameBrand->count() >= $limit) {
            return $sameBrand;
        }

        // Одномарочных не хватило — значит, в наличии их больше нет,
        // и исключения по ключам достаточно, чтобы второй круг шёл
        // по другим маркам.
        $others = $this->candidates($car)
            ->whereKeyNot($sameBrand->modelKeys())
            ->when(
                $car->price !== null,
                // Коридора вокруг `NULL` не существует: попытка его
                // посчитать даёт `NULL` в каждом сравнении и пустую
                // выдачу. У автомобиля без цены второй круг — просто
                // свежие доступные.
                fn (Builder $query) => $query->whereBetween('price', [
                    (int) round((int) $car->price * (1 - self::PRICE_CORRIDOR)),
                    (int) round((int) $car->price * (1 + self::PRICE_CORRIDOR)),
                ]),
            )
            ->limit($limit - $sameBrand->count())
            ->get();

        return $sameBrand->concat($others);
    }

    /**
     * Общая часть обоих кругов: доступные, кроме самого автомобиля,
     * в порядке близости по цене.
     *
     * Блок похожих показывает фото, марку и цену, поэтому обе выборки
     * идут с предзагрузкой — иначе три карточки дадут семь запросов.
     *
     * @return Builder<Car>
     */
    private function candidates(Car $car): Builder
    {
        return Car::query()
            ->available()
            ->whereKeyNot($car)
            ->with(['brand', 'mainPhoto'])
            ->when(
                $car->price !== null,
                fn (Builder $query) => $query->orderByRaw('abs(price - ?) NULLS LAST', [$car->price]),
                fn (Builder $query) => $query->orderByRaw('created_at DESC'),
            )
            // Tie-breaker по той же причине, что в `CatalogFilter`:
            // равные расстояния по цене и совпадающие `created_at`
            // иначе дают разный состав блока от запроса к запросу.
            ->orderByDesc('id');
    }
}
