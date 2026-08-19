<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EngineType;
use App\Enums\ServicePage;
use App\Models\Car;
use App\Models\Media;
use App\Models\Review;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;

/**
 * Данные главной страницы (вехи 4.2 и 4.6).
 *
 * `ARCHITECTURE.md` разрешает контроллеру читать модель напрямую, когда
 * речь про чистое чтение и прослойка была бы пустым файлом. Здесь она
 * пустой не будет: на главной шесть источников — подборка авто, три ключа
 * настроек с jsonb-значениями разной формы, разрешение `image_id` промо
 * в URL медиабиблиотеки и SEO-заголовки. Есть что оркестрировать и есть
 * что нормализовать. Прецедент в проекте точный — `CatalogFilterOptions`:
 * так же собирает данные одной страницы в массив и так же не принимает
 * `Request`, поэтому блоки главной проверяются без HTTP.
 *
 * Вехой 4.6 к ним добавились ещё шесть: три репитера макета v2 (этапы,
 * состав цены, FAQ), отзывы, витрина прайса и данные быстрого подбора.
 * Последние две — как раз то, ради чего сервис и существует: витрина
 * группируется и усекается в PHP из одного запроса, а подбор считает
 * границы бюджета по каталогу, а не по числам из макета.
 *
 * Нормализация здесь, а не в шаблоне, по одной причине: форма настроек
 * (веха 3.5) пишет свои ключи безусловно, даже пустыми — «очистить блок»
 * там рабочий сценарий, — а репитер при удалении всех элементов отдаёт
 * `null`, а не `[]`. Шаблон, написанный по сиду, падает на первом же
 * `foreach (null)`, и падает на проде.
 */
final class HomeContent
{
    /**
     * Порог, после которого выборка быстрого подбора становится дороже
     * остального документа.
     *
     * Массив автомобилей уезжает в разметку целиком (решение 4 вехи 4.6):
     * это цена честного счётчика без запроса к серверу на каждый сдвиг
     * ползунка. На пяти сотнях записей узкая выборка — это ещё десятки
     * килобайт, дальше блок пора переводить на отдельный endpoint.
     *
     * Константа, а не число в условии: иначе порог придётся искать
     * по тексту предупреждения, которое его и называет.
     */
    private const int SELECTOR_CARS_WARNING_THRESHOLD = 500;

    /**
     * Слаги категорий, на блоки которых ведут плашки «Экосистемы».
     *
     * Список живёт здесь, а не в шаблоне: адрес плашки надо СВЕРИТЬ
     * со справочником, а Blade в БД не ходит. Сами плашки (заголовок,
     * текст, иконка) остаются в шаблоне — они не данные, а вёрстка.
     */
    private const array ECOSYSTEM_ANCHORS = ['maintenance', 'detailing'];

    /**
     * `CatalogFilterOptions` внедряется через конструктор, а не создаётся
     * `new` внутри: правило `ARCHITECTURE.md` — иначе его не подменить
     * в тесте. Границы бюджета и список двигателей быстрого подбора
     * берутся оттуда же, откуда их берёт форма фильтра каталога: второй
     * источник тех же данных разошёлся бы с первым, и слайдер начал бы
     * обещать автомобили, которых каталог не покажет.
     */
    public function __construct(private readonly CatalogFilterOptions $filterOptions) {}

