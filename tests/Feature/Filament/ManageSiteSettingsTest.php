<?php

/*
 * Страница настроек сайта (веха 3.5).
 *
 * Три места, где ошибка молчит и обнаруживается уже на сайте:
 * форматы значений (`home.ticker` — плоский список, `home.advantages` —
 * список объектов), очистка текстового поля и расхождение реестра
 * ключей страницы с ключами сида.
 */

use App\Enums\ServiceCategory;
use App\Filament\Pages\ManageSiteSettings;
use App\Models\Media;
use App\Models\Setting;
use App\Models\User;
use App\Support\MediaSettingKeys;
use Database\Seeders\SiteSettingSeeder;
use Illuminate\Support\Facades\Log;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->seed(SiteSettingSeeder::class);
    $this->actingAs(User::factory()->create());
});

it('fills the form from stored settings', function () {
    // Имена полей — пути во вложенном состоянии: `data_get()` разбивает
    // ключ по точкам и литерального `'contacts.phone'` не находит,
    // поэтому заполнение идёт через `Arr::undot()`.
    livewire(ManageSiteSettings::class)
        ->assertOk()
        ->assertSchemaStateSet([
            'contacts.phone' => Setting::get('contacts.phone'),
            'socials.telegram' => Setting::get('socials.telegram'),
            'seo.default_title' => Setting::get('seo.default_title'),
        ]);
});

it('writes changed values to the database', function () {
    livewire(ManageSiteSettings::class)
        ->fillForm(['contacts.phone' => '+7 495 000-00-00'])
        ->call('save')
        ->assertHasNoErrors();

    // Без ручного сброса кеша: `Setting::booted()` чистит его на `saved`.
    expect(Setting::get('contacts.phone'))->toBe('+7 495 000-00-00');
});

it('keeps the ticker a flat list and advantages a list of objects', function () {
    // Форматы, от которых зависят шаблоны вех 4.1–4.2.
    livewire(ManageSiteSettings::class)
        ->call('save')
        ->assertHasNoErrors();

    $ticker = Setting::get('home.ticker');
    $advantages = Setting::get('home.advantages');

    expect($ticker)->toHaveCount(4)
        ->and($ticker[0])->toBeString()
        ->and(array_is_list($ticker))->toBeTrue()
        ->and($advantages)->toHaveCount(4)
        ->and(array_is_list($advantages))->toBeTrue()
        ->and($advantages[0])->toHaveKeys(['number', 'title', 'text']);
});

it('saves an emptied text field as empty instead of restoring it', function () {
    // Условие `if (filled($value))` перед `Setting::set()` сделало бы
    // невозможным очистить текстовый блок: администратор стирает текст,
    // сохраняет, текст возвращается из БД.
    livewire(ManageSiteSettings::class)
        ->fillForm(['parts_page.delivery_terms' => ''])
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('parts_page.delivery_terms'))->toBeIn([null, '']);
});

it('stores the promo image chosen by a picker without a relationship', function () {
    $media = Media::factory()->create();

    livewire(ManageSiteSettings::class)
        ->fillForm(['home.promo.image_id' => $media->getKey()])
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('home.promo')['image_id'])->toBe($media->getKey());
});

it('stores the step image chosen by a picker inside a repeater', function () {
    // `MediaPicker` до сих пор вызывался только на верхнем уровне формы,
    // а Filament переносит имена полей внутри репитера. Сторож на то,
    // что собственный компонент проекта это переживает.
    $media = Media::factory()->create();

    $steps = Setting::get('home.steps');
    $steps[0]['image_id'] = $media->getKey();

    livewire(ManageSiteSettings::class)
        ->fillForm(['home.steps' => $steps])
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('home.steps')[0]['image_id'])->toBe($media->getKey())
        // Остальные этапы поле получили, но пустым: форма пишет его
        // безусловно, и шаблон обязан пережить `null`.
        ->and(Setting::get('home.steps')[1]['image_id'])->toBeNull();
});

it('keeps the step image through a resave of the untouched form', function () {
    // Пересохранение нетронутой формы — тот круг «база → гидрация →
    // дегидрация → база», на котором поле внутри репитера теряется
    // молча: ошибки нет, картинка просто пропадает с сайта.
    $media = Media::factory()->create();

    $steps = Setting::get('home.steps');
    $steps[2]['image_id'] = $media->getKey();
    Setting::set('home.steps', $steps);

    livewire(ManageSiteSettings::class)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('home.steps')[2]['image_id'])->toBe($media->getKey());
});

it('logs the changed keys without their values', function () {
    Log::spy();

    livewire(ManageSiteSettings::class)
        ->fillForm(['contacts.email' => 'new@laocars.ru'])
        ->call('save')
        ->assertHasNoErrors();

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Сохранены настройки сайта'
            && $context['changed'] === ['contacts.email']
            // Значения в лог не пишутся — только ключи и автор правки.
            && array_keys($context) === ['actor_id', 'changed'])
        ->once();
});

it('reports nothing as changed when the form is saved untouched', function () {
    // Сторож против шума в логе. Значения-объекты (`home.promo`,
    // `footer.guarantee`) лежат в jsonb, а PostgreSQL порядок ключей
    // в нём не сохраняет: сравнение, чувствительное к порядку, объявляло
    // бы эти настройки изменёнными при каждом сохранении.
    Log::spy();

    livewire(ManageSiteSettings::class)
        ->call('save')
        ->assertHasNoErrors();

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Сохранены настройки сайта'
            && $context['changed'] === [])
        ->once();
});

