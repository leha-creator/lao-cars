<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Models\Setting;
use App\Support\PhoneLink;
use App\Support\SiteMenu;
use App\Support\SocialLinks;
use App\Support\WorkSchedule;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\View\Component;

/**
 * Подвал публичной части (веха 4.1).
 *
 * Как и `SiteHeader`, читает настройки сам — обоснование там же: подвал
 * рендерит layout на каждой странице, и прокидывание контактов из каждого
 * контроллера означало бы, что забытый контроллер даёт подвал без телефона
 * молча и только на одной странице.
 *
 * Состав навигации берётся из общего `SiteMenu` и шире, чем в шапке, ровно
 * на «О компании»: страница есть в ТЗ и в макете, но в закреплённое меню
 * не входит.
 */
final class SiteFooter extends Component
{
    /** @var array<string, mixed> */
    public array $contacts;

    /**
     * Блок гарантии из настроек: заголовок, текст и ссылка.
     *
     * @var array<string, mixed>
     */
    public array $guarantee;

    /**
     * Заполненные соцсети, в порядке макета.
     *
     * @var list<array{label: string, url: string}>
     */
    public array $socials;

    public function __construct()
    {
        $this->contacts = Setting::group('contacts');
        $this->socials = SocialLinks::from(Setting::group('socials'));

        $guarantee = Setting::get('footer.guarantee');
        $this->guarantee = is_array($guarantee) ? $guarantee : [];

        self::warnOnMissingContacts($this->contacts);
    }

    /**
     * @return list<array{name: string, label: string, url: string}>
     */
    public function items(): array
    {
        return SiteMenu::footer();
    }

    public function contact(string $key): ?string
    {
        $value = $this->contacts['contacts.'.$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * Расписание работы (веха 4.14).
     *
     * До неё часы приходили через `contact('work_hours')` — свободной
     * строкой. Настройка стала структурой, и подвал получает её через
     * `WorkSchedule`, а не через `contact()`: значение здесь объект,
     * и приведение его к строке дало бы «Array» в подвале.
     */
    public function schedule(): WorkSchedule
    {
        return WorkSchedule::fromSetting($this->contacts['contacts.schedule'] ?? null);
    }

    public function guaranteeValue(string $key): ?string
    {
        $value = $this->guarantee[$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * Адрес для ссылки `tel:` — без пробелов, скобок и дефисов.
     *
     * Само преобразование переехало в `PhoneLink` (веха 4.5): третьим
     * потребителем стала страница контактов. Метод остался — его зовёт
     * шаблон подвала.
     */
    public function phoneHref(): ?string
    {
        return PhoneLink::href($this->contact('phone'));
    }

    public function render(): View
    {
        return view('components.site-footer');
    }

    /**
     * Контакты, без которых подвал теряет смысл.
     *
     * WARN на каждый рендер — та же плата и то же обоснование, что
     * и у телефона в `SiteHeader`: конфигурационная ошибка чинится один
     * раз, а тихий фолбэк оставил бы сайт без способа связаться, и никто
     * бы этого не заметил.
     *
     * @param  array<string, mixed>  $contacts
     */
    private static function warnOnMissingContacts(array $contacts): void
    {
        $missing = [];

        foreach (['contacts.address', 'contacts.email'] as $key) {
            $value = $contacts[$key] ?? null;

            if ($value === null || $value === '') {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            Log::warning('[Layout] в настройках сайта не заполнены контакты подвала', [
                'settings' => $missing,
            ]);
        }
    }
}
