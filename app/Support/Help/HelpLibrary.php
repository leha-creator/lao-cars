<?php

declare(strict_types=1);

namespace App\Support\Help;

use App\Filament\Pages\ManageSiteSettings;
use App\Filament\Resources\CarAttributes\CarAttributeResource;
use App\Filament\Resources\Cars\CarResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Media\MediaResource;
use App\Filament\Resources\Reviews\ReviewResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Users\UserResource;

/**
 * Реестр статей справки: что есть, как называется и с чем связано.
 *
 * Живёт в `app/Support/`, а не в сервисе, по правилу зависимостей из
 * `ARCHITECTURE.md`: здесь чистые правила над данными — ни диска, ни
 * авторизации, ни запросов. Текст статьи читает `App\Services\HelpContent`,
 * он же спрашивает у ключа доступа, показывать ли статью.
 *
 * # Что обязан знать тот, кто добавляет шестнадцатую статью
 *
 * **1. В тексте статьи нет технических подробностей — это правило,
 * а не пожелание.** Запрещены: artisan-команды, переменные окружения,
 * имена файлов, таблиц, классов и ключей настроек, коды HTTP, очереди,
 * логи, названия пакетов. Всё называется тем, что человек видит
 * на экране: пункт меню, подпись поля, текст кнопки. Причина не
 * в снисходительности к читателю: техническая подробность в справке —
 * это второй источник истины про устройство системы, который расходится
 * с `docs/` и с кодом, а замечает расхождение тот, кто пришёл
 * за инструкцией. Диагностика формулируется как «что делать» и «когда
 * звать разработчика».
 *
 * **2. Статьи не ссылаются друг на друга внутри текста.** Относительная
 * ссылка из markdown резолвится от текущего адреса и ломается от лишнего
 * слеша, а абсолютная зашивает слаг в текст, где его не найдёт ни один
 * сторож. Перекрёстные связи объявляются полем `related` и печатаются
 * блоком «См. также»: переименованный слаг роняет тест, а не страницу.
 * Ссылки на внешний мир (публичный сайт, сторонние сервисы) в тексте
 * разрешены — они не про наши слаги.
 *
 * **3. В файле статьи нет заголовка первого уровня.** Заголовок печатает
 * сама страница панели, и `#` в файле дал бы его второй раз. Статьи
 * начинаются с `##`. Ошибка рефлекторная — текст в `docs/` начинается
 * именно с `#`, — поэтому на неё стоит сторож в тестах.
 *
 * **4. Правка панели правит статью тем же коммитом.** Справка описывает
 * поведение кода, и автоматического сторожа у их согласованности нет
 * и быть не может. Инструкция, отставшая на две вехи, вреднее её
 * отсутствия, потому что ей верят. Подробности — в `docs/help-center.md`.
 *
 * Порядок статей в списке — порядок объявления, и он содержателен:
 * «С чего начать» стоит первой.
 */
final class HelpLibrary
{
    /**
     * @return list<HelpArticle>
     */
    public static function all(): array
    {
        return self::build();
    }

    public static function find(string $slug): ?HelpArticle
    {
        foreach (self::all() as $article) {
            if ($article->slug === $slug) {
                return $article;
            }
        }

        return null;
    }

