<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CarStatus;
use App\Enums\DriveType;
use App\Enums\EngineType;
use App\Models\Concerns\HasSlug;
use App\Services\ImageProcessor;
use Database\Factories\CarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Автомобиль каталога.
 *
 * В колонках лежат только фиксированные фильтруемые характеристики
 * (раздел 3.2 ТЗ). Всё остальное — «кузов», «цвет», «растаможен» —
 * лежит в `attributeValues`: значения справочника `CarAttribute`,
 * где у каждой характеристики свой тип, единица измерения, группа
 * в сетке карточки и флаги вывода.
 *
 * Чтение характеристик — `cardAttributes()` и `attributeValue()`,
 * запись — только `syncAttributeValues()`. Оба метода чтения работают
 * поверх загруженной связи, поэтому в списках запрос обязан идти
 * с `with('attributeValues.attribute')`.
 */
#[Fillable([
    'brand_id',
    'model',
    'slug',
    'year',
    'engine_type',
    'engine_volume',
    'engine_power',
    'drive',
    'mileage',
    'price',
    'status',
    'show_on_homepage',
    'history',
    'description',
    'meta_title',
    'meta_description',
    'sort_order',
])]
#[RouteKey('slug')]
final class Car extends Model
{
    /** @use HasFactory<CarFactory> */
    use HasFactory;

