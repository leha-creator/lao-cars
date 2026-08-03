<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContactMethod;
use App\Enums\LeadStatus;
use App\Enums\PreferredTime;
use App\Models\Car;
use App\Models\Lead;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->optional()->safeEmail(),
            'message' => fake()->optional()->text(150),
            'contact_method' => fake()->randomElement(ContactMethod::cases()),
            'preferred_time' => fake()->randomElement(PreferredTime::cases()),
            'part_brand' => null,
            'part_model' => null,
            'part_vin' => null,
            // По умолчанию источника нет — это общая форма обратной связи.
            'source_type' => null,
            'source_id' => null,
            'status' => LeadStatus::New,
            'page_url' => fake()->url(),
        ];
    }

    /**
     * Заявка на конкретный автомобиль из карточки каталога.
     */
    public function forCar(?Car $car = null): static
    {
        return $this->for($car ?? Car::factory(), 'source');
    }

    /**
     * Запись на услугу со страницы автосервиса.
     */
    public function forService(?Service $service = null): static
    {
        return $this->for($service ?? Service::factory(), 'source');
    }

    /**
     * Общая форма обратной связи — источника нет.
     */
    public function general(): static
    {
        return $this->state(fn (array $attributes): array => [
            'source_type' => null,
            'source_id' => null,
        ]);
    }

    /**
     * Подбор запчасти: автомобиль клиента описывается полями заявки,
     * а не ссылкой на каталог — его в каталоге нет.
     */
    public function partsRequest(): static
    {
        return $this->state(fn (array $attributes): array => [
            'part_brand' => fake()->randomElement(['Toyota', 'BMW', 'Zeekr', 'Haval']),
            'part_model' => fake()->bothify('Model ?##'),
            'part_vin' => strtoupper(fake()->bothify('?????????????????')),
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => LeadStatus::InProgress,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => LeadStatus::Closed,
        ]);
    }
}
