<?php

namespace Database\Seeders;

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
        // Демо-пользователь с известным паролем и без ограничений на
        // вход в панель (см. User::canAccessPanel) на проде — открытая
        // дверь в админку, поэтому создаётся только вне production.
        // firstOrCreate вместо factory()->create(): повторный db:seed
        // не должен падать на уникальном e-mail.
        if (! app()->isProduction()) {
            User::firstOrCreate(
                ['email' => 'test@example.com'],
                User::factory()->raw(['name' => 'Test User', 'email' => 'test@example.com']),
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
        ]);
    }
}
