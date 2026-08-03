<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContactMethod;
use App\Enums\LeadStatus;
use App\Enums\PreferredTime;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Заявка со всех форм сайта.
 *
 * Единая сущность с полиморфной привязкой к источнику (автомобиль,
 * услуга или общая форма) — прямое следствие требования «единый список
 * заявок со всех форм с указанием источника» из раздела 4 ТЗ.
 *
 * Приём заявок, валидация и уведомления — веха 3.7; здесь только данные.
 */
#[Fillable([
    'name',
    'phone',
    'email',
    'message',
    'contact_method',
    'preferred_time',
    'part_brand',
    'part_model',
    'part_vin',
    'source_type',
    'source_id',
    'status',
    'page_url',
])]
final class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(LeadComment::class);
    }

    #[Scope]
    protected function new(Builder $query): void
    {
        $query->where('status', LeadStatus::New);
    }

    #[Scope]
    protected function unclosed(Builder $query): void
    {
        $query->whereIn('status', [LeadStatus::New, LeadStatus::InProgress]);
    }

    /**
     * Человекочитаемый источник — для списка заявок в админке и для
     * текста Telegram-уведомления (веха 3.7).
     */
    public function sourceLabel(): string
    {
        $source = $this->source;

        return match (true) {
            $source instanceof Car => trim(sprintf(
                'Авто: %s %s',
                $source->brand?->name ?? '',
                $source->model,
            )),
            $source instanceof Service => "Услуга: {$source->title}",
            default => 'Общая форма',
        };
    }

    /**
     * Заявка на подбор запчасти: отличается от прочих не источником,
     * а заполненными полями автомобиля клиента.
     */
    public function isPartsRequest(): bool
    {
        return filled($this->part_brand) || filled($this->part_vin);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'contact_method' => ContactMethod::class,
            'preferred_time' => PreferredTime::class,
        ];
    }
}
