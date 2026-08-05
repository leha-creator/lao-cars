<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ContactMethod;
use App\Enums\PreferredTime;

/**
 * Заявка, приведённая к типам, — то, что осталось от формы после
 * валидации.
 *
 * Сервис принимает этот DTO, а не `Request`: тот же `LeadService::capture()`
 * вызывается из теста, консольной команды и импорта без подделки HTTP
 * (правило зависимостей из `ARCHITECTURE.md`).
 *
 * Собирает DTO сам `StoreLeadRequest::toData()` — по прецеденту
 * `CatalogFilterRequest::toCriteria()` вехи 3.6. Имена HTTP-полей
 * (`contact_method`, `source_type`) превращаются в свойства ровно в одном
 * месте.
 */
final readonly class LeadData
{
    /**
     * @param  ?string  $sourceType  алиас morph map (`car` / `service`),
     *                               а не FQCN: так значение хранится
     *                               в `leads.source_type`
     * @param  ?string  $pageUrl  адрес страницы, с которой пришла форма;
     *                            определяется сервером, а не формой
     */
    public function __construct(
        public string $name,
        public string $phone,
        public ?string $email = null,
        public ?string $message = null,
        public ?ContactMethod $contactMethod = null,
        public ?PreferredTime $preferredTime = null,
        public ?string $partBrand = null,
        public ?string $partModel = null,
        public ?string $partVin = null,
        public ?string $sourceType = null,
        public ?int $sourceId = null,
        public ?string $pageUrl = null,
    ) {}

    /**
     * Атрибуты для `Lead::create()`.
     *
     * `status` в массив не попадает намеренно: умолчание `'new'` стоит
     * на колонке (миграция вехи 3.2), и это единственный его источник.
     * Второй источник истины в DTO означал бы, что заявку из консоли
     * или сида можно завести сразу закрытой мимо действий смены статуса —
     * единственного места, где статус меняется.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'message' => $this->message,
            'contact_method' => $this->contactMethod,
            'preferred_time' => $this->preferredTime,
            'part_brand' => $this->partBrand,
            'part_model' => $this->partModel,
            'part_vin' => $this->partVin,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'page_url' => $this->pageUrl,
        ];
    }
}
