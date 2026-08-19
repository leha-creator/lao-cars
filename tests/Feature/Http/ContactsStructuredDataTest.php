<?php

use App\Models\Setting;
use App\Support\WorkSchedule;

/*
 * Микроразметка организации на странице контактов (веха 4.14).
 *
 * Появилась вместе со структурным расписанием и ради него: часы работы
 * — единственное место, где выигрыш от структуры виден снаружи,
 * а не только в форме админки. По свободной строке «Пн–Вс, 9:00–21:00»
 * такой разметки не собрать в принципе.
 *
 * Проверяется ФОРМА ДАННЫХ, а не подстроки HTML — по образцу
 * `CarStructuredDataTest` и по той же причине: тест на подстроки прошёл бы
 * и на сломанном JSON, и на ключе со значением `null`, а именно эти две
 * поломки валидатор Google и считает ошибками.
 *
 * Соответствие требованиям Google этот файл не проверяет и проверить
 * не может: обязательные поля знает только валидатор. Прогон через
 * Rich Results Test входит в приёмку вехи.
 */

/**
 * Разметка организации, разобранная из единственного тега JSON-LD.
 *
 * @return array<string, mixed>
 */
function organizationJsonLd(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

    // Тег обязан быть один: два скрипта с разными версиями одних и тех же
    // данных — классический способ получить в выдаче не то, что ожидалось.
    expect($matches[1])->toHaveCount(1);

    return json_decode($matches[1][0], true, flags: JSON_THROW_ON_ERROR);
}

it('describes the organization with its opening hours', function () {
    Setting::set('contacts.phone', '+7 495 000-00-00');
    Setting::set('contacts.email', 'test@laocars.test');
    Setting::set('contacts.address', 'Москва, Тестовая, 1');

    $value = WorkSchedule::defaultSetting('09:00', '19:00');
    $value['days']['sun'] = ['closed' => true];
    Setting::set('contacts.schedule', $value);
    Setting::flushCache();

    $data = organizationJsonLd($this->get('/contacts')->assertOk()->getContent());

    expect($data['@type'])->toBe('LocalBusiness')
        ->and($data['telephone'])->toBe('+7 495 000-00-00')
        ->and($data['email'])->toBe('test@laocars.test')
        ->and($data['address']['@type'])->toBe('PostalAddress')
        ->and($data['address']['streetAddress'])->toBe('Москва, Тестовая, 1')
        ->and($data['openingHoursSpecification'])->toHaveCount(1)
        // Воскресенье выходное и в спецификацию не попадает.
        ->and($data['openingHoursSpecification'][0]['dayOfWeek'])->toHaveCount(6)
        ->and($data['openingHoursSpecification'][0]['opens'])->toBe('09:00')
        ->and($data['openingHoursSpecification'][0]['closes'])->toBe('19:00');
});

it('omits an unfilled contact instead of publishing it as empty', function () {
    // Ключ со значением `null` в JSON-LD — это заявление «у организации
    // нет телефона», а не «мы его не указали». Разницу видит агрегатор,
    // а не человек, и исправлять её потом некому.
    Setting::set('contacts.email', '');
    Setting::set('contacts.address', '');
    Setting::flushCache();

    $data = organizationJsonLd($this->get('/contacts')->assertOk()->getContent());

    expect($data)->not->toHaveKey('email')
        ->and($data)->not->toHaveKey('address');
});

it('drops the opening hours entirely when the schedule has no working day', function () {
    // Пустой массив часов — тоже заявление, и ложное: «организация
    // не открыта никогда».
    Setting::set('contacts.schedule', ['days' => [], 'note' => null]);
    Setting::flushCache();

    $data = organizationJsonLd($this->get('/contacts')->assertOk()->getContent());

    expect($data)->not->toHaveKey('openingHoursSpecification');
});

it('escapes a closing script tag typed into the address', function () {
    // Единственное место страницы, где данные из базы попадают в HTML
    // без экранирования Blade, — и потому единственное, где XSS вообще
    // возможен. Правило `RULES.md`: `@json` с `JSON_HEX_TAG`. Без флага
    // закрывающий тег, вписанный администратором в адрес, закрывает
    // скрипт по-настоящему и превращает остаток страницы в разметку.
    Setting::set('contacts.address', 'Москва</script><script>alert(1)</script>');
    Setting::flushCache();

    $html = $this->get('/contacts')->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>');

    // И разметка при этом осталась валидным JSON, а не обрывком.
    expect(organizationJsonLd($html)['address']['streetAddress'])
        ->toBe('Москва</script><script>alert(1)</script>');
});

it('shows the assembled schedule and its note on the page itself', function () {
    $value = WorkSchedule::defaultSetting();
    $value['note'] = 'в праздничные дни по записи';
    Setting::set('contacts.schedule', $value);
    Setting::flushCache();

    $this->get('/contacts')
        ->assertOk()
        // Строка собирается кодом из той же настройки, что и разметка:
        // источник один, и разойтись им негде.
        ->assertSee('Без выходных, 9:00–21:00')
        ->assertSee('в праздничные дни по записи');
});
