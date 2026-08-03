# Lead: модель, миграция, Filament-ресурс

## Миграция

Полиморфные колонки nullable — общая форма обратной связи приходит без источника.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('phone', 32);
            $table->string('email')->nullable();
            $table->text('message')->nullable();

            // Источник: Car, Service или null (общая форма)
            $table->nullableMorphs('source');

            $table->string('status')->default('new')->index();
            $table->string('page_url')->nullable();

            $table->timestamps();

            // Менеджер работает со списком «новые сверху» — индекс под эту выборку
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
```

`nullableMorphs('source')` создаёт `source_type` + `source_id` и составной индекс по ним.

### Комментарии менеджера

Отдельная таблица — в ТЗ требуется «возможность оставлять комментарии», во множественном
числе, с историей и авторством.

```php
Schema::create('lead_comments', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->restrictOnDelete();
    $table->text('body');
    $table->timestamps();
});
```

## Модель

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class Lead extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'phone', 'email', 'message',
        'source_type', 'source_id', 'status', 'page_url',
    ];

    protected $casts = [
        'status' => LeadStatus::class,
    ];

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(LeadComment::class);
    }

    public function scopeNew(Builder $query): Builder
    {
        return $query->where('status', LeadStatus::New);
    }

    /** Человекочитаемый источник для админки и уведомлений. */
    public function sourceLabel(): string
    {
        return match (true) {
            $this->source instanceof Car     => "Авто: {$this->source->brand} {$this->source->model}",
            $this->source instanceof Service => "Услуга: {$this->source->title}",
            default                          => 'Общая форма',
        };
    }
}
```

## Enum статуса

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New        => 'Новая',
            self::InProgress => 'В работе',
            self::Closed     => 'Закрыта',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New        => 'warning',
            self::InProgress => 'info',
            self::Closed     => 'success',
        };
    }
}
```

## Обратная связь от источников

Чтобы из карточки авто было видно её заявки:

```php
// App\Models\Car
public function leads(): MorphMany
{
    return $this->morphMany(Lead::class, 'source');
}
```

Аналогично в `Service`. Ограничить типы источников на уровне приложения:

```php
// App\Providers\AppServiceProvider::boot()
Relation::enforceMorphMap([
    'car'     => Car::class,
    'service' => Service::class,
]);
```

Morph map фиксирует в БД короткие алиасы вместо FQCN — переименование или перенос класса
не ломает существующие строки.

## Filament-ресурс

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\LeadStatus;
use App\Models\Lead;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class LeadResource extends Resource
{
    protected static ?string $model = Lead::class;
    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';
    protected static ?string $navigationLabel = 'Заявки';
    protected static ?string $modelLabel = 'Заявка';
    protected static ?string $pluralModelLabel = 'Заявки';

    /** Счётчик новых заявок в меню — менеджер видит их не заходя в раздел. */
    public static function getNavigationBadge(): ?string
    {
        return (string) Lead::query()->new()->count() ?: null;
    }

    public static function table(Table $table): Table
    {
        return $table
            // Без eager loading полиморфной связи список даёт N+1
            ->modifyQueryUsing(fn ($query) => $query->with('source'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')->label('Клиент')->searchable(),
                Tables\Columns\TextColumn::make('phone')->label('Телефон')->searchable()->copyable(),

                Tables\Columns\TextColumn::make('source')
                    ->label('Источник')
                    ->state(fn (Lead $record): string => $record->sourceLabel()),

                Tables\Columns\SelectColumn::make('status')
                    ->label('Статус')
                    ->options(collect(LeadStatus::cases())
                        ->mapWithKeys(fn (LeadStatus $s) => [$s->value => $s->label()])
                        ->all()),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options(collect(LeadStatus::cases())
                        ->mapWithKeys(fn (LeadStatus $s) => [$s->value => $s->label()])
                        ->all()),

                Tables\Filters\SelectFilter::make('source_type')
                    ->label('Тип источника')
                    ->options(['car' => 'Автомобиль', 'service' => 'Услуга']),
            ]);
    }
}
```

Заявки создаёт сайт, а не менеджер — у ресурса нет страницы создания:

```php
public static function canCreate(): bool
{
    return false;
}
```

## Политики доступа

Менеджер видит заявки и каталог, но не настройки сайта (раздел 4 ТЗ). Это политики, а не
скрытие пунктов меню — иначе прямой URL обходит ограничение.

```php
final class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isManager() || $user->isAdmin();
    }

    public function update(User $user, Lead $lead): bool
    {
        return $user->isManager() || $user->isAdmin();
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->isAdmin(); // менеджер не удаляет лиды
    }
}
```
