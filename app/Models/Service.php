<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServiceCategory;
use App\Models\Concerns\HasSlug;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Позиция того, что компания предлагает помимо автомобилей:
 * работы автосервиса, детейлинг, доп. сервисы и категории запчастей.
 *
 * Одна сущность на все категории — у всех позиций одинаковая форма
 * (название, описание, цена или «по запросу», порядок) и одно
 * назначение: привести к заявке. Различает их только категория.
 */
#[Fillable([
    'category',
    'title',
    'slug',
    'description',
    'price',
    'price_note',
    'is_published',
    'sort_order',
])]
#[RouteKey('slug')]
final class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    use HasSlug;

    /**
     * Уточнения к цене, которые ставятся ПЕРЕД суммой.
     *
     * Всё остальное идёт после — см. `priceLabel()`.
     */
    private const array PRICE_NOTE_PREFIXES = ['от', 'до'];

    public function leads(): MorphMany
    {
        return $this->morphMany(Lead::class, 'source');
    }

    /**
     * Цена в прайсе строкой: «от 6 500 ₽», «1 200 ₽ за колесо», «по запросу».
     *
     * Данные не различают префикс и суффикс, и это осознанно: `price_note` —
     * одна колонка со свободным текстом, а «от» стоит перед суммой,
     * «за колесо» — после. Позиция уточнения выводится из набора предлогов,
     * а не хранится в базе, потому что альтернативы хуже: словарь всех
     * возможных уточнений не выживет (администратор напишет своё
     * и получит пустое место вместо текста), а колонка-переключатель
     * «префикс/суффикс» — это миграция ради вёрстки.
     *
     * Триггер пересмотра назван заранее: третий предлог, попросившийся
     * в `PRICE_NOTE_PREFIXES`, означает, что позицию уточнения пора хранить
     * в данных. До него ошибка стоит одной грамматически неверной строки
     * в прайсе, а не переделки схемы.
     *
     * Сравнение идёт по приведённому к нижнему регистру и обрезанному
     * значению, а печатается исходное: администратор напишет «От»,
     * и разница в одной букве не должна утаскивать предлог в конец строки.
     */
    public function priceLabel(): string
    {
        if (! $this->hasPrice()) {
            return 'по запросу';
        }

        $sum = number_format((int) $this->price, 0, ',', ' ').' ₽';

        $note = trim((string) $this->price_note);

        if ($note === '') {
            return $sum;
        }

        return in_array(mb_strtolower($note), self::PRICE_NOTE_PREFIXES, true)
            ? "{$note} {$sum}"
            : "{$sum} {$note}";
    }

    /**
     * Есть ли у позиции цена — то есть надо ли набирать её акцентом.
     *
     * Метод заведён ради шаблона: сравнивать вывод `priceLabel()` со строкой
     * «по запросу» в Blade значит завести второе место, где живёт эта
     * формулировка, и разойтись с первым при первой же её правке.
     */
    public function hasPrice(): bool
    {
        return $this->price !== null;
    }

    #[Scope]
    protected function inCategory(Builder $query, ServiceCategory $category): void
    {
        $query->where('category', $category);
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true);
    }

    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('title');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ServiceCategory::class,
            'price' => 'integer',
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected function slugSource(): string
    {
        return (string) $this->title;
    }
}
