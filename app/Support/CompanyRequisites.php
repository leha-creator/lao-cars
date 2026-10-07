<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Строка реквизитов юридического лица для подвала и страницы контактов.
 *
 * Бренд на сайте — «ЛАО КАРС», а продавец и исполнитель — юридическое лицо,
 * и посетитель вправе знать, с кем имеет дело, не открывая политику
 * обработки персональных данных (ст. 9 Закона о защите прав потребителей).
 * До этой правки наименование, ИНН и ОГРН были только внутри её текста.
 *
 * Чистая функция от значения настройки `company.requisites`: строку
 * показывают два места, и собранная в каждом по-своему она разошлась бы
 * на первой же правке разделителя.
 */
final class CompanyRequisites
{
    /**
     * Ключ настройки-объекта с полями `legal_name`, `inn`, `ogrn`.
     */
    public const string SETTING_KEY = 'company.requisites';

    /**
     * «ООО «ЛаоКарс» · ИНН 9729396149 · ОГРН 1257700077183» или `null`.
     *
     * Каждая часть пропадает отдельно: незаполненный ОГРН не должен давать
     * «ОГРН » с пустым хвостом. Пусто всё — строки нет вовсе, и блок
     * на странице не рендерится (правило подвала).
     */
    public static function line(mixed $setting): ?string
    {
        $parts = array_filter([
            self::text(data_get($setting, 'legal_name')),
            self::labelled('ИНН', data_get($setting, 'inn')),
            self::labelled('ОГРН', data_get($setting, 'ogrn')),
        ], static fn (?string $part): bool => $part !== null);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private static function labelled(string $label, mixed $value): ?string
    {
        $value = self::text($value);

        // Неразрывный пробел: номер не должен отрываться от своей подписи
        // при переносе строки в узком подвале.
        return $value === null ? null : $label."\u{00A0}".$value;
    }

    /**
     * Непустая строка или `null` — строго, без `empty()` (правило `RULES.md`).
     */
    private static function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
