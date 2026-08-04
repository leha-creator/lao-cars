<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Ключи настроек сайта, значение которых — ссылка на медиабиблиотеку.
 *
 * Живёт в `app/Support/`, а не константой на странице настроек, по
 * правилу зависимостей из `ARCHITECTURE.md`: список нужен и форме
 * (`ManageSiteSettings`), и модели (`Media::usages()`), а модель не
 * должна знать о Filament-странице. Это чистые данные — ни диска, ни
 * HTTP, ни внешнего мира, — поэтому обращение к ним из модели слоёв
 * не нарушает. Тот же приём, что и в `ThumbnailPath`.
 *
 * Список — единственный источник правды: добавление второго медийного
 * ключа правится здесь, и оба потребителя видят его сразу. Совпадение
 * ключей с реальными полями формы проверяет тест.
 */
final class MediaSettingKeys
{
    /**
     * «путь к значению в настройках» => «как назвать это место человеку».
     *
     * Путь указывает внутрь значения настройки: ключ строки
     * `site_settings` — `home.promo`, а `image_id` — поле внутри её
     * jsonb-объекта.
     *
     * @var array<string, string>
     */
    private const array KEYS = [
        'home.promo.image_id' => 'Настройки: промо-блок на главной',
    ];

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::KEYS;
    }

    /**
     * @return list<string>
     */
    public static function paths(): array
    {
        return array_keys(self::KEYS);
    }
}
