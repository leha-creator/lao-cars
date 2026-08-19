<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasSlug;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Позиция того, что компания предлагает помимо автомобилей:
 * работы автосервиса, детейлинг, доп. сервисы и категории запчастей.
 *
 * Одна сущность на все категории — у всех позиций одинаковая форма
 * (название, описание, цена или «по запросу», порядок) и одно
 * назначение: привести к заявке. Различает их только категория.
 *
 * Категория с вехи 4.13 — строка справочника `ServiceCategory`, а не кейс
 * енама: заказчик правит состав категорий сам. Вместе со справочником
 * позиция получила фотографию (`media_id`), подробное описание (`details`)
 * и флаг широкой карточки (`is_featured`) — все три необязательны, и все
 * три меняют только вид карточки, а не её присутствие на странице.
 */
#[Fillable([
    'service_category_id',
    'media_id',
    'title',
    'slug',
    'description',
    'details',
    'price',
    'price_note',
    'is_featured',
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

    /**
     * Внешний ключ задан явно: Eloquent вывел бы его из имени метода
     * (`category_id`), а колонка называется `service_category_id` —
     * и связь молча отдавала бы `null` вместо ошибки.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    /**
     * Фотография позиции из общей медиабиблиотеки.
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

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
        $query->where('service_category_id', $category->getKey());
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true);
    }

    /**
     * Порядок выдачи: акцентные позиции, затем позиции с фотографией,
     * затем остальные; внутри каждой группы — `sort_order`, затем алфавит.
     *
     * Три группы идут стопкой сверху вниз, каждая своей раскладкой:
     * смешивать карточку с кадром и строку прайса в одном потоке нечем —
     * у них разная высота и разный вес.
     *
     * «Фотография есть» задаётся выражением, а не сортировкой по nullable-
     * колонке: правило `RULES.md` про `NULLS LAST` предупреждает ровно об
     * этом умолчании — в PostgreSQL оно зависит от направления сортировки.
     * `media_id IS NULL` по возрастанию ставит `false` первым, то есть
     * позиции с фотографией наверх, и читается это одинаково в обе стороны.
     *
     * Сортировка живёт в SQL, а не в коллекции после выборки: страница
     * собирается одним запросом на все категории, и сортировка в PHP
     * повторила бы то, что база делает бесплатно.
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderByDesc('is_featured')
            ->orderByRaw('media_id IS NULL')
            ->orderBy('sort_order')
            ->orderBy('title');
    }

    /**
     * URL фотографии позиции или `null`.
     *
     * Не входит в `$appends` — по той же причине, что `url` в `Media`
     * и `CarPhoto`: сериализация списка не должна дёргать драйвер диска
     * на каждую запись.
     *
     * Отдаётся `url`, а не `thumb_url`: `ImageProcessor` ограничивает
     * ширину сверху, но не апскейлит, а превью заведомо мельче карточки
     * во всю ширину контента.
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(
            fn (): ?string => $this->media?->url,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'is_featured' => 'boolean',
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected function slugSource(): string
    {
        return (string) $this->title;
    }
}
