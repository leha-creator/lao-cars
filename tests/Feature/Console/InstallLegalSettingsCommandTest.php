<?php

use App\Models\Setting;
use App\Support\Legal\LeadIntake;
use App\Support\Legal\PrivacyPolicy;

/*
 * Установка юридических настроек на проде (веха 4.17).
 *
 * Команда существует потому, что деплой выполняет только
 * `php artisan migrate --force`: сиды на прод не выкатываются, и без этого
 * шага `/privacy` отдавала бы пустой юридический документ.
 *
 * Главное её свойство — идемпотентность. Команда, которая «обновляет»
 * настройки, при следующем деплое вернёт отредактированный юристом текст
 * обратно на заглушку с незаполненными реквизитами. Молча и на живом сайте.
 */

it('creates the missing legal settings', function () {
    // База тестов чистая: сид здесь намеренно не запускается — проверяется
    // ровно тот случай, в котором команда и нужна.
    expect(Setting::get(PrivacyPolicy::SETTING_KEY))->toBeNull();

    $this->artisan('laocars:install-legal-settings')->assertSuccessful();

    Setting::flushCache();

    expect(Setting::get(PrivacyPolicy::SETTING_KEY))->toBe(PrivacyPolicy::defaultSetting())
        ->and(Setting::get(LeadIntake::SETTING_KEY))->toBeFalse();
});

it('leaves lead intake switched off', function () {
    // Включает его человек — после того, как уведомление подано
    // в Роскомнадзор. Команда, включающая приём, обнулила бы весь смысл
    // выключателя ровно в момент выката.
    $this->artisan('laocars:install-legal-settings')->assertSuccessful();

    Setting::flushCache();

    expect(LeadIntake::enabled())->toBeFalse();
});

it('never overwrites an existing policy text', function () {
    // Самый дорогой из возможных промахов: повторный запуск при следующем
    // деплое затёр бы правки юриста.
    Setting::set(PrivacyPolicy::SETTING_KEY, [
        'body' => '<p>Текст, отредактированный юристом.</p>',
        'version' => '5.0',
        'effective_on' => '01.12.2026',
    ]);

    $this->artisan('laocars:install-legal-settings')->assertSuccessful();

    Setting::flushCache();

    expect(Setting::get(PrivacyPolicy::SETTING_KEY)['body'])->toBe('<p>Текст, отредактированный юристом.</p>')
        ->and(Setting::get(PrivacyPolicy::SETTING_KEY)['version'])->toBe('5.0');
});

it('does not switch intake back off once it was enabled', function () {
    // `false` — полноценное значение настройки, и проверка «пусто ли»
    // посчитала бы его отсутствующим. Тогда каждый деплой выключал бы
    // приём заявок на работающем сайте.
    Setting::set(LeadIntake::SETTING_KEY, true);

    $this->artisan('laocars:install-legal-settings')->assertSuccessful();

    Setting::flushCache();

    expect(LeadIntake::enabled())->toBeTrue();
});