    /**
     * @return array{
     *     cars: EloquentCollection<int, Car>,
     *     ticker: list<string>,
     *     trustImage: ?string,
     *     promo: ?array{title: ?string, text: ?string, link_text: ?string, link_url: ?string, image_url: ?string},
     *     advantages: list<array{number: ?string, title: string, text: ?string}>,
     *     steps: list<array{number: ?string, title: string, text: ?string, image_url: ?string}>,
     *     priceBreakdown: list<array{title: string, note: ?string}>,
     *     faq: list<array{question: string, answer: string}>,
     *     reviews: EloquentCollection<int, Review>,
     *     serviceGroups: list<array{category: ServiceCategory, note: ?string, items: EloquentCollection<int, Service>}>,
     *     services: EloquentCollection<int, Service>,
     *     ecosystemLinks: array<string, string>,
     *     selector: array{cars: list<array{price: ?int, engine: ?string, status: string}>, engines: list<array{value: string, label: string}>, price_min: ?int, price_max: ?int, total: int},
     *     contacts: array{phone: ?string, email: ?string, address: ?string},
     *     seo: array{title: ?string, description: ?string},
     * }
     */
    public function build(): array
    {
        $cars = $this->cars();
        $steps = $this->steps();
        $priceBreakdown = $this->priceBreakdown();
        $faq = $this->faq();
        $reviews = $this->reviews();
        $price = $this->servicePrice();

        // Одно сообщение на всю сборку, а не по одному на блок: по нему
        // видно, КАКОЙ блок исчез со страницы, — а именно этот вопрос
        // и возникает, когда главная выглядит короче, чем вчера.
        //
        // WARN здесь нет намеренно: пустые `steps`, `price_breakdown`
        // и `faq` — рабочий сценарий «блок выключен», как у `promo`
        // и `advantages`. Предупреждение о пустой подборке авто пишет
        // `cars()`, о пустой витрине услуг — `servicePrice()`: там пустота
        // означает незаполненную админку, а не выключенный блок.
        Log::debug('[Главная] контент собран', [
            'cars' => $cars->count(),
            'steps' => count($steps),
            'price_breakdown' => count($priceBreakdown),
            'faq' => count($faq),
            'reviews' => $reviews->count(),
            'service_groups' => count($price['groups']),
        ]);

        return [
            'cars' => $cars,
            'ticker' => $this->ticker(),
            'trustImage' => $this->trustImageUrl(),
            'promo' => $this->promo(),
            'advantages' => $this->advantages(),
            'steps' => $steps,
            'priceBreakdown' => $priceBreakdown,
            'faq' => $faq,
            'reviews' => $reviews,
            'serviceGroups' => $price['groups'],
            'services' => $price['services'],
            'ecosystemLinks' => $this->ecosystemLinks($price['categories']),
            'selector' => $this->selector(),
            'contacts' => $this->contacts(),
            'seo' => $this->seo(),
        ];
    }

    /**
     * Подборка «на главной»: отмеченные администратором и при этом ещё
     * доступные к покупке.
     *
     * Пересечение делает сервис, а не скоуп `onHomepage()`: смысл скоупа —
     * «отмечен администратором», и ровно в этом значении его читают
     * `CarTest` и `SeedersTest`. Проданный автомобиль с отметкой — тупик:
     * карточка открывается, статус говорит «продан», покупать нечего.
     *
     * `with()` обязателен: без него сетка из шести карточек стоит
     * тринадцать запросов — карточка обращается и к марке, и к главному фото.
     *
     * @return EloquentCollection<int, Car>
     */
    private function cars(): EloquentCollection
    {
        $cars = Car::query()
            ->onHomepage()
            ->available()
            ->with(['brand', 'mainPhoto'])
            ->limit((int) config('catalog.homepage_limit'))
            ->get();

        if ($cars->isEmpty()) {
            // WARN на каждый рендер — осознанно, по прецеденту `SiteHeader`
            // с незаполненным телефоном: это конфигурационная ошибка,
            // которая чинится один раз, а тихий пропуск означал бы главную
            // без главного блока, которого никто не заметил.
            Log::warning('[Главная] в подборке нет ни одного автомобиля', [
                'hint' => 'отметьте автомобили галкой «Показывать на главной» в разделе «Автомобили» админки',
            ]);
        }

        return $cars;
    }

    /**
     * Бегущая строка: список непустых строк.
     *
     * @return list<string>
     */
    private function ticker(): array
    {
        $items = [];

        foreach ($this->listFrom(Setting::get('home.ticker')) as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $text = trim((string) $item);

            if ($text === '') {
                continue;
            }

            $items[] = $text;
        }

        return $items;
    }

