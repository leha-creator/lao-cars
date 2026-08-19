<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use App\Support\WorkSchedule;

/**
 * Микроразметка организации на странице контактов (веха 4.14).
 *
 * Появилась вместе со структурным расписанием и ради него: структура
 * `contacts.schedule` существует затем, чтобы её можно было отдать
 * машине, и `openingHoursSpecification` — единственное место, где выигрыш
 * от структуры виден снаружи, а не только в форме админки. По свободной
 * строке «Пн–Вс, 9:00–21:00» такой разметки не собрать в принципе.
 *
 * ОДНА СТРАНИЦА, А НЕ ВСЕ. `LocalBusiness` в подвале каждой страницы —
 * это дубль сущности в глазах поисковика; `/contacts` для неё каноничная.
 *
 * Сервисом это стало по тому же признаку, что `CarStructuredData`: данные
 * надо привести к форме, прежде чем отдать их в Blade, — выбросить пустые
 * ключи, собрать вложенный адрес, перевести расписание в словарь
 * schema.org. Собирать это в шаблоне значило бы держать словари
 * в разметке.
 */
final class OrganizationStructuredData
{
    /**
     * @return array<string, mixed>
     */
    public function forContactsPage(): array
    {
        $contacts = Setting::group('contacts');

        return $this->withoutEmpty([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => config('app.name'),
            'url' => route('contacts.index'),
            'telephone' => $this->text($contacts['contacts.phone'] ?? null),
            'email' => $this->text($contacts['contacts.email'] ?? null),
            'address' => $this->address($this->text($contacts['contacts.address'] ?? null)),
            'openingHoursSpecification' => WorkSchedule::fromSetting(
                $contacts['contacts.schedule'] ?? null,
            )->openingHoursSpecification(),
        ]);
    }

    /**
     * Почтовый адрес одной строкой, как его вводит администратор.
     *
     * На части `addressLocality`/`streetAddress` он не разбирается:
     * настройка — одно поле свободного текста, и любое разбиение было бы
     * догадкой по запятым. Догадка в микроразметке — это не «примерно
     * верно», а заявление о факте, которого никто не проверял.
     *
     * @return ?array<string, string>
     */
    private function address(?string $address): ?array
    {
        return $address === null ? null : [
            '@type' => 'PostalAddress',
            'streetAddress' => $address,
        ];
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Выбросить пустые ключи.
     *
     * Незаполненный контакт в JSON-LD — это заявление «у организации нет
     * телефона», а не «мы его не указали». Разница видна не человеку,
     * а агрегатору, и исправлять её потом некому.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withoutEmpty(array $values): array
    {
        return array_filter(
            $values,
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        );
    }
}
