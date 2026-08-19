<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ServicePage;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Справочник категорий, прайс автосервиса и категории запчастей
 * для разработки.
 *
 * Настоящие позиции и цены поступают от заказчика (этап 5 ТЗ);
 * здесь важно, чтобы на странице автосервиса были заполнены все
 * блоки, а на посадочной запчастей — все категории.
 *
 * Описания категорий переехали сюда из `SiteSettingSeeder` вехой 4.13
 * вместе с самими категориями: до неё они лежали в настройке-объекте
 * `services_page.notes`, ключами которой были значения енама.
 *
 * ФОТОГРАФИИ ПОЗИЦИЯМ СИД НЕ ПРОСТАВЛЯЕТ, И ЭТО РЕШЕНИЕ, А НЕ ПРОПУСК.
 * Взять шесть кадров из `assets/photo` нельзя: это иллюстрации этапов
 * покупки вехи 4.9, и одна фотография в двух ролях на одном сайте
 * читается как ошибка. Пустой `media_id` — штатное состояние, и сид,
 * показывающий именно его, честнее сида с чужим кадром. Как выбрать
 * фотографию, объясняют `resources/help/price-list.md`
 * и `docs/services-and-parts.md`.
 *
 * Флаг «акцентная» при этом проставляется двум позициям: иначе новый вид
 * карточки не увидит никто, кто разворачивает проект с нуля, и дефект
 * в нём доживёт до прода.
 */
class ServiceSeeder extends Seeder
{
    /**
     * Категории: слаг => [имя, страница, описание].
     *
     * Слаг — ключ поиска, а не имя: имя правится из админки первым,
     * и `updateOrCreate` по нему завёл бы дубль вместо обновления.
     * Те же пять слагов заводит миграция вехи 4.13 на существующей базе,
     * поэтому сид обязан быть идемпотентным по ним.
     *
     * @var array<string, array{0: string, 1: ServicePage, 2: string}>
     */
    private const array CATEGORIES = [
        'maintenance' => [
            'ТО и ремонт',
            ServicePage::Services,
            'Регламентное обслуживание, диагностика и ремонт. Состав работ и стоимость согласуем до начала: клиент видит, за что платит, прежде чем автомобиль попадёт на подъёмник.',
        ],
        'tire-service' => [
            'Шиномонтаж',
            ServicePage::Services,
            'Сезонная смена, балансировка и хранение комплекта до следующего сезона — чтобы летние колёса не занимали половину балкона.',
        ],
        'detailing' => [
            'Детейлинг',
            ServicePage::Services,
            'Защита кузова и салона: керамика, полировка, химчистка. Особенно имеет смысл сразу после выдачи — пока покрытие ложится на новый лак.',
        ],
        'extra' => [
            'Дополнительные сервисы',
            ServicePage::Services,
            'То, что нужно владельцу импортного автомобиля помимо ремонта: меню на русском, оформление, обмен старого автомобиля на новый.',
        ],
        'parts' => [
            'Запчасти',
            ServicePage::Parts,
            'Подбираем оригинал и проверенные аналоги по VIN — от расходников для ТО до кузовных деталей и электрики.',
        ],
    ];

    /**
     * Слаг категории => список позиций:
     * [название, цена или null, уточнение, акцентная].
     *
     * @var array<string, list<array{0: string, 1: int|null, 2: string|null, 3: bool}>>
     */
    private const array SERVICES = [
        'maintenance' => [
            ['Плановое ТО', 6500, 'от', true],
            ['Замена масла и фильтров', 3200, null, false],
            ['Диагностика ходовой части', 2500, null, false],
            ['Ремонт гибридных установок', null, null, false],
            ['Компьютерная диагностика', 2000, null, false],
        ],
        'tire-service' => [
            ['Шиномонтаж легкового автомобиля', 1200, 'за колесо', false],
            ['Балансировка колёс', 600, 'за колесо', false],
            ['Сезонное хранение шин', 4500, 'за сезон', false],
            ['Ремонт бокового пореза', null, null, false],
        ],
        'detailing' => [
            ['Комплексная мойка', 2500, 'от', false],
            ['Полировка кузова', 12000, 'от', false],
            ['Защитное керамическое покрытие', 35000, 'от', true],
            ['Химчистка салона', 9000, 'от', false],
        ],
        'extra' => [
            ['Русификация мультимедиа', 8000, 'от', false],
            ['Трейд-ин', null, null, false],
            ['Помощь в оформлении и постановке на учёт', null, null, false],
        ],
        'parts' => [
            ['Оригинальные запчасти', null, null, false],
            ['Аналоги проверенных производителей', null, null, false],
            ['Расходники для ТО', null, null, false],
            ['Кузовные детали', null, null, false],
            ['Электрика и электроника', null, null, false],
        ],
    ];

    public function run(): void
    {
        $categories = $this->seedCategories();

        $created = 0;
        $updated = 0;

        foreach (self::SERVICES as $slug => $items) {
            foreach ($items as $index => [$title, $price, $priceNote, $isFeatured]) {
                $service = Service::updateOrCreate(
                    ['slug' => Str::slug($title)],
                    [
                        'service_category_id' => $categories[$slug],
                        'title' => $title,
                        'description' => null,
                        'details' => null,
                        'price' => $price,
                        'price_note' => $priceNote,
                        'is_featured' => $isFeatured,
                        'is_published' => true,
                        'sort_order' => $index,
                    ],
                );

                $service->wasRecentlyCreated ? $created++ : $updated++;
            }
        }

        $this->command?->info("[ServiceSeeder] позиций создано: {$created}, обновлено: {$updated}");
    }

    /**
     * Справочник категорий. Возвращает «слаг => идентификатор».
     *
     * @return array<string, int>
     */
    private function seedCategories(): array
    {
        $ids = [];
        $created = 0;
        $updated = 0;
        $sortOrder = 0;

        foreach (self::CATEGORIES as $slug => [$name, $page, $description]) {
            $category = ServiceCategory::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'page' => $page,
                    'description' => $description,
                    'sort_order' => $sortOrder++,
                ],
            );

            $category->wasRecentlyCreated ? $created++ : $updated++;

            $ids[$slug] = $category->getKey();
        }

        $this->command?->info("[ServiceSeeder] категорий создано: {$created}, обновлено: {$updated}");

        return $ids;
    }
}
