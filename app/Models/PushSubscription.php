<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Str;
use NotificationChannels\WebPush\PushSubscription as BasePushSubscription;

/**
 * Подписка браузера на push-уведомления (веха 4.7).
 *
 * Наследует модель пакета, а не заменяет её: канал доставки резолвит класс
 * через `config('webpush.model')`, а трейт `HasPushSubscriptions` строит
 * по нему `morphMany`. Своя нужна ради двух вещей, которых у пакетной нет:
 * читаемого имени устройства для списка в кабинете и каста `last_used_at`.
 *
 * Полные ключи `public_key` и `auth_token` никогда не пишутся в лог: это
 * материал шифрования полезной нагрузки. `endpoint` — адрес доставки,
 * и в лог идёт только его хвост.
 */
final class PushSubscription extends BasePushSubscription
{
    /**
     * `user_agent` добавлен к пакетному набору: строку пишет контроллер
     * подписки из заголовка запроса.
     *
     * @var list<string>
     */
    protected $fillable = [
        'endpoint',
        'public_key',
        'auth_token',
        'content_encoding',
        'user_agent',
    ];

    /**
     * Хвост адреса доставки для логов.
     *
     * Целиком `endpoint` в лог не пишется: это адрес, по которому чужой
     * сервис доставляет сообщение конкретному человеку, и в файле лога,
     * который переживает ротацию и уезжает в бэкапы, ему не место.
     * Двенадцати символов достаточно, чтобы различить три устройства
     * одного сотрудника.
     */
    public function endpointTail(): string
    {
        return Str::substr($this->endpoint, -12);
    }

    /**
     * Имя устройства для списка в кабинете.
     *
     * Разбор `user_agent` намеренно грубый: точное определение браузера
     * — отдельная библиотека и вечная гонка со строками, которые
     * производители меняют каждый релиз. Здесь достаточно различить три
     * устройства одного человека, а не построить аналитику.
     *
     * Порядок проверок имеет значение: Edge и Opera несут в строке слово
     * Chrome, а Chrome — слово Safari. Проверка «сначала частное, потом
     * общее» — единственное, что удерживает это от вранья.
     */
    public function deviceLabel(): string
    {
        $agent = (string) $this->user_agent;

        if ($agent === '') {
            // Строка есть всегда, кроме подписок, заведённых в обход
            // формы. Честное «Неизвестное устройство» лучше пустой
            // ячейки: по пустой непонятно, сломалось что-то или нет.
            return 'Неизвестное устройство';
        }

        $browser = match (true) {
            Str::contains($agent, 'Edg/') => 'Edge',
            Str::contains($agent, ['OPR/', 'Opera']) => 'Opera',
            Str::contains($agent, 'YaBrowser') => 'Яндекс.Браузер',
            Str::contains($agent, 'Firefox') => 'Firefox',
            Str::contains($agent, 'Chrome') => 'Chrome',
            Str::contains($agent, 'Safari') => 'Safari',
            default => 'Браузер',
        };

        $platform = match (true) {
            Str::contains($agent, 'Android') => 'Android',
            Str::contains($agent, ['iPhone', 'iPad', 'iOS']) => 'iOS',
            Str::contains($agent, 'Windows') => 'Windows',
            Str::contains($agent, 'Mac OS') => 'macOS',
            Str::contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $platform === null ? $browser : $browser.' · '.$platform;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }
}
