<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServicePage;
use App\Models\Concerns\HasSlug;
use Database\Factories\ServiceCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Категория позиций прайса — редактируемый справочник.
 *
 * До вехи 4.13 категорий было ровно пять и жили они кейсами енама
 * `App\Enums\ServiceCategory`: добавить шестую значило выкатить релиз.
 * Образец новой сущности назван заказчиком дословно — «как марки авто»,
 * и `Brand` здесь именно образец: `name`, `slug`, `sort_order`, `HasSlug`,
 * `ordered()`, CRUD с перетаскиванием и отдельным классом действия
 * удаления. Второй механизм «редактируемый справочник» проекту не нужен.
 *
 * Описание категории — колонка `description`, а не настройка сайта.
 * До вехи 4.13 описания лежали в `services_page.notes.<значение енама>` —
 * объекте, чьи ключи были значениями енама. С редактируемым справочником
 * такой объект превращается в мусор при первом же удалении категории:
 * поле в форме настроек исчезает, ключ остаётся в jsonb вечно и никому
 * не виден. Побочный выигрыш измеримый: страница услуг и витрина главной
 * читают одно поле, а не один ключ настроек из двух мест.
 *
 * Метода `anchor()` здесь нет намеренно: якорь блока — это `slug` и есть,
 * а второй метод, возвращающий то же значение, разойдётся с ним при первой
 * правке. У старого енама `anchor()` существовал ровно потому, что значения
 * кейсов содержали подчёркивание (`tire_service`), а якоря макета — дефис,
 * и метод делал `str_replace('_', '-', …)`. Слаги подчёркиваний не содержат
 * вовсе — преобразовывать стало нечего. Слаги пяти исходных категорий
 * миграция перенесла как есть (`tire-service`, а не `tire_service`), потому
 * что якоря вида `/services#tire-service` уже разошлись по документации
 * и прототипу.
 *
 * Метода `icon()` здесь нет тоже: иконки сняты с заголовков категорий
 * по просьбе заказчика 19.08.2026 — и на странице услуг, и на витрине
 * главной, где те же категории набирались тем же значком в том же кружке.
 */
#[Fillable(['name', 'slug', 'page', 'description', 'sort_order'])]
final class ServiceCategory extends Model
{
    /** @use HasFactory<ServiceCategoryFactory> */
    use HasFactory;

    use HasSlug;

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Единственная ли это категория страницы запчастей.
     *
     * Ответ на вопрос «останется ли посадочная страница подбора без единого
     * блока, если эту категорию убрать» — удалением или сменой страницы.
     * Оба запрета читают его отсюда: продублированное условие однажды
     * обновится только в одном месте.
     *
     * Состояние берётся из БАЗЫ (`fresh()`), а не из объекта: на сохранении
     * формы `page` в модели уже новое, и проверка по нему отвечала бы
     * на вопрос «а после смены?» — то есть всегда «нет».
     */
    public function isOnlyPartsCategory(): bool
    {
        if ($this->fresh()?->page !== ServicePage::Parts) {
            return false;
        }

        return self::query()
            ->onPage(ServicePage::Parts)
            ->whereKeyNot($this->getKey())
            ->doesntExist();
    }

    /**
     * Категории одной публичной страницы.
     */
    #[Scope]
    protected function onPage(Builder $query, ServicePage $page): void
    {
        $query->where('page', $page);
    }

    /**
     * Порядок блоков на странице: сначала заданный вручную, затем алфавит.
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page' => ServicePage::class,
            'sort_order' => 'integer',
        ];
    }

    protected function slugSource(): string
    {
        return (string) $this->name;
    }
}
