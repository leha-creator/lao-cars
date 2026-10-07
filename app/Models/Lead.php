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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Заявка со всех форм сайта.
 *
 * Единая сущность с полиморфной привязкой к источнику (автомобиль,
 * услуга или общая форма) — прямое следствие требования «единый список
 * заявок со всех форм с указанием источника» из раздела 4 ТЗ.
 *
 * Приём заявок, валидация и уведомления — веха 3.7; здесь только данные.
 *
 * **Срок хранения.** Закрытая заявка хранится `config('leads.retention_years')`
 * лет с даты закрытия и затем обезличивается (`anonymize()`), а не удаляется:
 * строка остаётся для статистики, всё, что указывает на человека, стирается.
 * Тот же срок назван в разделе 9 политики обработки персональных данных —
 * это одно знание в двух местах. Удаление заявки целиком остаётся для
 * отзыва согласия.
 *
 * `closed_at` и `anonymized_at` в `#[Fillable]` не входят намеренно: первую
 * ведёт событие `saving` по статусу, вторую ставит только `anonymize()`.
 * Массовое присваивание позволило бы завести заявку «уже обезличенной»
 * мимо единственного места, где это происходит.
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
    // Согласие на обработку персональных данных (веха 4.17). `null`
    // в колонках означает «согласие через форму сайта не давалось»:
    // так выглядят заявки до вехи и всё, что заведено из консоли.
    // Редакций две, потому что документов два: политика и текст согласия.
    'consented_at',
    'consent_policy_version',
    'consent_text_version',
])]
final class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    /**
     * Имя, которое остаётся у обезличенной заявки.
     *
     * Колонка `name` — `NOT NULL`, и менять схему ради обезличивания
     * незачем: заглушка честнее `null`, потому что её видит человек
     * в списке заявок и сразу понимает, что это не пустое поле формы.
     */
    public const string ANONYMIZED_NAME = 'Обезличено';

    protected static function booted(): void
    {
        // Дата закрытия ведётся здесь, а не в действии смены статуса:
        // статус меняют ещё фабрики, сиды и консоль, и заявка, закрытая
        // мимо действия, осталась бы без даты — то есть никогда не попала
        // бы в отбор «срок хранения истёк». Возврат в работу дату
        // сбрасывает: срок считается от ПОСЛЕДНЕГО закрытия.
        self::saving(static function (self $lead): void {
            if ($lead->status !== LeadStatus::Closed) {
                $lead->closed_at = null;

                return;
            }

            if ($lead->closed_at === null) {
                $lead->closed_at = now();
            }
        });

        // Каскад базы уносит комментарии, но не уведомления колокольчика:
        // в них лежат имя и телефон клиента (`NewLeadNotification::toDatabase()`),
        // и после удаления заявки по отзыву согласия они остались бы в базе.
        // Хук, а не действие панели: заявку удаляют и строкой списка, и из
        // консоли, и забытый путь оставил бы данные лежать.
        self::deleting(static function (self $lead): void {
            $notifications = $lead->forgetPanelNotifications();

            // Имени и телефона в записи нет — запрет шапки канала `leads`.
            // Запись служит основанием для акта об уничтожении.
            Log::channel('leads')->info('[Lead] заявка удалена', [
                'lead_id' => $lead->getKey(),
                'actor_id' => auth()->id() ?? 'console',
                'notifications_removed' => $notifications,
            ]);
        });
    }

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
     * Закрытые заявки, срок хранения которых истёк и которые ещё не
     * обезличены.
     *
     * Условие одно на вкладку списка и на само действие: срок проверяет
     * сервер, а не то, что отмечено в таблице.
     */
    #[Scope]
    protected function retentionExpired(Builder $query): void
    {
        $query
            ->where('status', LeadStatus::Closed)
            ->whereNull('anonymized_at')
            ->where('closed_at', '<=', now()->subYears((int) config('leads.retention_years')));
    }

    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }

    public function isRetentionExpired(): bool
    {
        return $this->status === LeadStatus::Closed
            && ! $this->isAnonymized()
            && $this->closed_at !== null
            && $this->closed_at->lessThanOrEqualTo(now()->subYears((int) config('leads.retention_years')));
    }

    /**
     * Обезличить заявку: стереть всё, что указывает на человека.
     *
     * Уходят имя, телефон, почта, текст обращения и VIN, а с ними — копия
     * в уведомлениях панели. Остаются даты, статус, источник, адрес
     * страницы, способ связи, удобное время, марка и модель из формы
     * запчастей, сведения о согласии: по ним человека не определить,
     * а статистика заявок без них теряет смысл.
     *
     * Комментарии сотрудников НЕ удаляются — решение заказчика: история
     * работы по заявке нужна и после срока хранения. Следствие записано
     * в регламенте (`docs/leads.md`): в комментариях не пишут имя, телефон
     * и иные данные клиента.
     *
     * Срок здесь не проверяется: метод отвечает на вопрос «как», а «когда»
     * решает вызывающий (`isRetentionExpired()`), иначе обезличить заявку
     * по обращению субъекта раньше срока было бы нечем.
     *
     * @return bool `false`, если заявка уже обезличена и ничего не менялось
     */
    public function anonymize(): bool
    {
        if ($this->isAnonymized()) {
            return false;
        }

        DB::transaction(function (): void {
            $this->name = self::ANONYMIZED_NAME;
            $this->phone = '';
            $this->email = null;
            $this->message = null;
            $this->part_vin = null;
            $this->anonymized_at = now();
            $this->save();

            $this->forgetPanelNotifications();
        });

        return true;
    }

    /**
     * Убрать уведомления колокольчика панели об этой заявке.
     *
     * Строкой, а не числом в привязке: `data->lead_id` в PostgreSQL
     * разворачивается в `->>`, то есть в текст, и сравнение текста
     * с целым параметром драйвер отклоняет.
     *
     * @return int сколько строк убрано
     */
    public function forgetPanelNotifications(): int
    {
        return DB::table('notifications')
            ->where('data->lead_id', (string) $this->getKey())
            ->delete();
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
            'consented_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'anonymized_at' => 'immutable_datetime',
        ];
    }
}
