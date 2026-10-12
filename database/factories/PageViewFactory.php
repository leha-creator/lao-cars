<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DeviceType;
use App\Models\Car;
use App\Models\PageView;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PageView>
 */
class PageViewFactory extends Factory
{
    protected $model = PageView::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // По умолчанию — переход внутри сайта с компьютера: заход извне,
        // источник и метки включаются состояниями, чтобы тест источников
        // не получал их случайно.
        return [
            'viewed_at' => now(),
            'path' => fake()->randomElement(['/', '/catalog', '/services', '/parts', '/contacts', '/about']),
            'route_name' => null,
            'car_id' => null,
            // У каждой строки свой посетитель, пока тест не скажет иначе.
            'visitor_hash' => fake()->unique()->regexify('[0-9a-f]{32}'),
            'is_entry' => false,
            'referrer_host' => null,
            'utm_source' => null,
            'utm_medium' => null,
            'utm_campaign' => null,
            'device' => DeviceType::Desktop,
        ];
    }

    /**
     * Прямой заход: источника нет, но это вход на сайт.
     */
    public function entry(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_entry' => true,
        ]);
    }

    public function fromReferrer(string $host): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_entry' => true,
            'referrer_host' => $host,
        ]);
    }

    public function forCar(Car $car): static
    {
        return $this->state(fn (array $attributes): array => [
            'car_id' => $car->id,
            'path' => '/catalog/'.$car->slug,
            'route_name' => 'catalog.show',
        ]);
    }

    public function mobile(): static
    {
        return $this->state(fn (array $attributes): array => [
            'device' => DeviceType::Mobile,
        ]);
    }

    /**
     * Просмотр того же посетителя — для проверок счёта уникальных.
     */
    public function byVisitor(string $hash): static
    {
        return $this->state(fn (array $attributes): array => [
            'visitor_hash' => $hash,
        ]);
    }
}
