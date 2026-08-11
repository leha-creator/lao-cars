<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * Страница профиля сотрудника с настройками уведомлений (веха 4.7).
 *
 * Наследует штатную страницу Filament, а не заменяет её. Штатные поля
 * (имя, e-mail, пароль с подтверждением текущим) приходят ВЫЗОВОМ
 * родительской схемы, а не переписываются заново: список компонентов
 * в апстриме меняется от версии к версии, и скопированный сюда он
 * однажды потеряет поле — молча и без единой красной проверки. Дороже
 * всего потерялось бы подтверждение текущим паролем: страница осталась бы
 * рабочей, а сменить чужой пароль стало бы можно из чужой открытой сессии.
 *
 * Политики у страницы нет и не нужно — тот же случай, что и у штатной
 * версии: `Page::canAccess()` пускает любого аутентифицированного,
 * а править отсюда можно только себя.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        $schema = parent::form($schema);

        return $schema->components([
            ...$schema->getComponents(withActions: false),
            $this->notificationsSection(),
        ]);
    }

    /**
     * Секция «Уведомления».
     *
     * Оба переключателя влияют не на отображение, а на ВЫБОРКУ получателей
     * (`User::receivingPush()`, `User::receivingTelegram()`), поэтому
     * за ними стоят колонки, а не ключ jsonb.
     */
    protected function notificationsSection(): Section
    {
        return Section::make('Уведомления')
            ->description('Куда присылать сообщения о новых заявках. Заявка сохраняется в любом случае — настройки влияют только на то, узнаете ли вы о ней сразу.')
            ->schema([
                Toggle::make('notify_push')
                    ->label('Уведомления в браузере')
                    ->helperText('Всплывающее уведомление на этом и других устройствах, где вы разрешите их показ.'),

                // Блок устройств (задача 16) стоит сразу под переключателем
                // браузерных уведомлений: включённый флаг без разрешения
                // браузера не даёт ничего, и объяснить это можно только
                // рядом с самим флагом.
                $this->pushDevicesComponent(),

                Toggle::make('notify_telegram')
                    ->label('Уведомления в Telegram')
                    ->live()
                    ->helperText('Личное сообщение от бота с полным содержанием заявки.'),

                TextInput::make('telegram_chat_id')
                    ->label('Telegram chat ID')
                    ->helperText('Узнать свой ID можно у бота @userinfobot. Пока поле пустое, уведомления в Telegram вам не приходят.')
                    ->maxLength(64)
                    // Только цифры, возможен ведущий минус: у групповых
                    // чатов id отрицательный, и маска «только цифры»
                    // отсекла бы их молча.
                    ->rule('regex:/^-?\d+$/')
                    ->validationMessages([
                        'regex' => 'Telegram chat ID — это число, иногда с минусом впереди.',
                    ])
                    // Ветвление по состоянию без приведения к строке:
                    // приведение состояния в схемах Filament уже роняло
                    // страницу проекта (правило `RULES.md` про enum),
                    // и привычка приводить «на всякий случай» вредна
                    // и здесь — `$get()` отдаёт булево значение как есть.
                    ->visible(fn (Get $get): bool => (bool) $get('notify_telegram')),
            ]);
    }

    /**
     * Список подписанных устройств и кнопка разрешения.
     *
     * `viewData` получает ЗАМЫКАНИЕ, а не готовый массив: список меняется
     * прямо на этой странице (подписались, отозвали), и вычисленный один
     * раз он показывал бы состояние до действия — человек нажимал бы
     * «Отозвать» второй раз на уже удалённой строке.
     *
     * Выборка живёт здесь, а не в шаблоне: запросы к базе из Blade
     * запрещены `ARCHITECTURE.md`, и панель из этого правила не изъята.
     */
    protected function pushDevicesComponent(): Component
    {
        return View::make('filament.push-devices')
            ->viewData(fn (): array => [
                'devices' => $this->getUser()
                    ->pushSubscriptions()
                    ->latest()
                    ->get(),
            ]);
    }

    /**
     * Отозвать подписку из списка устройств.
     *
     * Фильтр по отношению обязателен: id приходит из разметки, то есть
     * из-под контроля клиента, и `PushSubscription::find($id)->delete()`
     * позволил бы отозвать чужую подписку подбором числа. Отношение
     * сужает выборку до своих, и чужой id просто ничего не находит.
     */
    public function revokeDevice(int $id): void
    {
        /** @var User $user */
        $user = $this->getUser();

        $subscription = $user->pushSubscriptions()->whereKey($id)->first();

        if ($subscription === null) {
            return;
        }

        $tail = $subscription->endpointTail();
        $subscription->delete();

        // То же событие, что и в `PushSubscriptionController`, но пришедшее
        // другим путём. `origin` различает их: «я ничего не отключал»
        // разбирается только так.
        Log::channel('leads')->debug('[Push] подписка отозвана', [
            'user_id' => $user->getKey(),
            'endpoint_tail' => $tail,
            'origin' => 'profile',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        /** @var User $record */
        $before = [
            'notify_push' => (bool) $record->notify_push,
            'notify_telegram' => (bool) $record->notify_telegram,
            'telegram_filled' => filled($record->telegram_chat_id),
        ];

        $record = parent::handleRecordUpdate($record, $data);

        $this->logNotificationSettings($record, $before);

        return $record;
    }

    /**
     * След изменения настроек уведомлений.
     *
     * Без него жалоба «мне перестали приходить уведомления» неразрешима:
     * причин ровно две — отозванное разрешение браузера и снятый галкой
     * переключатель, — и различить их можно только по записи о втором.
     *
     * Персональный `chat_id` целиком в лог не пишется: это адрес личного
     * чата сотрудника. В записи только признак «заполнен / очищен».
     *
     * @param  array{notify_push: bool, notify_telegram: bool, telegram_filled: bool}  $before
     */
    private function logNotificationSettings(Model $record, array $before): void
    {
        /** @var User $record */
        $after = [
            'notify_push' => (bool) $record->notify_push,
            'notify_telegram' => (bool) $record->notify_telegram,
            'telegram_filled' => filled($record->telegram_chat_id),
        ];

        if ($before === $after) {
            return;
        }

        Log::channel('leads')->info('[Push] настройки уведомлений изменены', [
            'user_id' => $record->getKey(),
            'before' => $before,
            'after' => $after,
        ]);
    }
}