    /**
     * @return list<HelpArticle>
     */
    public static function inSection(HelpSection $section): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (HelpArticle $article): bool => $article->section === $section,
        ));
    }

    /**
     * @return list<HelpArticle>
     */
    private static function build(): array
    {
        return [
            new HelpArticle(
                slug: 'first-steps',
                title: 'С чего начать',
                summary: 'Вход в панель, из чего состоит меню, чем администратор отличается от менеджера.',
                section: HelpSection::Scenarios,
                related: ['lead-processing', 'notifications-setup'],
            ),

            new HelpArticle(
                slug: 'lead-processing',
                title: 'Работа с заявкой',
                summary: 'Откуда приходят заявки, как менять статус и оставлять комментарии.',
                section: HelpSection::Scenarios,
                gate: LeadResource::class,
                related: ['notifications-setup', 'price-list'],
            ),

            new HelpArticle(
                slug: 'car-publishing',
                title: 'Добавить автомобиль в каталог',
                summary: 'Марка, обязательные поля карточки, статусы и показ на главной.',
                section: HelpSection::Scenarios,
                gate: CarResource::class,
                related: ['car-photos', 'car-attributes'],
            ),

            new HelpArticle(
                slug: 'car-photos',
                title: 'Фотографии автомобиля',
                summary: 'Загрузка снимков, порядок, главное фото и требования к исходникам.',
                section: HelpSection::Scenarios,
                gate: CarResource::class,
                related: ['car-publishing', 'media-library'],
            ),

            new HelpArticle(
                slug: 'price-list',
                title: 'Прайс автосервиса и запчастей',
                summary: 'Позиции, цены и «по запросу», порядок вывода и связь с формой записи.',
                section: HelpSection::Scenarios,
                gate: ServiceResource::class,
                related: ['service-pages-texts', 'lead-processing'],
            ),

            new HelpArticle(
                slug: 'reviews-moderation',
                title: 'Отзывы: проверка и публикация',
                summary: 'Откуда берутся отзывы, как публиковать и где они показываются.',
                section: HelpSection::Scenarios,
                gate: ReviewResource::class,
                related: ['media-library', 'team-page'],
            ),

            new HelpArticle(
                slug: 'team-page',
                title: 'Команда на странице «О компании»',
                summary: 'Карточки сотрудников: имя, должность, фотография, порядок.',
                section: HelpSection::Scenarios,
                gate: EmployeeResource::class,
                related: ['media-library', 'reviews-moderation'],
            ),

            new HelpArticle(
                slug: 'media-library',
                title: 'Медиабиблиотека',
                summary: 'Зачем нужна библиотека, чем отличается от фотографий автомобиля и почему файл не удаляется.',
                section: HelpSection::Scenarios,
                gate: MediaResource::class,
                related: ['car-photos', 'home-blocks'],
            ),

            new HelpArticle(
                slug: 'notifications-setup',
                title: 'Уведомления о новых заявках',
                summary: 'Три канала уведомлений, как включить каждый и что делать, если не приходят.',
                section: HelpSection::Settings,
                related: ['lead-processing', 'first-steps'],
            ),

            new HelpArticle(
                slug: 'car-attributes',
                title: 'Характеристики автомобилей',
                summary: 'Справочник характеристик, типы значений, показ в карточке и в фильтре.',
                section: HelpSection::Settings,
                gate: CarAttributeResource::class,
                related: ['car-publishing'],
            ),

            new HelpArticle(
                slug: 'home-blocks',
                title: 'Блоки главной страницы',
                summary: 'Бегущая строка, промо-баннер, преимущества, этапы покупки, состав цены и вопросы.',
                section: HelpSection::Settings,
                gate: ManageSiteSettings::class,
                related: ['media-library', 'seo-defaults'],
            ),

            new HelpArticle(
                slug: 'service-pages-texts',
                title: 'Тексты автосервиса и запчастей',
                summary: 'Вступления, описания категорий прайса, оговорка о ценах и условия поставки.',
                section: HelpSection::Settings,
                gate: ManageSiteSettings::class,
                related: ['price-list'],
            ),

            new HelpArticle(
                slug: 'contacts-and-footer',
                title: 'Контакты, соцсети и подвал',
                summary: 'Где показываются телефон, адрес и ссылки на мессенджеры.',
                section: HelpSection::Settings,
                gate: ManageSiteSettings::class,
                related: ['home-blocks'],
            ),

            new HelpArticle(
                slug: 'seo-defaults',
                title: 'Заголовки и описания для поиска',
                summary: 'Что видит посетитель в результатах поиска и где эти тексты перебиваются.',
                section: HelpSection::Settings,
                gate: ManageSiteSettings::class,
                related: ['car-publishing', 'home-blocks'],
            ),

            new HelpArticle(
                slug: 'staff-and-roles',
                title: 'Сотрудники и роли',
                summary: 'Как завести сотрудника, что видит менеджер и почему свою роль изменить нельзя.',
                section: HelpSection::Settings,
                gate: UserResource::class,
                related: ['first-steps', 'notifications-setup'],
            ),
        ];
    }
}
