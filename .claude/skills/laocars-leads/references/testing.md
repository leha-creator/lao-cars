# Feature-тесты форм заявок

Формы заявок — то, ради чего существует сайт. Тесты покрывают: создание лида с каждого
источника, отсечение мусора и **сохранность лида при недоступном Telegram**.

## Фабрика

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LeadStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

final class LeadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'     => fake('ru_RU')->name(),
            'phone'    => fake('ru_RU')->phoneNumber(),
            'email'    => fake()->safeEmail(),
            'message'  => fake('ru_RU')->sentence(),
            'status'   => LeadStatus::New,
            'page_url' => '/',
        ];
    }

    public function forCar(Car $car): self
    {
        return $this->state(fn (): array => [
            'source_type' => 'car',
            'source_id'   => $car->id,
        ]);
    }
}
```

## Создание лида с каждого источника

```php
<?php

use App\Enums\LeadStatus;
use App\Jobs\NotifyManagerAboutLead;
use App\Models\Car;
use App\Models\Lead;
use App\Models\Service;
use Illuminate\Support\Facades\Queue;

it('создаёт лид с карточки автомобиля и привязывает его к авто', function (): void {
    Queue::fake();
    $car = Car::factory()->create(['brand' => 'Zeekr', 'model' => '001']);

    $response = $this->post(route('leads.store'), [
        'name'        => 'Иван',
        'phone'       => '+7 900 123-45-67',
        'source_type' => 'car',
        'source_id'   => $car->id,
        'page_url'    => "/catalog/{$car->id}",
    ]);

    $response->assertRedirect();

    $lead = Lead::sole();
    expect($lead->source)->toBeInstanceOf(Car::class)
        ->and($lead->source->id)->toBe($car->id)
        ->and($lead->status)->toBe(LeadStatus::New);

    Queue::assertPushed(NotifyManagerAboutLead::class);
});

it('создаёт лид со страницы услуги', function (): void {
    Queue::fake();
    $service = Service::factory()->create(['title' => 'Комплексная мойка']);

    $this->post(route('leads.store'), [
        'name'        => 'Пётр',
        'phone'       => '+7 900 000-00-00',
        'source_type' => 'service',
        'source_id'   => $service->id,
    ])->assertRedirect();

    expect(Lead::sole()->source)->toBeInstanceOf(Service::class);
});

it('создаёт лид с общей формы без источника', function (): void {
    Queue::fake();

    $this->post(route('leads.store'), [
        'name'  => 'Анна',
        'phone' => '+7 900 111-22-33',
    ])->assertRedirect();

    $lead = Lead::sole();
    expect($lead->source)->toBeNull()
        ->and($lead->sourceLabel())->toBe('Общая форма');
});
```

## Валидация и защита

```php
it('не создаёт лид без телефона', function (): void {
    $this->post(route('leads.store'), ['name' => 'Иван'])
        ->assertSessionHasErrors('phone');

    expect(Lead::count())->toBe(0);
});

it('отбрасывает спам с заполненным honeypot', function (): void {
    Queue::fake();

    $this->post(route('leads.store'), [
        'name'    => 'Bot',
        'phone'   => '+7 900 123-45-67',
        'website' => 'http://spam.example',
    ])->assertSessionHasErrors('website');

    expect(Lead::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('не привязывает лид к несуществующему авто', function (): void {
    $this->post(route('leads.store'), [
        'name'        => 'Иван',
        'phone'       => '+7 900 123-45-67',
        'source_type' => 'car',
        'source_id'   => 99999,
    ])->assertSessionHasErrors('source_id');

    expect(Lead::count())->toBe(0);
});

it('срабатывает rate limiting после 5 заявок в минуту', function (): void {
    Queue::fake();

    foreach (range(1, 5) as $i) {
        $this->post(route('leads.store'), [
            'name' => "Клиент {$i}", 'phone' => '+7 900 123-45-67',
        ])->assertRedirect();
    }

    $this->post(route('leads.store'), [
        'name' => 'Шестой', 'phone' => '+7 900 123-45-67',
    ])->assertStatus(429);

    expect(Lead::count())->toBe(5);
});
```

## Главный тест: падение Telegram не теряет лид

Прямая проверка инварианта №1. Если этот тест зелёный — бизнес не теряет деньги при
недоступном внешнем API.

```php
use App\Services\TelegramNotifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('сохраняет лид и отвечает пользователю, даже если Telegram недоступен', function (): void {
    // Очередь работает синхронно — воспроизводим полный путь, включая сбой
    config(['queue.default' => 'sync']);
    Http::fake(['api.telegram.org/*' => Http::response('Service Unavailable', 503)]);

    $response = $this->post(route('leads.store'), [
        'name'  => 'Иван',
        'phone' => '+7 900 123-45-67',
    ]);

    // Пользователь не видит ошибку
    $response->assertRedirect()->assertSessionHasNoErrors();

    // Лид на месте — это главное
    expect(Lead::count())->toBe(1);
});

it('логирует окончательный провал доставки уведомления', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response('', 503)]);
    Log::shouldReceive('channel')->with('leads')->andReturnSelf();
    Log::shouldReceive('error')->once()->withArgs(
        fn (string $message): bool => str_contains($message, 'failed permanently')
    );

    $lead = Lead::factory()->create();
    (new NotifyManagerAboutLead($lead))->failed(new RuntimeException('Telegram API error: 503'));
});
```

## Тест админки

```php
it('показывает менеджеру заявки со всех источников в одном списке', function (): void {
    $manager = User::factory()->manager()->create();
    Lead::factory()->forCar(Car::factory()->create())->create();
    Lead::factory()->create(); // общая форма

    $this->actingAs($manager)
        ->get(LeadResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Общая форма');
});

it('не пускает менеджера в настройки сайта', function (): void {
    $manager = User::factory()->manager()->create();

    $this->actingAs($manager)
        ->get(SiteSettingsResource::getUrl('index'))
        ->assertForbidden();
});
```

## Запуск

```bash
php artisan test --filter=Lead
php artisan test --coverage
```

Тесты идут на отдельной БД (`.env.testing`), не на рабочих данных: `RefreshDatabase`
очищает таблицы между тестами — направьте его на прод и потеряете все заявки.
