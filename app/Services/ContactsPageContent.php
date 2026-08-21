<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Weekday;
use App\Models\Setting;
use App\Support\MapEmbed;
use App\Support\PhoneLink;
use App\Support\SocialLinks;
use App\Support\WorkSchedule;
use Illuminate\Support\Facades\Log;

/**
 * Данные страницы контактов (веха 4.5).
 *
 * Три группы настроек: `contacts.*` (телефон, почта, адрес, расписание,
 * карта), `contacts_page.*` (тексты страницы) и `socials.*`. Прослойка
 * здесь не пустой файл — граница `ARCHITECTURE.md` («если данные надо
 * приводить к форме, прежде чем отдать их в Blade, это сервис, даже если
 * запрос один») пройдена трижды: адреса ссылок `tel:`/`mailto:`, семь
 * строк расписания и разбор адреса карты с фолбэком. Прецедент точный —
 * `AboutPageContent` той же вехи.
 *
 * **Записано затем, чтобы сервис однажды не «упростили» обратно
 * в контроллер.** Ровно это едва не случилось с `AboutPageContent`, и там
 * причина тоже записана в PHPDoc. Противоположный случай, где сервиса нет
 * НАМЕРЕННО, — `PartsController`: там контроллер отдаёт настройки в шаблон
 * как есть, приводить к форме нечего.
 *
 * Микроразметку организации сервис НЕ поглощает: `OrganizationStructuredData`
 * заведён отдельным сервисом вехой 4.14, и словари schema.org внутри сборки
 * страницы оказались бы на уровень глубже, чем нужно, чтобы их найти.
 */
final class ContactsPageContent
{
    /**
     * Заголовок страницы, когда настройка очищена.
     *
     * Константа, а не второй аргумент `Setting::get()`: правило `RULES.md` —
     * умолчание там срабатывает только на ОТСУТСТВУЮЩИЙ ключ, а форма
     * настроек пишет пустое значение как есть. Очищенная настройка дала бы
     * пустой `<h1>` и `<title>` из одного разделителя при живом ключе
     * и без единой ошибки в логе.
     */
    private const string DEFAULT_TITLE = 'Контакты';

    /**
     * @return array{
     *     title: string,
     *     intro: ?string,
     *     routeText: ?string,
     *     address: ?string,
     *     phone: ?string,
     *     phoneHref: ?string,
     *     email: ?string,
     *     scheduleSummary: ?string,
     *     scheduleNote: ?string,
     *     scheduleDays: list<array{day: Weekday, label: string, hours: ?string}>,
     *     socials: list<array{label: string, url: string}>,
     *     mapUrl: ?string,
     *     mapExternalUrl: ?string,
     * }
     */
    public function build(): array
    {
        $contacts = Setting::group('contacts');
        $texts = Setting::group('contacts_page');

        $address = $this->string($contacts['contacts.address'] ?? null);
        $phone = $this->string($contacts['contacts.phone'] ?? null);
        $schedule = WorkSchedule::fromSetting($contacts['contacts.schedule'] ?? null);
        $mapUrl = MapEmbed::resolve(
            $this->string($contacts['contacts.map_embed'] ?? null),
            $address,
        );

        // Одно сообщение на всю сборку, а не по одному на блок: по нему
        // видно, КАКОЙ блок исчез со страницы, — а именно этот вопрос
        // и возникает, когда страница выглядит короче, чем вчера.
        // Образец — `HomeContent::build()` и `AboutPageContent::build()`.
        Log::debug('[Контакты] контент собран', [
            'address' => $address !== null,
            'map' => $mapUrl !== null,
            'schedule' => $schedule->hasWorkingDays(),
            'socials' => count(SocialLinks::from(Setting::group('socials'))),
        ]);

        return [
            'title' => $this->string($texts['contacts_page.intro_title'] ?? null) ?? self::DEFAULT_TITLE,
            'intro' => $this->string($texts['contacts_page.intro_text'] ?? null),
            'routeText' => $this->string($texts['contacts_page.route_text'] ?? null),
            'address' => $address,
            'phone' => $phone,
            'phoneHref' => PhoneLink::href($phone),
            'email' => $this->string($contacts['contacts.email'] ?? null),
            // Собранная строка расписания — та же, что в подвале. Считается
            // ЗДЕСЬ, а не в шаблоне, по двум причинам сразу: Blade не место
            // для вызова, который пишет в лог (`label()` предупреждает
            // о расписании без рабочих дней), и она же служит признаком
            // «блок часов вообще показывать»: `null` означает, что рабочих
            // дней нет.
            'scheduleSummary' => $schedule->label(),
            'scheduleNote' => $schedule->note(),
            // Пустой список — не «нет данных», а «строчить нечего»: либо
            // рабочих дней нет, либо неделя однородна и её описывает одна
            // строка `scheduleSummary`. Разбор — в PHPDoc `WorkSchedule::rows()`.
            'scheduleDays' => $schedule->rows(),
            'socials' => SocialLinks::from(Setting::group('socials')),
            'mapUrl' => $mapUrl,
            'mapExternalUrl' => MapEmbed::externalLink($address),
        ];
    }

    /**
     * Непустая строка или `null`.
     *
     * Проверка пустоты строгая, а не `empty()`: правило `RULES.md` —
     * `empty('0')` в PHP истинно.
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