it('has a key registry matching the seeder', function () {
    // Дешёвый сторож против расхождения: без него добавленный в сид ключ
    // не получает поля, а добавленное поле — значения по умолчанию,
    // и оба провала молчаливые.
    $seederKeys = array_keys(
        (fn (): array => $this->settings())->call(new SiteSettingSeeder),
    );

    expect(array_diff($seederKeys, ManageSiteSettings::settingKeys()))->toBe([])
        ->and(array_diff(ManageSiteSettings::settingKeys(), $seederKeys))->toBe([]);
});

it('keeps the services advantages a list of objects', function () {
    // Формат значения — то, от чего зависит шаблон страницы автосервиса
    // (веха 4.4), и ломается он молча: репитер, дегидрированный в другую
    // форму, даёт `foreach` по неверной структуре уже на проде.
    livewire(ManageSiteSettings::class)
        ->call('save')
        ->assertHasNoErrors();

    $advantages = Setting::get('services_page.advantages');

    expect($advantages)->toHaveCount(4)
        ->and(array_is_list($advantages))->toBeTrue()
        ->and($advantages[0])->toHaveKeys(['number', 'title', 'text']);
});

it('keeps the services notes an object with a field per service category', function () {
    // Описания категорий — ОДНА настройка-объект, а не четыре ключа, и поля
    // формы строятся циклом по енаму. Новая категория, не получившая поля,
    // провалилась бы молча: реестр сверяется с сидом по ключу
    // `services_page.notes` целиком.
    livewire(ManageSiteSettings::class)
        ->call('save')
        ->assertHasNoErrors();

    $notes = Setting::get('services_page.notes');

    expect($notes)->toBeArray()
        ->and(array_is_list($notes))->toBeFalse();

    foreach (ServiceCategory::serviceCategories() as $category) {
        expect($notes)->toHaveKey($category->value);
    }

    // Запчасти живут на своей посадочной странице и описания категории
    // в этой настройке не имеют.
    expect($notes)->not->toHaveKey(ServiceCategory::Parts->value);
});

it('keeps the new home repeaters lists of objects', function () {
    // Форматы, от которых зависят блоки главной вехи 4.6: этапы покупки,
    // состав цены и FAQ. Ломается это молча — репитер, дегидрированный
    // в другую форму, даёт `foreach` по неверной структуре уже на проде.
    //
    // Проверка идёт после сохранения НЕТРОНУТОЙ формы: именно так значение
    // проходит полный круг «сид → заполнение формы → дегидрация → запись»,
    // на котором форма и расходится с сидом.
    livewire(ManageSiteSettings::class)
        ->call('save')
        ->assertHasNoErrors();

    $steps = Setting::get('home.steps');
    $priceBreakdown = Setting::get('home.price_breakdown');
    $faq = Setting::get('home.faq');

    expect($steps)->toHaveCount(6)
        ->and(array_is_list($steps))->toBeTrue()
        ->and($steps[0])->toHaveKeys(['number', 'title', 'text'])
        ->and($priceBreakdown)->toHaveCount(6)
        ->and(array_is_list($priceBreakdown))->toBeTrue()
        ->and($priceBreakdown[0])->toHaveKeys(['title', 'note'])
        ->and($faq)->toHaveCount(6)
        ->and(array_is_list($faq))->toBeTrue()
        ->and($faq[0])->toHaveKeys(['question', 'answer']);
});

it('keeps the about page history a list of objects', function () {
    // Формат, от которого зависит блок истории страницы «О компании»
    // (веха 4.5). Ломается это молча: репитер, дегидрированный в другую
    // форму, даёт `foreach` по неверной структуре уже на проде.
    //
    // Проверка после сохранения НЕТРОНУТОЙ формы — тот же круг
    // «сид → заполнение → дегидрация → запись», на котором форма
    // и расходится с сидом.
    livewire(ManageSiteSettings::class)
        ->call('save')
        ->assertHasNoErrors();

    $history = Setting::get('about_page.history');
    $mission = Setting::get('about_page.mission');

    expect($history)->toHaveCount(4)
        ->and(array_is_list($history))->toBeTrue()
        ->and($history[0])->toHaveKeys(['year', 'title', 'text'])
        // Миссия, наоборот, объект, а не список: одно значение с двумя
        // полями, как `home.promo` и `footer.guarantee`.
        ->and($mission)->toBeArray()
        ->and(array_is_list($mission))->toBeFalse()
        ->and($mission)->toHaveKeys(['title', 'text']);
});

it('stores the showroom photo chosen by a picker in the trust setting', function () {
    // Фотография полосы доверия (веха 4.5) — третий медийный ключ, и форма
    // значения у него объектная (`home.trust.image_id`), а не скалярная:
    // реестр `MediaSettingKeys` разводит ключ и путь, а `data_get()`
    // с пустым путём ссылку внутрь значения не нашёл бы вовсе.
    $media = Media::factory()->create();

    livewire(ManageSiteSettings::class)
        ->fillForm(['home.trust.image_id' => $media->getKey()])
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('home.trust')['image_id'])->toBe($media->getKey())
        // Удалить используемый файл нельзя — иначе в jsonb остался бы
        // висячий id, у которого нет внешнего ключа.
        ->and($media->fresh()->usages())->toContain('Настройки: фотография шоу-рума на главной');
});

it('keeps the media key list pointing at real settings', function () {
    // Список медийных ключей читает `Media::usages()`; ключ, которого нет
    // в реестре страницы, никогда не совпадёт и проверка использования
    // молча пропустит файл.
    //
    // Спрашивается ключ настройки, а не путь внутрь её значения: реестр
    // хранит их раздельно именно потому, что вывести одно из другого
    // нельзя — у репитера граница не совпадает с последней точкой.
    foreach (MediaSettingKeys::settings() as $setting) {
        expect(ManageSiteSettings::settingKeys())->toContain($setting);
    }
});
