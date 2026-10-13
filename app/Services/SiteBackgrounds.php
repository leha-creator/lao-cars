<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Media;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Фоновые фотографии вёрстки, которые заказчик меняет из панели: фон
 * первого экрана главной, фон секции заявки и фотография мастерской.
 *
 * До этого сервиса все три были файлами в `resources/images/` и менялись
 * заменой файла с пересборкой. Теперь каждая — настройка-объект с одним
 * полем `image_id`, по прецеденту `home.trust`.
 *
 * **Почему сервис один на три слота.** На главной их три, и `find()`
 * на каждый — это три запроса ради трёх картинок. `resolve()` принимает
 * список слотов и разрешает их ОДНИМ запросом — приём
 * `HomeContent::withStepImages()`.
 *
 * **Почему под блоки, а не под страницу.** Фотографию мастерской
 * показывают две страницы — главная и `/services`. Собирать её в двух
 * сервисах страниц значило бы дать им разойтись; граница та же, что
 * у `PartsHeroContent`.
 *
 * **Почему `null`, а не адрес файла вёрстки.** Пустой слот означает
 * «показать прежний файл из `resources/images/`», но подставляет его
 * шаблон, а не сервис: адрес файла вёрстки выдаёт манифест сборки,
 * и сервису о нём знать незачем. Отсюда же безопасность выката — на проде
 * ключей нет, пока форму настроек не сохранили, и сайт до этого момента
 * не меняется.
 *
 * `HomeContent::singleImageUrl()` (промо и полоса доверия) и
 * `PartsHeroContent::imageUrl()` сюда не переведены намеренно: оба
 * работают и закрыты сторожами, а сведение трёх механизмов в один —
 * рефакторинг чужих блоков, а не часть задачи про фоны.
 *
 * Сервис без состояния и без `Request`; потребители получают его через
 * конструктор, а не `new` — иначе его не подменить в тесте.
 */
final class SiteBackgrounds
{
    public const string HERO = 'hero';

    public const string LEAD = 'lead';

    public const string SERVICE_PANEL = 'service_panel';

    /**
     * Слот → ключ настройки и название для лога.
     *
     * Ключи настроек обязаны совпадать с `ManageSiteSettings::KEYS`,
     * `SiteSettingSeeder` и `MediaSettingKeys` — последний блокирует
     * удаление выбранного файла из медиабиблиотеки.
     *
     * @var array<string, array{setting: string, what: string}>
     */
    private const array SLOTS = [
        self::HERO => [
            'setting' => 'home.hero',
            'what' => 'фон первого экрана главной',
        ],
        self::LEAD => [
            'setting' => 'home.lead',
            'what' => 'фон секции заявки',
        ],
        self::SERVICE_PANEL => [
            'setting' => 'services_page.panel',
            'what' => 'фотография мастерской',
        ],
    ];

    /**
     * Снимки запрошенных слотов — одним запросом на все.
     *
     * `null` у слота означает «своего снимка нет»: поле пустое, ключа нет
     * или запись пропала из медиабиблиотеки. Во всех трёх случаях шаблон
     * показывает файл вёрстки.
     *
     * `width` и `height` бывают `null`: колонки появились вехой 4.14,
     * и у записей старше неё они не заполнены.
     *
     * @param  list<string>  $slots
     * @return array<string, ?array{url: string, width: ?int, height: ?int}>
     */
    public function resolve(array $slots): array
    {
        $ids = [];

        foreach ($slots as $slot) {
            if (! isset(self::SLOTS[$slot])) {
                // Опечатка в вызове иначе дала бы вечный файл вёрстки,
                // неотличимый от пустого поля в панели.
                throw new InvalidArgumentException("Неизвестный слот фоновой фотографии: {$slot}");
            }

            $ids[$slot] = $this->imageId(self::SLOTS[$slot]['setting']);
        }

        $wanted = array_values(array_unique(array_filter(
            $ids,
            static fn (?int $id): bool => $id !== null,
        )));

        /** @var EloquentCollection<int, Media> $media */
        $media = $wanted === []
            ? new EloquentCollection
            : Media::query()->whereKey($wanted)->get()->keyBy(
                static fn (Media $record): int => (int) $record->getKey(),
            );

        $resolved = [];

        foreach ($ids as $slot => $id) {
            $resolved[$slot] = null;

            if ($id === null) {
                continue;
            }

            $record = $media->get($id);

            if ($record === null) {
                // Штатным путём сюда не попасть: удаление используемой
                // записи блокирует `Media::usages()` через реестр
                // `MediaSettingKeys`. Значит, запись пропала в обход
                // админки, и это стоит WARN — снаружи отказ неотличим
                // от пустого поля: на сайте просто стоит файл вёрстки.
                Log::warning('[Фоны] '.self::SLOTS[$slot]['what'].' ссылается на удалённую запись медиабиблиотеки', [
                    'setting' => self::SLOTS[$slot]['setting'].'.image_id',
                    'media_id' => $id,
                ]);

                continue;
            }

            $resolved[$slot] = [
                // `url`, а не `thumb_url`: все три кадра крупные, и превью
                // шириной 600 растянулось бы в мыло.
                'url' => $record->url,
                'width' => $record->width,
                'height' => $record->height,
            ];
        }

        Log::debug('[Фоны] слоты разрешены', [
            'slots' => array_map(
                static fn (?array $image): bool => $image !== null,
                $resolved,
            ),
        ]);

        return $resolved;
    }

    /**
     * Идентификатор записи медиабиблиотеки из настройки-объекта или `null`.
     *
     * Настройки читаются через кеш `Setting` — запросов к базе здесь нет.
     */
    private function imageId(string $setting): ?int
    {
        $value = Setting::get($setting);

        if (! is_array($value)) {
            return null;
        }

        $id = $value['image_id'] ?? null;

        // Пустота проверяется строго — правило `RULES.md`: `empty()`
        // истинно и для нуля. Нечисловое значение в jsonb — не ссылка
        // на запись, и в `whereKey()` оно уронило бы запрос в PostgreSQL.
        if ($id === null || $id === '' || ! is_numeric($id)) {
            return null;
        }

        return (int) $id;
    }
}
