<?php

/*
 * Панель заявок (веха 3.7).
 *
 * Раздел закрывает вторую половину роли менеджера: политика `LeadPolicy`
 * существовала с вехи 3.5 без экрана, к которому её применить.
 */

use App\Enums\LeadStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Filament\Resources\Leads\RelationManagers\CommentsRelationManager;
use App\Models\Brand;
use App\Models\Car;
use App\Models\Lead;
use App\Models\LeadComment;
use App\Models\Service;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Log;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->manager = User::factory()->create(['role' => UserRole::Manager]);
    $this->actingAs($this->manager);
});

it('shows the manager leads from every source', function () {
    $brand = Brand::factory()->create(['name' => 'Zeekr']);
    $car = Car::factory()->for($brand)->create(['model' => '001']);
    $service = Service::factory()->create(['title' => 'Полировка кузова']);

    $fromCar = Lead::factory()->forCar($car)->create();
    $fromService = Lead::factory()->forService($service)->create();
    $general = Lead::factory()->general()->create();

    livewire(ListLeads::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$fromCar, $fromService, $general])
        ->assertSee('Авто: Zeekr 001')
        ->assertSee('Услуга: Полировка кузова')
        // Заявка без источника не безымянная: у неё своя подпись.
        ->assertSee('Общая форма');
});

it('opens on new leads', function () {
    $new = Lead::factory()->general()->count(2)->create();
    $closed = Lead::factory()->general()->closed()->create();

    livewire(ListLeads::class)
        ->assertCanSeeTableRecords($new)
        ->assertCanNotSeeTableRecords([$closed]);
});

it('does not let the panel create leads', function () {
    // Заявки создаёт сайт: форма в панели завела бы второй путь появления
    // лида мимо валидации, honeypot и уведомления.
    expect(LeadResource::canCreate())->toBeFalse()
        ->and(LeadResource::getPages())->not->toHaveKey('create')
        // Страницы Edit тоже нет: содержимое заявки — слова клиента.
        ->and(LeadResource::getPages())->not->toHaveKey('edit')
        ->and(LeadResource::getPages())->toHaveKeys(['index', 'view']);
});

it('takes a lead into work from the view page', function () {
    $lead = Lead::factory()->general()->create();

    livewire(ViewLead::class, ['record' => $lead->getKey()])
        ->assertActionVisible('takeInWork')
        ->assertActionHidden('reopen')
        ->callAction('takeInWork');

    expect($lead->refresh()->status)->toBe(LeadStatus::InProgress);
});

it('closes and reopens a lead', function () {
    $lead = Lead::factory()->general()->inProgress()->create();

    livewire(ViewLead::class, ['record' => $lead->getKey()])
        ->callAction('close');

    expect($lead->refresh()->status)->toBe(LeadStatus::Closed);

    livewire(ViewLead::class, ['record' => $lead->getKey()])
        ->assertActionHidden('takeInWork')
        ->assertActionVisible('reopen')
        ->callAction('reopen');

    expect($lead->refresh()->status)->toBe(LeadStatus::InProgress);
});

it('logs a status change with its author', function () {
    $log = Log::spy();
    $log->shouldReceive('channel')->with('leads')->andReturnSelf();

    $lead = Lead::factory()->general()->create();

    livewire(ViewLead::class, ['record' => $lead->getKey()])
        ->callAction('takeInWork');

    // Событие с последствиями: закрытая заявка перестаёт быть в работе,
    // и без автора разбор «кто и когда её закрыл» невозможен.
    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $message === '[Lead] статус изменён'
            && $context['lead_id'] === $lead->getKey()
            && $context['from'] === 'new'
            && $context['to'] === 'in_progress'
            && $context['actor_id'] === $this->manager->getKey()
            // Персональные данные клиента в запись не идут.
            && ! array_key_exists('phone', $context))
        ->once();
});

it('saves a manager comment with the current user as its author', function () {
    $lead = Lead::factory()->general()->create();

    livewire(CommentsRelationManager::class, [
        'ownerRecord' => $lead,
        'pageClass' => ViewLead::class,
    ])
        // Через TestAction::table(): «Добавить комментарий» — действие
        // шапки таблицы ленты, а не действие самого компонента.
        ->callAction(TestAction::make('create')->table(), data: ['body' => 'Клиент перезвонит завтра'])
        ->assertHasNoActionErrors();

    $comment = LeadComment::query()->sole();

    // Автор проставляется сервером и в форме не редактируется: поле выбора
    // автора означало бы возможность подписать чужим именем.
    expect($comment->body)->toBe('Клиент перезвонит завтра')
        ->and($comment->user_id)->toBe($this->manager->getKey())
        ->and($comment->lead_id)->toBe($lead->getKey());
});

it('lets a manager delete only their own comments', function () {
    $lead = Lead::factory()->general()->create();

    $own = LeadComment::factory()->for($lead)->create(['user_id' => $this->manager->getKey()]);
    $foreign = LeadComment::factory()->for($lead)->create([
        'user_id' => User::factory()->create(['role' => UserRole::Manager])->getKey(),
    ]);

    $manager = livewire(CommentsRelationManager::class, [
        'ownerRecord' => $lead,
        'pageClass' => ViewLead::class,
    ]);

    // Лента работы с заявкой — журнал, а не документ: чужую запись
    // менеджер не убирает.
    $manager->assertActionVisible(TestAction::make('delete')->table($own));
    $manager->assertActionHidden(TestAction::make('delete')->table($foreign));
});

it('lets an administrator delete any comment', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    $lead = Lead::factory()->general()->create();
    $foreign = LeadComment::factory()->for($lead)->create([
        'user_id' => $this->manager->getKey(),
    ]);

    livewire(CommentsRelationManager::class, [
        'ownerRecord' => $lead,
        'pageClass' => ViewLead::class,
    ])->assertActionVisible(TestAction::make('delete')->table($foreign));
});

it('counts new leads in the navigation badge', function () {
    Lead::factory()->general()->count(3)->create();
    Lead::factory()->general()->inProgress()->create();
    Lead::factory()->general()->closed()->create();

    expect(LeadResource::getNavigationBadge())->toBe('3');
});

it('does not grow its query count with the list', function () {
    $cars = Car::factory()->count(5)->create();

    foreach ($cars as $car) {
        Lead::factory()->forCar($car)->create();
    }

    $read = fn (): int => countQueries(function (): void {
        foreach (LeadResource::getEloquentQuery()->get() as $lead) {
            // Подпись автомобиля собирается из марки — без `morphWith()`
            // это запрос к `brands` на каждую строку поверх остальных.
            $lead->sourceLabel();
        }
    });

    $small = $read();

    foreach ($cars as $car) {
        Lead::factory()->forCar($car)->create();
    }

    $large = $read();

    // Нижняя граница обязательна (правило `RULES.md`): выборка, не поймавшая
    // ни одного запроса, иначе проходит вхолостую.
    expect($small)->toBeGreaterThan(0)
        ->and($large)->toBe($small);
});
