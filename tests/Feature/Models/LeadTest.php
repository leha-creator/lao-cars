<?php

use App\Enums\ContactMethod;
use App\Enums\LeadStatus;
use App\Enums\PreferredTime;
use App\Models\Brand;
use App\Models\Car;
use App\Models\Lead;
use App\Models\LeadComment;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

it('stores the source type as a short morph alias', function () {
    $car = Car::factory()->create();

    Lead::factory()->forCar($car)->create();

    // Не FQCN: с полным именем класса перенос модели в другой namespace
    // ломает все существующие строки задним числом.
    expect(DB::table('leads')->value('source_type'))->toBe('car');
});

it('resolves the source relation back to the model', function () {
    $car = Car::factory()->create();
    $service = Service::factory()->create();

    $fromCar = Lead::factory()->forCar($car)->create();
    $fromService = Lead::factory()->forService($service)->create();

    expect($fromCar->source)->toBeInstanceOf(Car::class)
        ->and($fromCar->source->is($car))->toBeTrue()
        ->and($fromService->source)->toBeInstanceOf(Service::class)
        ->and($fromService->source->is($service))->toBeTrue();
});

it('describes a lead coming from a car card', function () {
    $brand = Brand::factory()->create(['name' => 'Zeekr']);
    $car = Car::factory()->for($brand)->create(['model' => '001']);

    $lead = Lead::factory()->forCar($car)->create();

    expect($lead->sourceLabel())->toBe('Авто: Zeekr 001');
});

it('describes a lead coming from a service', function () {
    $service = Service::factory()->create(['title' => 'Полировка кузова']);

    $lead = Lead::factory()->forService($service)->create();

    expect($lead->sourceLabel())->toBe('Услуга: Полировка кузова');
});

it('describes a lead coming from the general form', function () {
    $lead = Lead::factory()->general()->create();

    expect($lead->source)->toBeNull()
        ->and($lead->sourceLabel())->toBe('Общая форма');
});

it('casts status and form fields to enums', function () {
    $lead = Lead::factory()->create([
        'status' => LeadStatus::InProgress,
        'contact_method' => ContactMethod::Telegram,
        'preferred_time' => PreferredTime::Evening,
    ]);

    $lead->refresh();

    expect($lead->status)->toBe(LeadStatus::InProgress)
        ->and($lead->contact_method)->toBe(ContactMethod::Telegram)
        ->and($lead->preferred_time)->toBe(PreferredTime::Evening);
});

it('defaults to the new status', function () {
    $lead = Lead::query()->create([
        'name' => 'Иван',
        'phone' => '+7 900 000-00-00',
    ]);

    $lead->refresh();

    expect($lead->status)->toBe(LeadStatus::New);
});

it('filters leads by processing status', function () {
    Lead::factory()->count(2)->general()->create();
    Lead::factory()->general()->inProgress()->create();
    Lead::factory()->general()->closed()->create();

    expect(Lead::new()->count())->toBe(2)
        ->and(Lead::unclosed()->count())->toBe(3);
});

it('recognises a parts lookup request by its car fields', function () {
    $parts = Lead::factory()->general()->partsRequest()->create();
    $plain = Lead::factory()->general()->create();

    expect($parts->isPartsRequest())->toBeTrue()
        ->and($parts->part_vin)->not->toBeNull()
        ->and($plain->isPartsRequest())->toBeFalse();
});

it('keeps manager comments with their author', function () {
    $lead = Lead::factory()->general()->create();
    $comment = LeadComment::factory()->for($lead)->create();

    expect($lead->comments)->toHaveCount(1)
        ->and($comment->author->exists)->toBeTrue();
});

it('deletes comments together with the lead', function () {
    $lead = Lead::factory()->general()->create();
    LeadComment::factory()->count(2)->for($lead)->create();

    $lead->delete();

    expect(LeadComment::query()->count())->toBe(0);
});