    /**
     * Промо-баннер или `null`, если администратор очистил и заголовок, и текст.
     *
     * Проверка пустоты строгая (`=== null || === ''`), а не `empty()`:
     * правило `RULES.md` — `empty('0')` в PHP истинно.
     *
     * @return ?array{title: ?string, text: ?string, link_text: ?string, link_url: ?string, image_url: ?string}
     */
    private function promo(): ?array
    {
        $promo = Setting::get('home.promo');

        if (! is_array($promo)) {
            return null;
        }

        $title = $this->string($promo['title'] ?? null);
        $text = $this->string($promo['text'] ?? null);

        if ($title === null && $text === null) {
            return null;
        }

        return [
            'title' => $title,
            'text' => $text,
            'link_text' => $this->string($promo['link_text'] ?? null),
            'link_url' => $this->string($promo['link_url'] ?? null),
            'image_url' => $this->singleImageUrl(
                $promo['image_id'] ?? null,
                'home.promo.image_id',
                'фон промо-блока',
            ),
        ];
    }

    /**
     * Фотография шоу-рума в полосе доверия (веха 4.5) — URL или `null`.
     *
     * Ключ настроек, а не имя файла в шаблоне: заказчик обязан иметь
     * возможность заменить снимок сам, не дожидаясь разработчика. Ровно
     * поэтому же снимок живёт в медиабиблиотеке, а не в `resources/images/`
     * рядом с фоном хиро.
     *
     * `null` убирает фотопанель, но НЕ блок: полоса доверия остаётся
     * четырьмя текстовыми карточками, как до вехи 4.5. Обратное правило
     * снесло бы с главной работающий блок из-за незаполненной настройки.
     */
    private function trustImageUrl(): ?string
    {
        $trust = Setting::get('home.trust');

        return $this->singleImageUrl(
            is_array($trust) ? ($trust['image_id'] ?? null) : null,
            'home.trust.image_id',
            'фотография шоу-рума в полосе доверия',
        );
    }

    /**
     * Одиночная ссылка на медиабиблиотеку: URL записи или `null`.
     *
     * Штатным путём `null` при заполненном `image_id` не возникает —
     * удаление используемой записи блокирует проверка `Media::usages()`
     * (веха 3.5). Значит запись пропала в обход админки, и это стоит WARN:
     * блок отрендерится без изображения, а не упадёт, и симптом иначе
     * останется незамеченным — снаружи он неотличим от незаполненного поля.
     *
     * Общий метод на промо и полосу доверия, а не копия на каждый ключ:
     * оба разрешают ОДИН id, и разошедшиеся копии первым делом разошлись бы
     * текстом предупреждения — то есть ровно тем, ради чего оно пишется.
     * Для репитера этот приём не годится и не переиспользуется: там
     * `find()` на элемент означает N+1, и этапы разрешаются одним запросом
     * в `withStepImages()`.
     */
    private function singleImageUrl(mixed $mediaId, string $setting, string $what): ?string
    {
        // Пустота проверяется строго — правило `RULES.md`: `empty()`
        // истинно и для нуля.
        if ($mediaId === null || $mediaId === '') {
            return null;
        }

        $media = Media::query()->find($mediaId);

        if ($media === null) {
            Log::warning("[Главная] {$what} ссылается на удалённую запись медиабиблиотеки", [
                'setting' => $setting,
                'media_id' => $mediaId,
            ]);

            return null;
        }

        return $media->url;
    }

    /**
     * Блок «Почему мы». Элемент без заголовка выпадает: карточка,
     * состоящая из одного номера, выглядит как поломка вёрстки.
     *
     * @return list<array{number: ?string, title: string, text: ?string}>
     */
    private function advantages(): array
    {
        $advantages = [];

        foreach ($this->listFrom(Setting::get('home.advantages')) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = $this->string($item['title'] ?? null);

            if ($title === null) {
                continue;
            }

            $advantages[] = [
                'number' => $this->string($item['number'] ?? null),
                'title' => $title,
                'text' => $this->string($item['text'] ?? null),
            ];
        }

        return $advantages;
    }

