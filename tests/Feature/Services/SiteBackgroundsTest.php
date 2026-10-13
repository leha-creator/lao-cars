<?php

use App\Models\Media;
use App\Models\Setting;
use App\Services\SiteBackgrounds;
use Illuminate\Support\Facades\Log;

/*
 * Фоновые фотографии из медиабиблиотеки: фон первого экрана главной, фон
 * секции заявки и фотография мастерской.
 *
 * Пустой слот здесь — не ошибка и не пустой блок: шаблон подставляет файл
 * вёрстки. Поэтому главный отказ этого сервиса молчаливый в обе стороны —
 * снимок, который не разрешился, снаружи неотличим от поля, которое никто
 * не заполнял.
 *
 * Кеш настроек сбрасывает глобальный `beforeEach` в `Pest.php`.
 */

it('leaves a slot empty until a photo is chosen', function (mixed $value) {
    // Все четыре формы пустоты достижимы: ключа нет на проде до первого
    // сохранения формы, `null` пишет очищенный пикер, а скаляр и пустая
    // строка приезжают правкой базы в обход формы.
    if ($value !== 'missing') {
        Setting::set('home.hero', $value);
    }

    $resolved = app(SiteBackgrounds::class)->resolve([SiteBackgrounds::HERO]);

    expect($resolved)->toBe([SiteBackgrounds::HERO => null]);
})->with([
    'ключа нет' => ['missing'],
    'значение не объект' => ['строка'],
    'поле очищено' => [['image_id' => null]],
    'пустая строка' => [['image_id' => '']],
    'не число' => [['image_id' => 'abc']],
]);

it('returns the address and the dimensions of the chosen photo', function () {
    $media = Media::factory()->create(['width' => 1920, 'height' => 1080]);

    Setting::set('home.hero', ['image_id' => $media->getKey()]);

    $hero = app(SiteBackgrounds::class)->resolve([SiteBackgrounds::HERO])[SiteBackgrounds::HERO];

    expect($hero)->toBe([
        // Полный файл, а не превью: кадр идёт во всю ширину экрана.
        'url' => $media->url,
        'width' => 1920,
        'height' => 1080,
    ])->and($hero['url'])->not->toBe($media->thumb_url);
});

it('reads every slot from its own setting', function () {
    $hero = Media::factory()->create();
    $lead = Media::factory()->create();
    $panel = Media::factory()->create();

    Setting::set('home.hero', ['image_id' => $hero->getKey()]);
    Setting::set('home.lead', ['image_id' => $lead->getKey()]);
    Setting::set('services_page.panel', ['image_id' => $panel->getKey()]);

    $resolved = app(SiteBackgrounds::class)->resolve([
        SiteBackgrounds::HERO,
        SiteBackgrounds::LEAD,
        SiteBackgrounds::SERVICE_PANEL,
    ]);

    expect($resolved[SiteBackgrounds::HERO]['url'])->toBe($hero->url)
        ->and($resolved[SiteBackgrounds::LEAD]['url'])->toBe($lead->url)
        ->and($resolved[SiteBackgrounds::SERVICE_PANEL]['url'])->toBe($panel->url);
});

it('resolves three slots with a single query', function () {
    Setting::set('home.hero', ['image_id' => Media::factory()->create()->getKey()]);
    Setting::set('home.lead', ['image_id' => Media::factory()->create()->getKey()]);
    Setting::set('services_page.panel', ['image_id' => Media::factory()->create()->getKey()]);

    $slots = [SiteBackgrounds::HERO, SiteBackgrounds::LEAD, SiteBackgrounds::SERVICE_PANEL];

    // Прогрев: первый вызов платит за промах кеша настроек.
    app(SiteBackgrounds::class)->resolve($slots);

    $queries = countQueries(fn () => app(SiteBackgrounds::class)->resolve($slots));

    // Ровно один — это и нижняя граница из `RULES.md` (счётчик, не поймавший
    // ничего, дал бы ноль), и верхняя: `find()` на слот дал бы три.
    expect($queries)->toBe(1);
});

it('makes no query when no slot has a photo', function () {
    Setting::set('home.hero', ['image_id' => null]);
    Setting::set('home.lead', ['image_id' => null]);

    $slots = [SiteBackgrounds::HERO, SiteBackgrounds::LEAD, SiteBackgrounds::SERVICE_PANEL];

    app(SiteBackgrounds::class)->resolve($slots);

    // Сторож холостого прохода: тот же замер на заполненных слотах выше
    // даёт единицу, то есть счётчик запросы видит.
    expect(countQueries(fn () => app(SiteBackgrounds::class)->resolve($slots)))->toBe(0);
});

it('falls back and warns when the chosen photo is gone from the library', function () {
    // Штатным путём сюда не попасть — удаление используемого файла блокирует
    // `Media::usages()`. Значит запись пропала в обход админки, а на сайте
    // при этом просто стоит прежняя фотография: без WARN отказ не виден.
    Log::spy();

    $media = Media::factory()->create();
    $id = $media->getKey();
    $media->forceDelete();

    $kept = Media::factory()->create();

    Setting::set('home.hero', ['image_id' => $id]);
    Setting::set('home.lead', ['image_id' => $kept->getKey()]);

    $resolved = app(SiteBackgrounds::class)->resolve([SiteBackgrounds::HERO, SiteBackgrounds::LEAD]);

    // Пропавший снимок не уносит соседний слот.
    expect($resolved[SiteBackgrounds::HERO])->toBeNull()
        ->and($resolved[SiteBackgrounds::LEAD]['url'])->toBe($kept->url);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'фон первого экрана главной')
            && $context['setting'] === 'home.hero.image_id'
            && $context['media_id'] === $id)
        ->once();
});

it('refuses an unknown slot instead of silently falling back', function () {
    // Опечатка в вызове иначе дала бы вечный файл вёрстки, неотличимый
    // от пустого поля в панели.
    app(SiteBackgrounds::class)->resolve(['footer']);
})->throws(InvalidArgumentException::class);
