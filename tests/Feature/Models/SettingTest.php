<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

it('stores and reads a scalar setting', function () {
    Setting::set('contacts.phone', '+7 495 123-45-67');

    expect(Setting::get('contacts.phone'))->toBe('+7 495 123-45-67');
});

it('stores and reads a structured setting', function () {
    // Значения разнородны: от строки телефона до массива карточек.
    Setting::set('home.advantages', [
        ['number' => '01', 'title' => 'Прямые контракты'],
        ['number' => '02', 'title' => 'Юридическое сопровождение'],
    ]);

    $advantages = Setting::get('home.advantages');

    expect($advantages)->toBeArray()
        ->toHaveCount(2)
        ->and($advantages[0]['title'])->toBe('Прямые контракты');
});

it('returns the default value for a missing key', function () {
    expect(Setting::get('contacts.fax', 'нет'))->toBe('нет');
});

it('derives the group from the key', function () {
    Setting::set('seo.default_title', 'ЛАО КАРС');

    expect(DB::table('site_settings')->value('group'))->toBe('seo');
});

it('falls back to the general group for a key without a prefix', function () {
    Setting::set('maintenance_mode', false);

    expect(DB::table('site_settings')->value('group'))->toBe('general');
});

it('returns every setting of a group', function () {
    Setting::set('contacts.phone', '+7 495 123-45-67');
    Setting::set('contacts.email', 'info@laocars.ru');
    Setting::set('seo.default_title', 'ЛАО КАРС');

    expect(Setting::group('contacts'))->toBe([
        'contacts.phone' => '+7 495 123-45-67',
        'contacts.email' => 'info@laocars.ru',
    ]);
});

it('serves reads from the cache instead of the database', function () {
    Setting::set('contacts.phone', '+7 495 123-45-67');
    Setting::get('contacts.phone'); // прогрев кеша

    // Правка в обход модели не поднимает событий, значит кеш не сброшен.
    // Если бы чтение шло в БД, здесь вернулось бы новое значение.
    DB::table('site_settings')->update(['value' => json_encode('изменено мимо модели')]);

    expect(Setting::get('contacts.phone'))->toBe('+7 495 123-45-67');

    Setting::flushCache();

    expect(Setting::get('contacts.phone'))->toBe('изменено мимо модели');
});

it('drops the cache when a setting is saved', function () {
    Setting::set('contacts.phone', '+7 495 123-45-67');
    Setting::get('contacts.phone');

    expect(Cache::get(Setting::CACHE_KEY))->not->toBeNull();

    // Без сброса администратор сохраняет настройку и не видит изменений.
    Setting::set('contacts.phone', '+7 495 000-00-00');

    expect(Cache::get(Setting::CACHE_KEY))->toBeNull()
        ->and(Setting::get('contacts.phone'))->toBe('+7 495 000-00-00');
});

it('drops the cache when a setting is deleted', function () {
    Setting::set('contacts.phone', '+7 495 123-45-67');
    Setting::get('contacts.phone');

    Setting::query()->where('key', 'contacts.phone')->first()->delete();

    expect(Cache::get(Setting::CACHE_KEY))->toBeNull()
        ->and(Setting::get('contacts.phone'))->toBeNull();
});

it('updates an existing key instead of duplicating it', function () {
    Setting::set('contacts.phone', '+7 495 123-45-67');
    Setting::set('contacts.phone', '+7 495 000-00-00');

    expect(Setting::query()->where('key', 'contacts.phone')->count())->toBe(1);
});
