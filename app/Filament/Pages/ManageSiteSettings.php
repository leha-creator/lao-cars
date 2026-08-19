<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Actions\HelpAction;
use App\Filament\Forms\Components\MediaPicker;
use App\Filament\NavigationGroup;
use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use UnitEnum;

/**
 * Редактируемые блоки сайта: контакты, соцсети, тексты главной,
 * подвала, страниц автосервиса и запчастей, страницы «О компании»,
 * SEO по умолчанию.
 *
 * Собрана методом `content()`, а не собственным Blade-шаблоном: штатный
 * вид `filament-panels::pages.page` рендерит ровно `{{ $this->content }}`,
 * и свой шаблон означал бы копию вёрстки панели, которая отстанет от неё
 * на первом же обновлении Filament — причём отстанет тихо, страница
 * продолжит работать и просто перестанет выглядеть как остальная админка.
 * Образец — штатная `Filament\Auth\Pages\EditProfile`.
 *
 * @property-read Schema $form
 */
final class ManageSiteSettings extends Page
{
    use InteractsWithFormActions;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static ?string $title = 'Настройки сайта';

    protected static ?int $navigationSort = 1;

    /**
     * Реестр ключей настроек, сгруппированный по вкладкам формы.
     *
     * По нему идут и заполнение, и сохранение. Без реестра поле, забытое
     * в сохранении, молча не пишется, и «настройка не применяется» ищется
     * руками по всей форме.
     *
     * Ключи — это ключи строк `site_settings`, а не имена полей формы:
     * `home.promo` — одна запись с объектом внутри, и полей у неё пять.
     * Совпадение этого множества с ключами `SiteSettingSeeder` проверяет
     * тест: без сторожа добавленный в сид ключ не получает поля, а
     * добавленное поле — значения по умолчанию.
     *
     * @var array<string, list<string>>
     */
    private const array KEYS = [
        'contacts' => [
            'contacts.phone',
            'contacts.email',
            'contacts.address',
            'contacts.work_hours',
        ],
        'socials' => [
            'socials.telegram',
            'socials.whatsapp',
            'socials.vk',
        ],
        'home' => [
            'home.ticker',
            'home.promo',
            'home.advantages',
            // Блоки главной, заведённые вехой 4.6 по макету v2. Форма
            // значения у всех трёх — репитер, то есть список объектов,
            // и нормализует их тот же `HomeContent`, что и `advantages`.
            'home.steps',
            'home.price_breakdown',
            'home.faq',
            // Фотография шоу-рума в полосе доверия (веха 4.5). Форма
            // значения — объект с одним полем, как у `home.promo`,
            // а не голый скаляр: реестр `MediaSettingKeys` разводит ключ
            // настройки и путь внутрь её значения, и `data_get()` с пустым
            // путём проверку использования не выполнил бы.
            'home.trust',
        ],
        'footer' => [
            'footer.guarantee',
        ],
        'pages' => [
            'services_page.intro_title',
            'services_page.intro_text',
            // Описания категорий прайса ЗДЕСЬ БЫЛИ до вехи 4.13 — ОДНОЙ
            // настройкой-объектом с полем на каждую категорию
            // (`services_page.notes.maintenance` и так далее), а не четырьмя
            // отдельными ключами: реестр сверяется с сидом по ключу целиком,
            // и четыре ключа вместо одного дали бы четыре шанса разойтись.
            // Решение было верным для пяти неизменяемых категорий и стало
            // неверным для редактируемого справочника — ключами объекта
            // служили значения енама, а удалённая из админки категория
            // оставила бы свой ключ в jsonb навсегда: без поля в форме
            // и без единого способа его увидеть. Описания переехали
            // в колонку `service_categories.description`.
            'services_page.price_disclaimer',
            'services_page.advantages',
            'parts_page.intro_title',
            'parts_page.intro_text',
            'parts_page.delivery_terms',
        ],
        // Страница «О компании» (веха 4.5) получает СВОЮ вкладку, а не
        // дописывается в `pages`. Та называется «Автосервис и запчасти»,
        // и её имя намеренно не трогали при переименовании меню — это
        // записано в `DESCRIPTION.md` и в `docs/design-system.md`. Спрятать
        // ключи страницы «О компании» под заголовок про автосервис значит
        // положить их туда, где заказчик их не найдёт.
        'about' => [
            'about_page.intro_title',
            'about_page.intro_text',
            // Миссия — ОДНА настройка-объект с двумя полями, а не два
            // ключа: реестр сверяется с сидом по ключу целиком, и два
            // ключа вместо одного дали бы два шанса разойтись. Образец
            // формы значения — `home.promo` и `footer.guarantee`.
            'about_page.mission',
            'about_page.history',
        ],
        'seo' => [
            'seo.default_title',
            'seo.default_description',
        ],
    ];

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /**
     * Страница — не ресурс, политика к ней сама не применяется, а базовая
     * реализация трейта `CanAuthorizeAccess` возвращает `true` всем.
     * Поэтому проверка объявлена здесь явно.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() === true;
    }

    /**
     * Ссылка на справку в шапке — рядом с формой, а не только в меню.
     *
     * Вопрос «что делает этот блок» возникает над полем, а не в боковом
     * меню. Вкладок у формы семь, и статей о них тоже несколько —
     * кнопка ведёт в ту, с которой человек чаще всего начинает.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('home-blocks'),
        ];
    }

    /**
     * Полный список ключей реестра.
     *
     * @return list<string>
     */
    public static function settingKeys(): array
    {
        return array_merge(...array_values(self::KEYS));
    }