    use HasSlug;

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(CarPhoto::class)->orderBy('sort_order');
    }

    /**
     * Главное фото — первое по порядку. Отдельного флага нет осознанно:
     * флаг и порядок неизбежно расходятся, и администратор перетаскивает
     * фото, не понимая, почему на карточке осталось старое.
     */
    public function mainPhoto(): HasOne
    {
        return $this->hasOne(CarPhoto::class)->oldestOfMany('sort_order');
    }

    public function leads(): MorphMany
    {
        return $this->morphMany(Lead::class, 'source');
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(CarAttributeValue::class);
    }

    /**
     * Характеристики для сетки карточки, сгруппированные по группам
     * справочника: «Кузов и салон», «Импорт», …
     *
     * Работает поверх уже загруженной связи и сам запросов не делает —
     * контроллер карточки (веха 4.3) обязан грузить
     * `with('attributeValues.attribute')`, иначе каждая строка сетки
     * даст отдельный запрос.
     *
     * Порядок групп задаётся минимальным `sort_order` внутри группы:
     * значения сортируются до раскладки, поэтому группа встречается
     * впервые ровно на своей самой «ранней» характеристике.
     *
     * Характеристики без группы идут последними под ключом `''`.
     * Настоящий `null` ключом быть не может — PHP приводит его к пустой
     * строке, — поэтому шаблон вехи 4.3 отличает эту группу проверкой
     * `$group !== ''` и выводит её блоком без заголовка.
     *
     * @return Collection<string, Collection<int, CarAttributeValue>>
     */
    public function cardAttributes(): Collection
    {
        /** @var Collection<string, Collection<int, CarAttributeValue>> $groups */
        $groups = new Collection;

        $visible = $this->attributeValues
            ->filter(fn (CarAttributeValue $value): bool => $value->attribute?->show_in_card === true)
            ->sortBy(fn (CarAttributeValue $value): int => $value->attribute->sort_order);

        foreach ($visible as $value) {
            $group = $value->attribute->group ?? '';

            if (! $groups->has($group)) {
                $groups->put($group, new Collection);
            }

            $groups->get($group)->push($value);
        }

        // Безымянная группа переставляется в конец: без заголовка она
        // читается как хвост сетки, а не как секция посреди карточки.
        if ($groups->count() > 1 && $groups->has('')) {
            $ungrouped = $groups->get('');
            $groups->forget('');
            $groups->put('', $ungrouped);
        }

        return $groups;
    }

    /**
     * Типизированное значение характеристики по её машинному ключу.
     *
     * Точечное чтение для вехи 4.3: микроразметке Vehicle нужен
     * `body_type`, а не вся сетка. Как и `cardAttributes()`, работает
     * поверх загруженной связи.
     */
    public function attributeValue(string $key): string|int|float|bool|null
    {
        $value = $this->attributeValues
            ->first(fn (CarAttributeValue $value): bool => $value->attribute?->key === $key);

        return $value?->casted;
    }

    /**
     * Записать значения характеристик по машинным ключам:
     * `['body_type' => 'Седан', 'doors' => 4]`.
     *
     * Единственный путь записи — им пользуются и редактор вехи 3.4, и
     * сиды. Правила ниже задаются здесь и нигде больше, иначе две
     * реализации неизбежно разъедутся:
     *
     * - справочник читается одним запросом на вызов, а не поиском по
     *   ключу внутри цикла (это 9 запросов на автомобиль и 108 лишних
     *   на прогоне сида);
     * - значение нормализуется в строку до проверки на пустоту, и
     *   проверка строгая: `empty('0')` в PHP истинно, и «Растаможен:
     *   Нет» удалялось бы вместо сохранения;
     * - пустое значение удаляет запись — администратор очистил поле,
     *   характеристика уходит из карточки, а не остаётся пустой строкой;
     * - значение вне списка `options` не пишется и логируется: иначе
     *   в фильтре вехи 3.6 появится вариант, по которому ничего не
     *   находится.
     *
     * Ключи, которых нет в справочнике, молча отбрасываются.
     *
     * @param  array<string, mixed>  $values
     */
    public function syncAttributeValues(array $values): void
    {
        // Один запрос на вызов: справочник — десятки строк, дальше
        // цикл идёт по памяти.
        $attributes = CarAttribute::query()->get()->keyBy('key');

        foreach ($values as $key => $value) {
            $attribute = $attributes->get($key);

            if (! $attribute instanceof CarAttribute) {
                continue;
            }

            $normalized = $this->normalizeAttributeValue($value);

            if ($normalized === null || $normalized === '') {
                $this->attributeValues()
                    ->where('car_attribute_id', $attribute->id)
                    ->delete();

                continue;
            }

            if (! $attribute->isValidValue($normalized)) {
                // Единственное исключение из «слой данных не логируется»:
                // отброшенное значение исчезает молча, и без записи
                // в лог разбираться будет не с чем.
                Log::warning('[Car] значение вне списка допустимых, запись пропущена', [
                    'car_id' => $this->id,
                    'key' => $key,
                    'value' => $normalized,
                ]);

                continue;
            }

            $this->attributeValues()->updateOrCreate(
                ['car_attribute_id' => $attribute->id],
                ['value' => $normalized],
            );
        }

        // Загруженная связь после записи устарела: следующее обращение
        // к cardAttributes() должно видеть актуальные значения.
        $this->unsetRelation('attributeValues');
    }

    /**
     * Записать галерею по списку путей — единственный путь записи фото.
     *
     * Ровно тот же приём, что `syncAttributeValues()`, и по той же
     * причине: правила ниже должны лежать в одном месте, иначе редактор
     * админки и будущий импорт разъедутся.
     *
     * - позиция в массиве становится `sort_order`, то есть первое фото
     *   и есть главное — `mainPhoto()` уже на это опирается;
     * - существующий путь обновляет только порядок: заданный вручную
     *   `alt` переживает пересортировку;
     * - у новой записи `alt` собирается по образцу `CarPhotoSeeder`
     *   («BMW X5, фото 3»), а `thumb_path` восстанавливается правилом
     *   `ImageProcessor::thumbPathFor()` и пишется, **только если файл
     *   существует на диске** — иначе шаблон получит битую ссылку;
     * - пропавшие из массива пути удаляются вызовом `delete()` на
     *   модели, а не массовым запросом: массовое удаление не поднимает
     *   события Eloquent, и файлы остались бы на диске навсегда.
     *
     * @param  array<int, string>  $paths
     */
    public function syncPhotos(array $paths): void
    {
        $paths = array_values(array_filter($paths, static fn (mixed $path): bool => is_string($path) && $path !== ''));

        $existing = $this->photos()->get()->keyBy('path');
        $disk = Storage::disk('public');
        $processor = app(ImageProcessor::class);

        foreach ($paths as $index => $path) {
            $photo = $existing->get($path);

            if ($photo instanceof CarPhoto) {
                $photo->sort_order = $index;
                $photo->save();

                continue;
            }

            $thumbPath = $processor->thumbPathFor($path);

            if (! $disk->exists($thumbPath)) {
                // Не ошибка: так выглядит файл, у которого обработка
                // не удалась и оригинал сохранён как есть.
                Log::warning('[Car] превью не найдено на диске, фото останется без него', [
                    'car_id' => $this->id,
                    'path' => $path,
                    'expected_thumb' => $thumbPath,
                ]);

                $thumbPath = null;
            }

            $this->photos()->create([
                'disk' => 'public',
                'path' => $path,
                'thumb_path' => $thumbPath,
                'alt' => trim(sprintf('%s %s', $this->brand?->name ?? '', $this->model)).', фото '.($index + 1),
                'sort_order' => $index,
            ]);
        }

        foreach ($existing as $path => $photo) {
            if (in_array($path, $paths, strict: true)) {
                continue;
            }

            $photo->delete();
        }

        // Загруженная связь после записи устарела.
        $this->unsetRelation('photos');
        $this->unsetRelation('mainPhoto');
    }

    protected static function booted(): void
    {
        // Каскад БД (`car_photos.car_id` объявлен `cascadeOnDelete()`
        // в вехе 3.2) событий Eloquent не поднимает — без этого удаление
        // автомобиля оставляет его файлы на диске навсегда.
        self::deleting(static function (self $car): void {
            foreach ($car->photos()->cursor() as $photo) {
                $photo->delete();
            }
        });
    }

    #[Scope]
    protected function inStock(Builder $query): void
    {
        $query->where('status', CarStatus::InStock);
    }

    #[Scope]
    protected function onOrder(Builder $query): void
    {
        $query->where('status', CarStatus::OnOrder);
    }

    /**
     * Всё, что можно купить. Проданные карточки остаются в базе ради
     * истории и SEO, но из выдачи каталога уходят.
     */
    #[Scope]
    protected function available(Builder $query): void
    {
        $query->whereIn('status', [CarStatus::InStock, CarStatus::OnOrder]);
    }

    #[Scope]
    protected function onHomepage(Builder $query): void
    {
        $query->where('show_on_homepage', true)->orderBy('sort_order');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'engine_type' => EngineType::class,
            'engine_volume' => 'decimal:1',
            'engine_power' => 'integer',
            'drive' => DriveType::class,
            'mileage' => 'integer',
            'price' => 'integer',
            'status' => CarStatus::class,
            'show_on_homepage' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected function slugSource(): string
    {
        return trim(sprintf('%s %s %s', $this->brand?->name ?? '', $this->model, $this->year));
    }

    /**
     * Привести значение к строке до проверки на пустоту.
     *
     * Порядок важен: сначала нормализация, потом проверка. Иначе
     * булево `false` не доживёт до колонки в понятном виде — во что
     * PDO превращает PHP-булево при биндинге в `text`, зависит от
     * драйвера, и полагаться на это нельзя.
     *
     * Разделитель дробной части — точка: `(float) '2,5'` в PHP молча
     * даёт `2.0`, поэтому запятая нормализуется формой на входе
     * (веха 3.4), а в БД её быть не должно.
     */
    private function normalizeAttributeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        // Приведение int и float к строке в PHP 8 не зависит от локали
        // и всегда даёт точку.
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return trim((string) $value);
    }
}
