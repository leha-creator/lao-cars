<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ContactMethod;
use App\Enums\LeadStatus;
use App\Enums\PreferredTime;
use App\Models\Car;
use App\Models\Lead;
use App\Models\LeadComment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Демонстрационные заявки — веха 4.16.
 *
 * Заведён ради снимков экрана в справке. «Заявки» — единственный экран
 * панели, где в кадр попадают имена и телефоны, и регламент снимков
 * (`.claude/skills/laocars-help/references/screenshots.md`) запрещает
 * снимать там реальные данные клиентов: файл уезжает в историю
 * репозитория навсегда. Без сида кадр снять не с чего — заявки
 * приходят только с форм сайта.
 *
 * **Значения записаны явно, а не взяты у фабрики.** `LeadFactory`
 * отдаёт `fake()->name()` и случайный статус, то есть каждый прогон
 * даёт другой список — а регламент требует уметь ПЕРЕСНЯТЬ экран
 * так, чтобы новый кадр отличался от старого только тем, что
 * поменялось в панели. Фабрика годится для тестов, где важна
 * произвольность, и не годится здесь, где важна повторяемость.
 *
 * Четыре заявки, а не одна: на экране показаны все три статуса и три
 * из четырёх источников. Вкладки «Новые» и «В работе» с одной
 * заявкой в каждой выглядят как пустая панель.
 *
 * Вызывается только вне production — см. `DatabaseSeeder`, там же
 * и по той же причине заводятся демо-пользователи.
 */
class LeadSeeder extends Seeder
{
    /**
     * @var list<array{
     *     name: string,
     *     phone: string,
     *     email: ?string,
     *     message: ?string,
     *     contact_method: ContactMethod,
     *     preferred_time: PreferredTime,
     *     status: LeadStatus,
     *     page_url: string,
     *     source: 'car'|'service'|null,
     *     part: ?array{brand: string, model: string, vin: string},
     *     comment: ?string
     * }>
     */
    private const LEADS = [
        [
            'name' => 'Артём Ковалёв',
            'phone' => '+7 914 555-01-24',
            'email' => 'kovalev.demo@example.com',
            'message' => 'Интересует этот автомобиль. Можно посмотреть в субботу?',
            'contact_method' => ContactMethod::Phone,
            'preferred_time' => PreferredTime::Day,
            'status' => LeadStatus::New,
            'page_url' => '/catalog',
            'source' => 'car',
            'part' => null,
            'comment' => null,
        ],
        [
            'name' => 'Марина Соболева',
            'phone' => '+7 924 555-08-70',
            'email' => null,
            'message' => 'Нужно записаться на ближайшую неделю.',
            'contact_method' => ContactMethod::WhatsApp,
            'preferred_time' => PreferredTime::Morning,
            'status' => LeadStatus::InProgress,
            'page_url' => '/service',
            'source' => 'service',
            'part' => null,
            'comment' => 'Позвонил, договорились на четверг 10:00. Ждёт подтверждения по стоимости.',
        ],
        [
            'name' => 'Игорь Пантелеев',
            'phone' => '+7 902 555-33-19',
            'email' => 'panteleev.demo@example.com',
            'message' => 'Нужен передний бампер, в наличии нигде не нашёл.',
            'contact_method' => ContactMethod::Telegram,
            'preferred_time' => PreferredTime::Evening,
            'status' => LeadStatus::New,
            'page_url' => '/parts',
            'source' => null,
            'part' => [
                'brand' => 'Zeekr',
                'model' => '001',
                'vin' => 'LVVDB11B7NDXXXXXX',
            ],
            'comment' => null,
        ],
        [
            'name' => 'Елена Гордеева',
            'phone' => '+7 908 555-46-02',
            'email' => 'gordeeva.demo@example.com',
            'message' => 'Здравствуйте! Подскажите, работаете ли вы в выходные.',
            'contact_method' => ContactMethod::Phone,
            'preferred_time' => PreferredTime::Day,
            'status' => LeadStatus::Closed,
            'page_url' => '/contacts',
            'source' => null,
            'part' => null,
            'comment' => 'Ответил по телефону, вопрос закрыт.',
        ],
    ];

    public function run(): void
    {
        $car = Car::query()->oldest('id')->first();
        $service = Service::query()->oldest('id')->first();
        $manager = User::query()->where('email', 'manager@example.com')->first();

        foreach (self::LEADS as $row) {
            $lead = new Lead;

            $lead->fill([
                'name' => $row['name'],
                'phone' => $row['phone'],
                'email' => $row['email'],
                'message' => $row['message'],
                'contact_method' => $row['contact_method'],
                'preferred_time' => $row['preferred_time'],
                'status' => $row['status'],
                'page_url' => $row['page_url'],
                'part_brand' => $row['part']['brand'] ?? null,
                'part_model' => $row['part']['model'] ?? null,
                'part_vin' => $row['part']['vin'] ?? null,
            ]);

            // Источник привязывается отношением, а не парой колонок:
            // при пустом каталоге заявка остаётся заявкой с общей формы,
            // а не ссылкой в никуда.
            $source = match ($row['source']) {
                'car' => $car,
                'service' => $service,
                default => null,
            };

            if ($source !== null) {
                $lead->source()->associate($source);
            }

            $lead->save();

            if ($row['comment'] !== null && $manager !== null) {
                LeadComment::query()->create([
                    'lead_id' => $lead->id,
                    'user_id' => $manager->id,
                    'body' => $row['comment'],
                ]);
            }
        }
    }
}