    public function mount(): void
    {
        $values = [];

        foreach (self::settingKeys() as $key) {
            $values[$key] = Setting::get($key);
        }

        // `Arr::undot()`, а не плоский массив с ключами-точками: Filament
        // разбирает имя поля как путь через `data_get()`, а тот, в отличие
        // от `Arr::get`, не имеет ярлыка на точное совпадение и
        // литерального ключа `'contacts.phone'` не находит.
        $this->form->fill(Arr::undot($values));
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $changed = [];

        foreach (self::settingKeys() as $key) {
            $value = data_get($state, $key);

            if (self::isChanged($value, Setting::get($key))) {
                $changed[] = $key;
            }

            // Безусловно, даже когда значение пустое. Условие
            // `if (filled($value))` сделало бы невозможным очистить
            // текстовый блок: администратор стирает промо-текст,
            // сохраняет, текст возвращается из БД.
            Setting::set($key, $value);
        }

        // Кеш сбрасывать вручную не нужно — `Setting::booted()` делает
        // это на событии `saved`.

        Log::info('Сохранены настройки сайта', [
            'actor_id' => auth()->id(),
            // Только ключи: значения — это тексты сайта, в логе они лишние.
            'changed' => $changed,
        ]);

        Notification::make()
            ->title('Настройки сохранены')
            ->success()
            ->send();
    }

    /**
     * Отличается ли новое значение настройки от сохранённого.
     *
     * Голого `!==` здесь мало. Значения-объекты (`home.promo`,
     * `footer.guarantee`) лежат в jsonb, а PostgreSQL порядок ключей
     * в jsonb не сохраняет — он их нормализует. Форма же собирает объект
     * в порядке объявления полей, и `!==`, чувствительный к порядку
     * ключей, объявлял бы эти настройки изменёнными при каждом
     * сохранении. На запись это не влияет (значения те же), но лог
     * изменений превращается в шум, ради которого он и заводился.
     *
     * Сравнение остаётся строгим по типам: ключи сортируются
     * рекурсивно, значения сравниваются через `!==` (правило `RULES.md`
     * про нестрогие сравнения).
     */
    private static function isChanged(mixed $new, mixed $stored): bool
    {
        return self::normalize($new) !== self::normalize($stored);
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = array_map(self::normalize(...), $value);

        // Списки (бегущая строка, преимущества) сортировать нельзя —
        // там порядок и есть содержание.
        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->tabs([
                self::contactsTab(),
                self::socialsTab(),
                self::homeTab(),
                self::footerTab(),
                self::pagesTab(),
                self::aboutTab(),
                self::seoTab(),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFormContentComponent(),
        ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make($this->getFormActions())
                    ->sticky()
                    ->key('form-actions'),
            ]);
    }

