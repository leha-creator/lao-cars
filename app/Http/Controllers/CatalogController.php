<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CatalogFilterRequest;
use App\Models\Car;
use App\Services\CarStructuredData;
use App\Services\CatalogFilter;
use App\Services\CatalogFilterOptions;
use App\Services\SimilarCars;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Публичный каталог автомобилей (веха 3.6).
 *
 * Контроллер тонкий по правилу `ARCHITECTURE.md`: принимает
 * провалидированный запрос, отдаёт билдер фильтру и передаёт в шаблон
 * готовые данные. Условий выборки здесь нет — они живут
 * в `CatalogFilter`.
 */
final class CatalogController extends Controller
{
    public function index(CatalogFilterRequest $request, CatalogFilter $filter, CatalogFilterOptions $options): View
    {
        $criteria = $request->toCriteria();

        $cars = $filter
            // Без предзагрузки список из девяти карточек даёт 19 запросов:
            // N+1 на списках назван антипаттерном в `ARCHITECTURE.md`.
            ->apply(Car::query()->with(['brand', 'mainPhoto']), $criteria)
            ->paginate((int) config('catalog.per_page'))
            // Без `withQueryString()` вторая страница теряет фильтры.
            ->withQueryString();

        // Страница за пределами последней — 404, а не пустая страница
        // с кодом 200: тонкий контент поисковик увидит и запомнит.
        // Пустой каталог под это правило не подпадает — там пусто
        // по существу, а не из-за номера страницы.
        if ($cars->currentPage() > $cars->lastPage() && $cars->total() > 0) {
            abort(404);
        }

        // Логирование здесь не требуется: применённый набор фильтров
        // уже пишет `CatalogFilter`.
        return view('catalog.index', [
            'cars' => $cars,
            'options' => $options->build(),
            'criteria' => $criteria,
            'filtered' => $criteria->hasActiveFilters(),
            'canonical' => $this->canonical($cars),
        ]);
    }

    /**
     * Карточка автомобиля.
     *
     * Привязка по slug работает через объявленный на модели
     * `#[RouteKey('slug')]` — отдельной настройки роута не нужно.
     *
     * Проданный автомобиль страницу отдаёт: «Продан» — не удаление,
     * карточка остаётся ради истории и SEO (PHPDoc `CarStatus`),
     * и ссылки из выдачи Google не должны превращаться в 404.
     */
    public function show(Car $car, SimilarCars $similar, CarStructuredData $structuredData): View
    {
        // Без `attributeValues.attribute` каждая строка сетки
        // характеристик даёт свой запрос — об этом прямо предупреждает
        // PHPDoc `Car::cardAttributes()`. Эти же связи требует
        // `CarStructuredData`: собственных запросов он не делает.
        $car->load(['brand', 'photos', 'attributeValues.attribute']);

        return view('catalog.show', [
            'car' => $car,
            'similar' => $similar->for($car),
            // Микроразметку собирает сервис, а не шаблон: словари
            // schema.org в разметке — это словари, которые никто
            // не найдёт при следующей переверстке.
            'structuredData' => $structuredData->for($car),
        ]);
    }

    /**
     * Канонический адрес списка: `/catalog` плюс только номер страницы.
     *
     * Параметры фильтра из канонического адреса выбрасываются — комбинаций
     * сотни, и каждая давала бы свой URL. Номер страницы, наоборот,
     * обязан в нём остаться: у страницы 2 собственный канонический адрес,
     * иначе её карточки не попадут в индекс никогда.
     *
     * @param  LengthAwarePaginator<int, Car>  $cars
     */
    private function canonical(LengthAwarePaginator $cars): string
    {
        return $cars->currentPage() > 1
            ? route('catalog.index', ['page' => $cars->currentPage()])
            : route('catalog.index');
    }
}
