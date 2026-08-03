<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Настройка сайта: контакты, соцсети, тексты редактируемых блоков,
 * SEO по умолчанию (раздел 4 ТЗ).
 *
 * Хранилище «ключ — значение» с jsonb: значения разнородны, от строки
 * телефона до массива карточек преимуществ, а колонка на каждый блок
 * означала бы миграцию на каждую правку макета.
 *
 * Чтение идёт через кеш одной выборкой на запрос: шапка, подвал и
 * главная обращаются к настройкам десятки раз, и запрос на каждое
 * обращение — гарантированный N+1 на всех страницах сразу.
 */
#[Fillable(['key', 'value', 'group'])]
final class Setting extends Model
{
    public const CACHE_KEY = 'site_settings';

    protected $table = 'site_settings';

    /**
     * Значение настройки.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::cached()[$key]['value'] ?? $default;
    }

    /**
     * Записать настройку. Группа по умолчанию выводится из ключа:
     * `contacts.phone` → `contacts`.
     */
    public static function set(string $key, mixed $value, ?string $group = null): void
    {
        self::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => $group ?? self::groupFromKey($key)],
        );
    }

    /**
     * Все настройки группы в виде «ключ => значение».
     *
     * @return array<string, mixed>
     */
    public static function group(string $group): array
    {
        $values = [];

        foreach (self::cached() as $key => $row) {
            if ($row['group'] === $group) {
                $values[$key] = $row['value'];
            }
        }

        return $values;
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function booted(): void
    {
        // Без сброса администратор сохраняет настройку и не видит
        // изменений — самый частый способ получить «баг» на пустом месте.
        self::saved(static fn (): mixed => self::flushCache());
        self::deleted(static fn (): mixed => self::flushCache());
    }

    /**
     * @return array<string, array{value: mixed, group: string}>
     */
    protected static function cached(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, static function (): array {
            // Промах кеша — единственный момент, когда настройки читаются
            // из БД. Если он виден на каждом запросе, кеш не работает.
            Log::debug('[Setting] промах кеша, читаем site_settings из БД');

            return self::query()
                ->get(['key', 'value', 'group'])
                ->mapWithKeys(static fn (self $setting): array => [
                    $setting->key => ['value' => $setting->value, 'group' => $setting->group],
                ])
                ->all();
        });
    }

    protected static function groupFromKey(string $key): string
    {
        return Str::contains($key, '.') ? Str::before($key, '.') : 'general';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // json, а не array: значением может быть и скаляр, и массив.
        return [
            'value' => 'json',
        ];
    }
}
