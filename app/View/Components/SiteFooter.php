<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Models\Setting;
use App\Support\SiteMenu;
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

    /**
     * Подписи соцсетей по ключам настроек. Порядок задаёт вывод.
     *
     * @var array<string, string>
     */
    private const array SOCIAL_LABELS = [
        'socials.telegram' => 'Telegram',
        'socials.whatsapp' => 'WhatsApp',
        'socials.vk' => 'ВКонтакте',
    ];

    public function __construct()
    {
        $this->contacts = Setting::group('contacts');
        $this->socials = self::resolveSocials();

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

    public function guaranteeValue(string $key): ?string
    {
        $value = $this->guarantee[$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * Адрес для ссылки `tel:` — без пробелов, скобок и дефисов.
     */
    public function phoneHref(): ?string
    {
        $phone = $this->contact('phone');

        return $phone === null ? null : preg_replace('/[^\d+]/', '', $phone);
    }

    public function render(): View
    {
        return view('components.site-footer');
    }

    /**
     * Пустая соцсеть не выводится вовсе: ссылка на пустой адрес хуже
     * отсутствующей — она выглядит рабочей и ведёт на текущую страницу.
     *
     * Предупреждения здесь нет намеренно, в отличие от контактов: соцсети
     * необязательны, компания может не вести VK, и WARN о «незаполненном»
     * поле был бы шумом, а не сигналом.
     *
     * @return list<array{label: string, url: string}>
     */
    private static function resolveSocials(): array
    {
        $stored = Setting::group('socials');
        $socials = [];

        // Обход идёт по словарю подписей, а не по настройкам: порядок вывода
        // задаёт макет, а не порядок строк в таблице.
        foreach (self::SOCIAL_LABELS as $key => $label) {
            $url = $stored[$key] ?? null;

            if ($url !== null && $url !== '') {
                $socials[] = ['label' => $label, 'url' => (string) $url];
            }
        }

        return $socials;
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
