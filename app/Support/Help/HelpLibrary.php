<?php

declare(strict_types=1);

namespace App\Support\Help;

use App\Filament\Pages\ManageSiteSettings;
use App\Filament\Resources\Brands\BrandResource;
use App\Filament\Resources\CarAttributes\CarAttributeResource;
use App\Filament\Resources\Cars\CarResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Media\MediaResource;
use App\Filament\Resources\Reviews\ReviewResource;
use App\Filament\Resources\ServiceCategories\ServiceCategoryResource;
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
 * # Что обязан знать тот, кто добавляет двадцать третью статью
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
 * **5. Снимки экрана разрешены с вехи 4.15 — по регламенту, а не
 * свободно.** До неё их не было намеренно, и то основание («снимок
 * устаревает, а переснимать не будет никто») не опровергнуто, а
 * замещено: сторожами, которые сверяют текст и папку в обе стороны,
 * съёмкой фрагмента вместо экрана целиком и таблицей актуальных снимков
 * с датами. Файлы лежат в `resources/help/screenshots/` — ВНЕ веб-корня,
 * потому что следующий экран, который попросят снять, это «Заявки»
 * с телефонами клиентов. Регламент целиком — в `docs/help-center.md`
 * и в скилле `.claude/skills/laocars-help/`.
 *
 * **6. Снимки есть у КАЖДОЙ статьи, а не у двух (веха 4.16).** Кадров
 * пятьдесят два, и покрыт ими практически каждый экран панели — то есть
 * правка любой формы почти наверняка попадает в чей-то снимок. Правя
 * панель, откройте таблицу актуальных снимков в `docs/help-center.md`
 * и посмотрите, попал ли этот экран в кадр. Опасность здесь выше, чем
 * у устаревшего абзаца: текст читают, а картинку узнают, и человек,
 * не нашедший на экране кнопку со снимка, решает, что сломался он.
 *
 * Тот самый экран «Заявки» из пункта 5 вехой 4.16 сняли — и сняли
 * на демонстрационных данных из `LeadSeeder`, заведённого ровно для
 * этого. Настоящих телефонов в кадре нет и быть не должно: файл уезжает
 * в историю репозитория навсегда.
 *
 * Порядок статей в списке — порядок объявления, и он содержателен:
 * «С чего начать» стоит первой, справочники каталога идут подряд,
 * вкладки настроек — в порядке самих вкладок, личные настройки
 * («Свой профиль и пароль») — последними.
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
                related: ['lead-processing', 'notifications-setup', 'profile-and-password'],
            ),

            new HelpArticle(
                slug: 'contacts-update',
                title: 'Обновить контакты компании',
                summary: 'Переехали, сменился телефон или график — что обойти, чтобы сайт обновился целиком.',
                section: HelpSection::Scenarios,
                gate: ManageSiteSettings::class,
                related: ['contacts-and-footer', 'first-steps'],
            ),

            new HelpArticle(
                slug: 'lead-processing',
                title: 'Работа с заявкой',
                summary: 'Откуда приходят заявки, как менять статус и оставлять комментарии.',
                section: HelpSection::Scenarios,
                gate: LeadResource::class,
                related: ['notifications-setup', 'price-list', 'car-sold'],
            ),

            new HelpArticle(
                slug: 'car-publishing',
                title: 'Добавить автомобиль в каталог',
                summary: 'Марка, обязательные поля карточки, статусы и показ на главной.',
                section: HelpSection::Scenarios,
                gate: CarResource::class,
                related: ['car-photos', 'car-attributes', 'car-brands', 'car-sold'],
            ),

            new HelpArticle(
                slug: 'car-photos',
                title: 'Фотографии автомобиля',
                summary: 'Загрузка снимков, порядок, главное фото и требования к исходникам.',
                section: HelpSection::Scenarios,
                gate: CarResource::class,
                related: ['car-publishing', 'media-library'],
            ),

            // Веха 4.16. Отдельная статья, а не раздел в «Добавить
            // автомобиль»: продажа — это сценарий, к которому приходят
            // со своим вопросом, и искать его внутри статьи про
            // заведение карточки никто не станет.
            new HelpArticle(
                slug: 'car-sold',
                title: 'Автомобиль продан',
                summary: 'Что сделать со статусом, что станет с главной и с уже пришедшими заявками.',
                section: HelpSection::Scenarios,
                gate: CarResource::class,
                related: ['car-publishing', 'lead-processing'],
            ),

            new HelpArticle(
                slug: 'price-list',
                title: 'Прайс автосервиса и запчастей',
                summary: 'Позиции, цены и «по запросу», порядок вывода и связь с формой записи.',
                section: HelpSection::Scenarios,
                gate: ServiceResource::class,
                related: ['service-pages-texts', 'lead-processing', 'service-categories'],
            ),

            new HelpArticle(
                slug: 'reviews-moderation',
                title: 'Отзывы: проверка и публикация',
                summary: 'Откуда берутся отзывы, как публиковать и где они показываются.',
                section: HelpSection::Scenarios,
                gate: ReviewResource::class,
                related: ['media-library', 'team-page', 'about-page-update'],
            ),

            new HelpArticle(
                slug: 'team-page',
                title: 'Команда на странице «О компании»',
                summary: 'Карточки сотрудников: имя, должность, фотография, порядок.',
                section: HelpSection::Scenarios,
                gate: EmployeeResource::class,
                related: ['media-library', 'reviews-moderation', 'about-page-update'],
            ),

            // Веха 4.16. Единственный сценарий, проходящий три раздела
            // панели сразу. Ключ доступа — от настроек сайта, хотя
            // статья живёт в «Сценариях работы»: раздел выбирается по
            // вопросу читателя, ключ — по разделу, который статья
            // описывает. Тот же случай, что и `contacts-update`.
            new HelpArticle(
                slug: 'about-page-update',
                title: 'Собрать страницу «О компании»',
                summary: 'Тексты, команда и отзывы: что обойти, чтобы страница выглядела целиком.',
                section: HelpSection::Scenarios,
                gate: ManageSiteSettings::class,
                related: ['about-page-texts', 'team-page', 'reviews-moderation'],
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
                related: ['lead-processing', 'first-steps', 'profile-and-password'],
            ),

            new HelpArticle(
                slug: 'car-attributes',
                title: 'Характеристики автомобилей',
                summary: 'Справочник характеристик, типы значений, показ в карточке и в фильтре.',
                section: HelpSection::Settings,
                gate: CarAttributeResource::class,
                related: ['car-publishing', 'car-brands'],
            ),

            // Веха 4.16. До неё про марки было одно предложение внутри
            // статьи про заведение автомобиля — то есть человек с
            // вопросом «как завести марку» искал статью про марки
            // и не находил её.
            new HelpArticle(
                slug: 'car-brands',
                title: 'Марки автомобилей',
                summary: 'Справочник марок: зачем он, как добавить марку и что будет при переименовании.',
                section: HelpSection::Settings,
                gate: BrandResource::class,
                related: ['car-publishing', 'car-attributes'],
            ),

            new HelpArticle(
                slug: 'home-blocks',
                title: 'Блоки главной страницы',
                summary: 'Бегущая строка, промо-баннер, преимущества, этапы покупки, состав цены и вопросы.',
                section: HelpSection::Settings,
                gate: ManageSiteSettings::class,
                related: ['media-library', 'seo-defaults', 'about-page-texts'],
            ),

            new HelpArticle(
                slug: 'service-pages-texts',
                title: 'Тексты автосервиса и запчастей',
                summary: 'Вступления, оговорка о ценах, условия поставки и блок «почему сюда».',
                section: HelpSection::Settings,
                gate: ManageSiteSettings::class,
                related: ['price-list', 'service-categories'],
            ),

            // Веха 4.16. Справочник вехи 4.13 разбирался внутри статьи
            // про прайс — то есть в «Сценариях работы», куда за
            // описанием полей не ходят.
            new HelpArticle(
                slug: 'service-categories',
                title: 'Категории услуг',
                summary: 'Блоки прайса: название, страница, описание, порядок и что будет при удалении.',
                section: HelpSection::Settings,
                gate: ServiceCategoryResource::class,
                related: ['price-list', 'service-pages-texts'],
            ),

            // Веха 4.16. Седьмая вкладка настроек сайта — единственная,
            // про которую не было ни строки, при том что в ней лежит
            // самое неочевидное поле всех настроек: история по годам.
            new HelpArticle(
                slug: 'about-page-texts',
                title: 'Тексты страницы «О компании»',
                summary: 'Заголовок, вступление, миссия и история по годам.',
                section: HelpSection::Settings,
                gate: ManageSiteSettings::class,
                related: ['about-page-update', 'home-blocks'],
            ),

            new HelpArticle(
                slug: 'contacts-and-footer',
                title: 'Контакты, соцсети и подвал',
                summary: 'Где показываются телефон, адрес и ссылки на мессенджеры.',
                section: HelpSection::Settings,
                gate: ManageSiteSettings::class,
                related: ['contacts-update', 'home-blocks'],
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
                related: ['first-steps', 'notifications-setup', 'profile-and-password'],
            ),

            // Веха 4.16. Ключа доступа нет намеренно: свой профиль
            // правит любой вошедший сотрудник — `Page::canAccess()`
            // штатной страницы Filament пускает всех аутентифицированных,
            // и второй матрицы прав здесь не заводится.
            //
            // Последней в разделе: настройки сайта общие, а эта —
            // личная, и от соседства с ними отличается сильнее, чем
            // они друг от друга.
            new HelpArticle(
                slug: 'profile-and-password',
                title: 'Свой профиль и пароль',
                summary: 'Имя и почта, смена пароля и устройства, на которые приходят уведомления.',
                section: HelpSection::Settings,
                related: ['notifications-setup', 'first-steps', 'staff-and-roles'],
            ),
        ];
    }
}
