<?php

declare(strict_types=1);

namespace App\Filament\Pages;

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
 * подвала, страниц автосервиса и запчастей, SEO по умолчанию.
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
        ],
        'footer' => [
            'footer.guarantee',
        ],
        'pages' => [
            'services_page.intro_title',
            'services_page.intro_text',
            'parts_page.intro_title',
            'parts_page.intro_text',
            'parts_page.delivery_terms',
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