    /**
     * Этапы покупки. Элемент без заголовка выпадает по тому же правилу,
     * что и в `advantages()`: карточка из одного номера выглядит
     * как поломка вёрстки, а не как «не дописали текст».
     *
     * Картинка, в отличие от заголовка, необязательна: этап без неё
     * рендерится прежней текстовой карточкой. Обратное правило снесло бы
     * с сайта этап, которому изображение ещё не подобрали, — и симптом
     * читался бы как поломка сохранения формы, а не как пустое поле.
     *
     * @return list<array{number: ?string, title: string, text: ?string, image_url: ?string}>
     */
    private function steps(): array
    {
        $steps = [];

        foreach ($this->listFrom(Setting::get('home.steps')) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = $this->string($item['title'] ?? null);

            if ($title === null) {
                continue;
            }

            $steps[] = [
                'number' => $this->string($item['number'] ?? null),
                'title' => $title,
                'text' => $this->string($item['text'] ?? null),
                'image_id' => $item['image_id'] ?? null,
            ];
        }

        return $this->withStepImages($steps);
    }

    /**
     * Разрешить `image_id` этапов в URL — ОДНИМ запросом на весь блок.
     *
     * Приём `promoImageUrl()` (`find()` на каждый вызов) здесь не годится:
     * для одной картинки промо это один запрос, для шести этапов — шесть,
     * то есть N+1 на главной странице сайта. Правило проекта против N+1
     * на списках действует и при шести элементах: шесть — это ровно то
     * число, при котором приём копируют дальше.
     *
     * @param  list<array{number: ?string, title: string, text: ?string, image_id: mixed}>  $steps
     * @return list<array{number: ?string, title: string, text: ?string, image_url: ?string}>
     */
    private function withStepImages(array $steps): array
    {
        $ids = [];

        foreach ($steps as $step) {
            // Пустота проверяется строго — правило `RULES.md`:
            // `empty()` истинно и для нуля.
            if ($step['image_id'] === null || $step['image_id'] === '') {
                continue;
            }

            $ids[] = $step['image_id'];
        }

        /** @var EloquentCollection<int, Media> $media */
        $media = $ids === []
            ? new EloquentCollection
            : Media::query()->whereKey(array_unique($ids))->get()->keyBy(
                static fn (Media $record): int => (int) $record->getKey(),
            );

        $resolved = [];

        foreach ($steps as $step) {
            $id = $step['image_id'];
            unset($step['image_id']);

            $step['image_url'] = null;

            if ($id !== null && $id !== '') {
                $record = $media->get((int) $id);

                if ($record === null) {
                    // Штатным путём сюда не попасть: удаление используемой
                    // записи блокирует `Media::usages()`, и ссылку внутри
                    // репитера он теперь понимает. Значит запись пропала
                    // в обход админки — и это стоит WARN, потому что
                    // снаружи отказ неотличим от незаполненного поля:
                    // карточка просто останется текстовой.
                    Log::warning('[Главная] этап покупки ссылается на удалённую запись медиабиблиотеки', [
                        'setting' => 'home.steps',
                        'step' => $step['title'],
                        'media_id' => $id,
                    ]);
                } else {
                    // `url`, а не `thumb_url`: карточка занимает треть
                    // контейнера в 1440px, то есть кадр около 460px,
                    // а на экране с двойной плотностью — под 930px.
                    // Превью шириной 600 растянулось бы в мыло.
                    $step['image_url'] = $record->url;
                }
            }

            $resolved[] = $step;
        }

        return $resolved;
    }

