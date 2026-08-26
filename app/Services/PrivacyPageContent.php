<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use App\Support\Legal\PrivacyPolicy;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Данные страницы политики обработки персональных данных (веха 4.17).
 *
 * Сервис, а не чтение прямо из контроллера, — граница `ARCHITECTURE.md`
 * («если данные надо приводить к форме, прежде чем отдать их в Blade, это
 * сервис, даже если запрос один») пройдена трижды: тело проверяется на
 * пустоту строгим сравнением, версия и дата приводятся к `null` вместо
 * пустых строк, а текст просматривается на незаполненные реквизиты. Ни одну
 * из трёх проверок Blade делать не должен и не умеет.
 *
 * Прецедент противоположного случая — `PartsController`, который читает
 * настройки сам: там приводить к форме нечего. Записано затем, чтобы сервис
 * однажды не «упростили» обратно в контроллер, как едва не случилось
 * с `AboutPageContent`.
 */
final class PrivacyPageContent
{
    /**
     * Заголовок страницы.
     *
     * Константа, а не настройка: заголовок юридического документа не тот
     * текст, который правят из панели ради красоты, — по нему документ
     * опознают. Правится он вместе с самим текстом, то есть коммитом.
     */
    public const string TITLE = 'Политика обработки персональных данных';

    /**
     * @return array{body: ?string, version: ?string, effectiveOn: ?string}
     */
    public function build(): array
    {
        $setting = Setting::get(PrivacyPolicy::SETTING_KEY);

        $body = self::sanitized(self::text(data_get($setting, 'body')));
        $version = self::text(data_get($setting, 'version'));
        $effectiveOn = self::text(data_get($setting, 'effective_on'));

        Log::debug('[Политика] собрана страница', [
            'version' => $version,
            'effective_on' => $effectiveOn,
            'body_length' => $body === null ? 0 : mb_strlen($body),
        ]);

        if ($body === null) {
            // 404 здесь запрещён: на этот адрес ведут подвал и чекбокс
            // согласия в каждой форме сайта, и битая обязательная ссылка
            // хуже пустой страницы. Страница отдаёт 200 с заголовком,
            // а блок текста не рендерится вовсе — правило проекта «блок
            // без данных не выводится».
            Log::warning('[Политика] текст политики пуст — на сайте опубликован пустой юридический документ');
        } elseif (Str::contains($body, PrivacyPolicy::PLACEHOLDER_MARK)) {
            // Единственный автоматический способ узнать, что документ уехал
            // в прод с `[[ИНН]]` внутри: страницу открывают редко, а читают
            // ещё реже. Проверка идёт на каждый рендер осознанно — страница
            // одна, текст в памяти, а альтернатива (проверка при сохранении)
            // молчала бы про документ, который никто с тех пор не открывал.
            Log::warning('[Политика] в тексте остались незаполненные реквизиты', [
                'placeholders' => self::placeholders($body),
            ]);
        }

        return [
            'body' => $body,
            'version' => $version,
            'effectiveOn' => $effectiveOn,
        ];
    }

    /**
     * Незаполненные реквизиты, найденные в тексте.
     *
     * Нужны в логе именно списком: запись «остались плейсхолдеры» без имён
     * заставляет открывать страницу и искать их глазами, а имя реквизита —
     * это готовый вопрос заказчику.
     *
     * @return list<string>
     */
    private static function placeholders(string $body): array
    {
        preg_match_all('/\[\[[^\]]{1,80}\]\]/u', $body, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * Текст, пропущенный через санитайзер редактора.
     *
     * **Это не перестраховка, а прямое требование Filament.** В шапке
     * `Filament\Forms\Components\RichEditor` записано дословно: значение
     * поля — сырой HTML, его можно перехватить и отправить на бэкенд любым,
     * поэтому в Blade его печатают только через `RichContentRenderer` либо
     * `sanitizeHtml()`, и никогда как `{!! $content !!}` без обработки.
     * Ограниченный набор инструментов на вкладке настроек ЭТОГО НЕ ЗАМЕНЯЕТ:
     * тулбар живёт в браузере, а запрос уходит с любым телом.
     *
     * Рендерер пересобирает разметку из разобранного документа TipTap, то
     * есть на выходе остаются только известные ему узлы и метки. Для текста,
     * написанного через редактор, это тождественное преобразование — что
     * подтверждает сторож `it('reports nothing as changed when the form is
     * saved untouched')`: канонический вид совпадает с хранимым.
     */
    private static function sanitized(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        return RichContentRenderer::make($body)->toHtml();
    }

    /**
     * Непустая строка или `null`.
     *
     * Пустота проверяется строго (`=== ''`), а не через `empty()` — правило
     * `RULES.md`. И проверяется здесь, а не вторым аргументом
     * `Setting::get()`: тот срабатывает только на ОТСУТСТВУЮЩИЙ ключ, а
     * форма настроек пишет очищенное поле как есть, и «очистить блок» там
     * рабочий сценарий.
     */
    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
