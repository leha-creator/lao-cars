<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Соцсети компании — подписи и порядок вывода (веха 4.1).
 *
 * Живёт в `app/Support/` по тому же правилу, что и `SiteMenu`: словарь нужен
 * и подвалу, и странице контактов, а выводить подпись из адреса («t.me»,
 * «wa.me») значит показывать посетителю имя хоста вместо названия сервиса.
 *
 * Порядок задаёт сам словарь, а не порядок строк в `site_settings`.
 */
final class SocialLinks
{
    /**
     * «ключ настройки => подпись».
     *
     * @var array<string, string>
     */
    private const array LABELS = [
        'socials.telegram' => 'Telegram',
        'socials.whatsapp' => 'WhatsApp',
        'socials.vk' => 'ВКонтакте',
    ];

    /**
     * Заполненные соцсети из группы настроек.
     *
     * Незаполненная не выводится вовсе: ссылка на пустой адрес хуже
     * отсутствующей — она выглядит рабочей и ведёт на текущую страницу.
     * Предупреждения здесь нет намеренно, в отличие от контактов: соцсети
     * необязательны, компания может не вести VK, и WARN о «незаполненном»
     * поле был бы шумом, а не сигналом.
     *
     * @param  array<string, mixed>  $settings  результат `Setting::group('socials')`
     * @return list<array{label: string, url: string}>
     */
    public static function from(array $settings): array
    {
        $links = [];

        foreach (self::LABELS as $key => $label) {
            $url = $settings[$key] ?? null;

            if ($url !== null && $url !== '') {
                $links[] = ['label' => $label, 'url' => (string) $url];
            }
        }

        return $links;
    }
}