    /**
     * Кнопка сохранения пишется руками: трейт `InteractsWithFormActions`
     * даёт только `getFormActions()`, `getCachedFormActions()` и
     * `hasFullWidthFormActions()` — готового действия сохранения в нём
     * нет, в отличие от `EditRecord`.
     *
     * @return array<Action>
     */
    public function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Сохранить')
                ->submit('save')
                ->keyBindings(['mod+s']),
        ];
    }

    private static function contactsTab(): Tab
    {
        return Tab::make('Контакты')->schema([
            TextInput::make('contacts.phone')
                ->label('Телефон')
                ->tel(),

            TextInput::make('contacts.email')
                ->label('E-mail')
                ->email(),

            TextInput::make('contacts.address')
                ->label('Адрес'),

            TextInput::make('contacts.work_hours')
                ->label('Часы работы'),
        ]);
    }

    private static function socialsTab(): Tab
    {
        return Tab::make('Соцсети')->schema([
            TextInput::make('socials.telegram')
                ->label('Telegram')
                ->url(),

            TextInput::make('socials.whatsapp')
                ->label('WhatsApp')
                ->url(),

            TextInput::make('socials.vk')
                ->label('ВКонтакте')
                ->url(),
        ]);
    }

    private static function homeTab(): Tab
    {
        return Tab::make('Главная')->schema([
            // `simple()`-репитер дегидрируется в плоский список строк —
            // ровно тот формат, что лежит в сиде и который ждут шаблоны
            // вехи 4.1.
            Repeater::make('home.ticker')
                ->label('Бегущая строка')
                ->helperText('Тезисы в шапке главной.')
                ->simple(
                    TextInput::make('text')
                        ->label('Тезис')
                        ->required(),
                )
                ->reorderable()
                ->defaultItems(0),

            // Полоса доверия (веха 4.5). Из админки правится только
            // фотография: четыре текста карточек по-прежнему живут
            // в шаблоне — правило вехи 4.6 «одна строка на карточку,
            // привязанная к вёрстке» никто не отменял, и заказчик
            // просил именно снимок салона, а не редактор текстов.
            //
            // Путь этого поля перечислен в `MediaSettingKeys` — оттуда
            // его читает проверка «файл где-то используется» перед
            // удалением из медиабиблиотеки.
            MediaPicker::make('home.trust.image_id')
                ->label('Полоса доверия: фотография шоу-рума')
                ->helperText('Снимок салона рядом с карточками. Без него полоса остаётся четырьмя текстовыми карточками, как раньше.'),

            TextInput::make('home.promo.title')
                ->label('Промо-блок: заголовок'),

            Textarea::make('home.promo.text')
                ->label('Промо-блок: текст')
                ->rows(3),

            TextInput::make('home.promo.link_text')
                ->label('Промо-блок: текст кнопки'),

            TextInput::make('home.promo.link_url')
                ->label('Промо-блок: ссылка кнопки')
                ->helperText('Якорь вида #lead-form или полный URL.'),

            // Пикер без связи: Eloquent-модели у этой формы нет.
            // Путь этого поля перечислен в `MediaSettingKeys` — оттуда его
            // читает проверка «файл где-то используется» перед удалением
            // из медиабиблиотеки.
            MediaPicker::make('home.promo.image_id')
                ->label('Промо-блок: фон'),

            Repeater::make('home.advantages')
                ->label('Преимущества')
                ->helperText('Блок «Почему мы» на главной.')
                ->schema([
                    TextInput::make('number')
                        ->label('Номер')
                        ->required(),

                    TextInput::make('title')
                        ->label('Заголовок')
                        ->required(),

                    Textarea::make('text')
                        ->label('Текст')
                        ->rows(2),
                ])
                ->reorderable()
                ->collapsed()
                ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                ->defaultItems(0),

            // Три репитера ниже заведены вехой 4.6. Формат значения дословно
            // повторяет `home.advantages`: одна нормализация в `HomeContent`
            // на все списки объектов, а две разные на одинаковых данных
            // разъехались бы на первой же правке одной из них.
            //
            // `itemLabel()` обязателен у каждого: свёрнутый элемент без
            // подписи — это шесть одинаковых строк «Элемент», в которых
            // администратор ищет нужную раскрытием по очереди.
            Repeater::make('home.steps')
                ->label('Этапы покупки')
                ->helperText('Блок «Как проходит покупка» на главной.')
                ->schema([
                    TextInput::make('number')
                        ->label('Номер')
                        ->required(),

                    TextInput::make('title')
                        ->label('Заголовок')
                        ->required(),

                    Textarea::make('text')
                        ->label('Текст')
                        ->rows(2),

                    // Первый медийный пикер ВНУТРИ репитера. Путь этого
                    // поля перечислен в `MediaSettingKeys` подстановочным
                    // знаком (`home.steps` + `*.image_id`) — оттуда его
                    // читает проверка «файл где-то используется» перед
                    // удалением из библиотеки.
                    //
                    // Поле необязательное намеренно: сделать его
                    // обязательным значит снести с сайта этап, которому
                    // картинку ещё не подобрали, при первом же сохранении
                    // формы — и выглядело бы это как поломка сохранения,
                    // а не как незаполненное поле.
                    MediaPicker::make('image_id')
                        ->label('Изображение')
                        ->helperText('Появится в карточке этапа. Без изображения карточка останется текстовой.'),
                ])
                // Порядок содержателен: этапы идут по времени, и подбор
                // после доставки читается как ошибка сайта.
                ->reorderable()
                ->collapsed()
                ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                ->defaultItems(0),

            Repeater::make('home.price_breakdown')
                ->label('Состав цены')
                ->helperText('Блок «Прозрачность цены» на главной. Суммы здесь не указываются — только статьи расходов.')
                ->schema([
                    TextInput::make('title')
                        ->label('Статья расходов')
                        ->required(),

                    TextInput::make('note')
                        ->label('Уточнение')
                        ->helperText('Короткая приписка справа: «по условиям сделки», «фиксируется в расчёте».'),
                ])
                // Порядок содержателен и здесь: строки идут по ходу сделки,
                // от автомобиля у поставщика до дополнительных услуг.
                ->reorderable()
                ->collapsed()
                ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                ->defaultItems(0),

            Repeater::make('home.faq')
                ->label('Частые вопросы')
                ->helperText('Блок FAQ на главной. Первый вопрос на странице раскрыт.')
                ->schema([
                    TextInput::make('question')
                        ->label('Вопрос')
                        ->required(),

                    Textarea::make('answer')
                        ->label('Ответ')
                        // Обязателен наравне с вопросом: `<summary>` без
                        // содержимого раскрывается в пустоту, и это читается
                        // как сломанный аккордеон. Сервис такой элемент
                        // всё равно отбросит — здесь это видно сразу.
                        ->required()
                        ->rows(3),
                ])
                // Порядок содержателен: самые частые вопросы стоят выше,
                // и первый из них раскрыт на странице.
                ->reorderable()
                ->collapsed()
                ->itemLabel(fn (array $state): ?string => $state['question'] ?? null)
                ->defaultItems(0),
        ]);
    }

    private static function footerTab(): Tab
    {
        return Tab::make('Подвал')->schema([
            TextInput::make('footer.guarantee.title')
                ->label('Гарантия: заголовок'),

            Textarea::make('footer.guarantee.text')
                ->label('Гарантия: текст')
                ->rows(2),

            TextInput::make('footer.guarantee.link_text')
                ->label('Гарантия: текст ссылки'),

            TextInput::make('footer.guarantee.link_url')
                ->label('Гарантия: адрес ссылки'),
        ]);
    }

    private static function pagesTab(): Tab
    {
        return Tab::make('Автосервис и запчасти')->schema([
            TextInput::make('services_page.intro_title')
                ->label('Автосервис: заголовок'),

            Textarea::make('services_page.intro_text')
                ->label('Автосервис: вступление')
                ->rows(3),

            // Описания категорий переехали в справочник (раздел «Категории
            // услуг») вехой 4.13 — значение было объектом с ключами
            // по значениям енама, и с редактируемым справочником такой
            // объект превращается в мусор при первом же удалении категории.
            // Поля строились здесь циклом по кейсам енама; цикла по строкам
            // справочника на их месте нет намеренно — описание правится там
            // же, где заводится сама категория, а поле в двух местах
            // означает два места правки одного и того же.

            Textarea::make('services_page.price_disclaimer')
                ->label('Автосервис: оговорка о ценах')
                ->helperText('Выводится под прайсом вместе с плашкой «Не публичная оферта». Пустое значение убирает блок целиком.')
                ->rows(3),

            // Формат намеренно повторяет `home.advantages`: у него уже есть
            // и нормализация значения, и тест на форму. Пустой список убирает
            // секцию «Почему сюда» вместе с фотопанелью.
            Repeater::make('services_page.advantages')
                ->label('Автосервис: почему сюда')
                ->helperText('Карточки рядом с фотографией мастерской.')
                ->schema([
                    TextInput::make('number')
                        ->label('Номер')
                        ->required(),

                    TextInput::make('title')
                        ->label('Заголовок')
                        ->required(),

                    Textarea::make('text')
                        ->label('Текст')
                        ->rows(2),
                ])
                ->reorderable()
                ->collapsed()
                ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                ->defaultItems(0),

            TextInput::make('parts_page.intro_title')
                ->label('Запчасти: заголовок'),

            Textarea::make('parts_page.intro_text')
                ->label('Запчасти: вступление')
                ->rows(3),

            Textarea::make('parts_page.delivery_terms')
                ->label('Запчасти: условия поставки')
                ->rows(2),
        ]);
    }

    /**
     * Вкладка страницы «О компании» (веха 4.5) — седьмая и своя.
     *
     * Не дописана в `pagesTab()` намеренно: та называется «Автосервис
     * и запчасти», а не «Страницы», и её имя намеренно оставлено прежним
     * при переименовании пунктов меню (разбор — в `DESCRIPTION.md`
     * и `docs/design-system.md`). Ключи страницы «О компании» под этим
     * заголовком заказчик искал бы долго и, скорее всего, не нашёл.
     *
     * Тексты приходят отсюда, а не из шаблона, по правилу проекта: H1
     * и вводные тексты страниц разделов редактирует заказчик
     * (`services_page.*`, `parts_page.*`). Константа из макета отобрала бы
     * у него поле, которое ему уже отдали на двух других страницах, —
     * и асимметрию он заметит первым.
     *
     * Команда и отзывы здесь НЕ настраиваются: у них свои разделы админки
     * («Команда» и «Отзывы»), а дублирующее поле в настройках означало бы
     * два места правки одного и того же.
     */
    private static function aboutTab(): Tab
    {
        return Tab::make('О компании')->schema([
            TextInput::make('about_page.intro_title')
                ->label('Заголовок страницы')
                ->helperText('H1 и заголовок вкладки браузера. Пустое значение вернёт умолчание «О компании».'),

            Textarea::make('about_page.intro_text')
                ->label('Вступление')
                ->rows(3),

            TextInput::make('about_page.mission.title')
                ->label('Миссия: заголовок'),

            Textarea::make('about_page.mission.text')
                ->label('Миссия: текст')
                ->helperText('Блок исчезает со страницы, только когда очищены оба поля — заголовок и текст.')
                ->rows(4),

            // Формат намеренно повторяет `home.advantages`: у него уже есть
            // и нормализация значения в сервисе, и тест на форму. Отличается
            // одна подпись — «Год» вместо «Номера»: поле то же, а слово
            // человеку понятнее.
            Repeater::make('about_page.history')
                ->label('История')
                ->helperText('Вехи компании по годам. Пустой список убирает блок со страницы.')
                ->schema([
                    TextInput::make('year')
                        ->label('Год'),

                    TextInput::make('title')
                        ->label('Заголовок')
                        // Обязателен, в отличие от года: сервис всё равно
                        // отбросит элемент без заголовка — карточка из
                        // одного года читается как поломка вёрстки, —
                        // и здесь это видно сразу, а не после сохранения.
                        ->required(),

                    Textarea::make('text')
                        ->label('Текст')
                        ->rows(2),
                ])
                // Порядок содержателен: вехи идут по времени, и 2019-й
                // после 2024-го читается как ошибка сайта.
                ->reorderable()
                ->collapsed()
                ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                ->defaultItems(0),
        ]);
    }

    private static function seoTab(): Tab
    {
        return Tab::make('SEO')->schema([
            TextInput::make('seo.default_title')
                ->label('Заголовок по умолчанию')
                ->helperText('До 60 символов — иначе поисковик обрежет сниппет.'),

            Textarea::make('seo.default_description')
                ->label('Описание по умолчанию')
                ->helperText('До 160 символов — иначе поисковик обрежет сниппет.')
                ->rows(3),
        ]);
    }
}
