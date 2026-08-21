<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Данные для разработки, а не контент заказчика: реальные карточки,
     * прайс и тексты поступают отдельно (этап 5 ТЗ). Порядок важен —
     * автомобили опираются на справочник марок.
     *
     * Трейт `WithoutModelEvents` из скелета Laravel здесь снят
     * осознанно. Он оборачивает весь прогон в `Model::withoutEvents()`,
     * а на событиях моделей в этом проекте держится корректность:
     * `creating` генерирует slug (иначе NOT NULL на `cars.slug`),
     * `saved` сбрасывает кеш настроек (иначе сайт показывает значения
     * из предыдущего прогона). Дорогих слушателей у моделей нет.
     */
    public function run(): void
    {
        // Демо-пользователи с известным паролем и без ограничений на
        // вход в панель (см. User::canAccessPanel) на проде — открытая
        // дверь в админку, поэтому создаются только вне production.
        // Первого администратора на проде заводит `laocars:make-admin`.
        //
        // updateOrCreate, а не firstOrCreate: последний не тронул бы уже
        // существующего `test@example.com`, и тот остался бы с умолчанием
        // колонки, то есть менеджером, — потеряв доступ к настройкам
        // сайта после миграции и без единого сообщения.
        //
        // Менеджер заводится вторым не для полноты: ограниченную панель
        // надо уметь посмотреть глазами, а не только в тестах.
        if (! app()->isProduction()) {
            User::updateOrCreate(
                ['email' => 'test@example.com'],
                User::factory()->raw([
                    'name' => 'Test User',
                    'email' => 'test@example.com',
                    'role' => UserRole::Admin,
                ]),
            );

            User::updateOrCreate(
                ['email' => 'manager@example.com'],
                User::factory()->raw([
                    'name' => 'Test Manager',
                    'email' => 'manager@example.com',
                    'role' => UserRole::Manager,
                ]),
            );
        }

        $this->call([
            BrandSeeder::class,
            CarAttributeSeeder::class,
            CarSeeder::class,
            CarAttributeValueSeeder::class,
            CarPhotoSeeder::class,
            ServiceSeeder::class,
            EmployeeSeeder::class,
            ReviewSeeder::class,
            SiteSettingSeeder::class,
            // Строго после `SiteSettingSeeder`: тот пишет `home.steps`
            // и `home.trust` целиком и затёр бы проставленные здесь
            // ссылки на картинки.
            StepPhotoSeeder::class,
            ShowroomPhotoSeeder::class,
        ]);

        // Демо-заявки (веха 4.16) — только вне production, по той же
        // причине, что и демо-пользователи выше: это не контент
        // заказчика, а данные для снимков экрана в справке.
        //
        // Отдельным вызовом ПОСЛЕ основного списка, а не строкой в нём:
        // заявка привязывается к автомобилю и к услуге, а комментарий
        // менеджера — к демо-пользователю, заведённому в начале метода.
        // Внутри списка сид встал бы до своих зависимостей.
        if (! app()->isProduction()) {
            $this->call(LeadSeeder::class);
        }
    }
}