    /**
     * Состав цены. Элемент без названия статьи выпадает: строка из одного
     * уточнения («по условиям сделки») не сообщает, к чему оно относится.
     *
     * @return list<array{title: string, note: ?string}>
     */
    private function priceBreakdown(): array
    {
        $rows = [];

        foreach ($this->listFrom(Setting::get('home.price_breakdown')) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = $this->string($item['title'] ?? null);

            if ($title === null) {
                continue;
            }

            $rows[] = [
                'title' => $title,
                'note' => $this->string($item['note'] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * Частые вопросы.
     *
     * Здесь обязательны ОБА поля, в отличие от остальных репитеров: `<summary>`
     * без содержимого раскрывается в пустоту, и это читается как сломанный
     * аккордеон, а не как отсутствующий ответ. Вопрос без ответа удаляется
     * целиком, а не показывается нераскрываемым.
     *
     * @return list<array{question: string, answer: string}>
     */
    private function faq(): array
    {
        $faq = [];

        foreach ($this->listFrom(Setting::get('home.faq')) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $question = $this->string($item['question'] ?? null);
            $answer = $this->string($item['answer'] ?? null);

            if ($question === null || $answer === null) {
                continue;
            }

            $faq[] = [
                'question' => $question,
                'answer' => $answer,
            ];
        }

        return $faq;
    }

    /**
     * Отзывы клиентов — первый потребитель модели `Review` (веха 3.2)
     * и её модерации.
     *
     * Только опубликованные: немодерированный отзыв на сайте — это дефект,
     * и путь к нему закрыт скоупом, а не условием в шаблоне. `with('media')`
     * обязателен — карточка показывает фото автора, и без него сетка из трёх
     * отзывов стоит четыре запроса вместо двух.
     *
     * Пустая выдача убирает секцию целиком (правило проекта о блоках,
     * управляемых данными), и WARN здесь не пишется: отзывов может
     * не быть просто потому, что их ещё не собрали.
     *
     * @return EloquentCollection<int, Review>
     */
    private function reviews(): EloquentCollection
    {
        return Review::query()
            ->published()
            ->ordered()
            ->with('media')
            ->limit((int) config('home.reviews_limit'))
            ->get();
    }

    /**
     * Витрина прайса и полный список позиций для селекта формы — из одного
     * запроса.
     *
     * Два значения возвращаются вместе намеренно: они обязаны быть построены
     * из одной выборки. Витрина усечена до `home.services_per_category`
     * позиций на категорию, селект — нет, и асимметрия здесь работает
     * в ОДНУ сторону: селект надмножество витрины (решение 3 вехи 4.6).
     * Клик по любой видимой строке гарантированно находит свою опцию,
     * а посетитель, пришедший за позицией вне витрины, находит её в списке.
     * Обратное было бы дефектом — клик подставлял бы несуществующую опцию,
     * и Alpine молча ничего не сделал бы.
     *
     * Состав и порядок категорий с вехи 4.13 задаёт справочник
     * (`service_categories`), а не кейсы енама: категории заводит заказчик.
     * Запчасти исключает колонка `page` — у них своя посадочная страница.
     * Порядок селекта наследуется от категорий, поэтому `<optgroup>`
     * в форме выстраиваются так же, как блоки витрины.
     *
     * Описание категории приходит из её собственной колонки. До вехи 4.13
     * оно бралось из настройки `services_page.notes` — того же ключа, что
     * читала страница услуг, — и здесь стоял отдельный абзац о том, почему
     * второй набор ключей заводить нельзя. Объяснять больше нечего: поле
     * одно и живёт у категории.
     *
     * Категории возвращаются третьим значением ради плашек «Экосистемы»
     * (`ecosystemLinks()`): им нужен тот же справочник, и второй запрос
     * за теми же строками был бы платой за раздельность методов.
     *
     * @return array{
     *     groups: list<array{category: ServiceCategory, note: ?string, items: EloquentCollection<int, Service>}>,
     *     services: EloquentCollection<int, Service>,
     *     categories: EloquentCollection<int, ServiceCategory>,
     * }
     */
    private function servicePrice(): array
    {
        $categories = ServiceCategory::query()
            ->onPage(ServicePage::Services)
            ->ordered()
            ->get();

        // Один запрос на весь блок, группировка в PHP. Запрос на категорию
        // означал бы четыре запроса ради четырёх карточек плюс пятый
        // на селект формы — тот же приём, что в `ServicesPageContent`.
        //
        // Предзагрузка обязательна и у фотографии, и у категории:
        // `Service::ordered()` сортирует по наличию фотографии, а селект
        // «Интересует» в форме заявки группирует позиции по имени
        // категории. Без обеих — N+1 на пустом месте, и не падает при этом
        // ни один тест, кроме того, который считает запросы.
        $published = Service::query()->published()->ordered()->with(['media', 'category'])->get();

        if ($published->isEmpty()) {
            // WARN на каждый рендер — по прецеденту `ServicesPageContent`:
            // снаружи главная выглядит рабочей, просто без блока услуг,
            // и заметить незаполненный прайс больше нечем.
            Log::warning('[Главная] на витрине услуг нет ни одной опубликованной позиции', [
                'hint' => 'опубликуйте услуги в разделе «Услуги и запчасти» админки',
            ]);
        }

        $limit = (int) config('home.services_per_category');

        $groups = [];

        /** @var EloquentCollection<int, Service> $services */
        $services = new EloquentCollection;

        foreach ($categories as $category) {
            $items = $published->where('service_category_id', $category->getKey());

            // Категория без опубликованных позиций выпадает: карточка
            // с заголовком «Шиномонтаж» и пустым списком читается
            // как поломка, а не как «пока ничего не завели».
            if ($items->isEmpty()) {
                continue;
            }

            // Селект наполняется ДО усечения — в этом и состоит асимметрия.
            $services = $services->concat($items);

            $groups[] = [
                'category' => $category,
                'note' => $this->string($category->description),
                'items' => $items->take($limit),
            ];
        }

        return ['groups' => $groups, 'services' => $services, 'categories' => $categories];
    }

    /**
     * Адреса плашек «Экосистемы», ведущих в блоки категорий на `/services`.
     *
     * Разрешение живёт здесь, а не в шаблоне, по правилу проекта «Blade
     * не ходит в БД». До вехи 4.13 разрешать было нечего: якорь выдавал
     * `ServiceCategory::anchor()` у кейса енама, и промахнуться мимо
     * существующего блока было физически нельзя. Теперь слаг правится
     * из админки, то есть промах стал ДОСТИЖИМЫМ состоянием, а ссылка
     * на несуществующий якорь не работает МОЛЧА — ни ошибки в консоли,
     * ни падения теста разметки.
     *
     * Промах даёт ссылку на страницу услуг БЕЗ якоря: она приводит туда,
     * куда обещала плашка, просто без прокрутки к блоку. Ссылка в никуда
     * была бы хуже — человек остаётся на главной и решает, что сайт сломан.
     *
     * @param  EloquentCollection<int, ServiceCategory>  $categories
     * @return array<string, string>
     */
    private function ecosystemLinks(EloquentCollection $categories): array
    {
        $slugs = $categories->pluck('slug')->all();

        $links = [];

        foreach (self::ECOSYSTEM_ANCHORS as $slug) {
            if (in_array($slug, $slugs, true)) {
                $links[$slug] = route('services.index').'#'.$slug;

                continue;
            }

            Log::warning('[Главная] плашка экосистемы ссылается на несуществующую категорию', [
                'slug' => $slug,
                'hint' => 'заведите категорию с этим слагом в разделе «Категории услуг» или поправьте плашку в шаблоне главной',
            ]);

            $links[$slug] = route('services.index');
        }

        return $links;
    }

    /**
     * Данные быстрого подбора: выборка каталога, варианты двигателя
     * и границы бюджета.
     *
     * Счёт идёт по РЕАЛЬНОМУ каталогу, а не по формуле из макета
     * (`js/mockup.js` считает «направления» выражением вида
     * `value < 3000000 ? 2 : …`). Цифра на живом сайте, не подкреплённая
     * данными, — это обещание, которого нет.
     *
     * Выборка нарочно узкая: три скаляра на автомобиль, ни связей, ни фото.
     * Массив уезжает в разметку через `@js(...)`, и каждое лишнее поле
     * умножается на число доступных автомобилей.
     *
     * Границы бюджета берутся из `CatalogFilterOptions`, а не из макета
     * (`min="2000000" max="15000000"`): жёсткие числа рассинхронизируются
     * с каталогом при первом же автомобиле дороже верхней границы —
     * слайдер физически не сможет его выбрать, и блок начнёт врать.
     * Обе границы `null` означают пустой или бесценовой каталог, и шаблон
     * в этом случае не рендерит секцию вовсе.
     *
     * @return array{cars: list<array{price: ?int, engine: ?string, status: string}>, engines: list<array{value: string, label: string}>, price_min: ?int, price_max: ?int, total: int}
     */
    private function selector(): array
    {
        $options = $this->filterOptions->build();

        $cars = Car::query()
            ->available()
            ->get(['price', 'engine_type', 'status'])
            ->map(static fn (Car $car): array => [
                'price' => $car->price === null ? null : (int) $car->price,
                'engine' => $car->engine_type?->value,
                'status' => $car->status->value,
            ])
            ->all();

        $total = count($cars);

        if ($total > self::SELECTOR_CARS_WARNING_THRESHOLD) {
            Log::warning('[Главная] выборка быстрого подбора превысила порог', [
                'cars' => $total,
                'threshold' => self::SELECTOR_CARS_WARNING_THRESHOLD,
                'hint' => 'массив уезжает в HTML целиком — блок пора переводить на отдельный endpoint с запросом на каждое изменение фильтра',
            ]);
        }

        // По этому сообщению видно, почему секция не отрендерилась: пустая
        // выборка и обе границы `null` — это каталог без цен, а не ошибка
        // шаблона.
        Log::debug('[Главная] быстрый подбор', [
            'cars' => $total,
            'price_min' => $options['prices']['min'],
            'price_max' => $options['prices']['max'],
        ]);

        return [
            'cars' => $cars,
            // Варианты, реально встречающиеся в каталоге, а не все четыре
            // кейса `EngineType`: чип, дающий ноль автомобилей, — это
            // сломанное доверие к подбору, ровно то же основание,
            // по которому их отбирает форма фильтра каталога.
            'engines' => array_map(
                static fn (EngineType $engine): array => [
                    'value' => $engine->value,
                    'label' => $engine->label(),
                ],
                $options['engines'],
            ),
            'price_min' => $options['prices']['min'],
            'price_max' => $options['prices']['max'],
            // Серверное значение счётчика: без JavaScript блок читается
            // как «В каталоге N автомобилей → Перейти в каталог».
            'total' => $total,
        ];
    }

    /**
     * Контакты для левой колонки секции заявки.
     *
     * Читает сервис, а не Blade: исключение «компонент ходит в БД»
     * распространяется только на `SiteHeader` и `SiteFooter`
     * (`ARCHITECTURE.md`) — их рендерит layout на каждой странице,
     * и прокидывать контакты из каждого контроллера было бы опаснее.
     * У секции заявки такого оправдания нет: она живёт на одной странице
     * и данные получает от контроллера, как все.
     *
     * Пустое значение остаётся `null` и убирает свой контакт в шаблоне:
     * подпись «Телефон» над пустотой хуже отсутствующего блока.
     *
     * @return array{phone: ?string, email: ?string, address: ?string}
     */
    private function contacts(): array
    {
        return [
            'phone' => $this->string(Setting::get('contacts.phone')),
            'email' => $this->string(Setting::get('contacts.email')),
            'address' => $this->string(Setting::get('contacts.address')),
        ];
    }

    /**
     * SEO-заголовки по умолчанию (веха 3.5) получают первого потребителя.
     *
     * Пустая настройка приводится к `null`, чтобы сработал фолбэк шаблона:
     * иначе очищенный ключ дал бы пустой `<title>`. На остальные страницы
     * это не распространяется — у каталога, карточки и разделов свои
     * осмысленные заголовки, и общий фолбэк там маскировал бы забытую
     * секцию вместо того, чтобы её обнажить.
     *
     * @return array{title: ?string, description: ?string}
     */
    private function seo(): array
    {
        return [
            'title' => $this->string(Setting::get('seo.default_title')),
            'description' => $this->string(Setting::get('seo.default_description')),
        ];
    }

    /**
     * Значение репитера настроек в виде списка.
     *
     * Удаление всех элементов в форме настроек даёт `null`, а не `[]`, —
     * это и есть причина, по которой нормализация живёт в сервисе.
     *
     * @return list<mixed>
     */
    private function listFrom(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Непустая строка или `null`.
     */
    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
