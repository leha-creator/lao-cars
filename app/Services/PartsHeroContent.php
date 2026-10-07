<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Media;
use App\Models\Setting;
use App\Support\OutboundLink;
use App\Support\Typography;
use Illuminate\Support\Facades\Log;

/**
 * Первый экран страницы запчастей: фон, заголовок, описание и кнопка
 * в каталог автозапчастей на другом сервисе.
 *
 * Блок один, а страниц у него две: `/parts` показывает его всегда, когда
 * он заполнен, главная — когда включён переключатель «Показывать на главной».
 * Тексты общие — отдельного набора для главной нет.
 *
 * **Почему сервис есть у блока и по-прежнему нет у страницы запчастей.**
 * `PartsController` читает модель напрямую, и его PHPDoc объясняет почему:
 * там нечего приводить к форме. Здесь есть что — объект настройки
 * нормализуется, `image_id` разрешается в URL, адрес кнопки проверяется, —
 * и нужно это двум потребителям сразу (`PartsController` и `HomeContent`).
 * Граница та же, что названа в `ARCHITECTURE.md`: данные надо приводить
 * к форме до Blade — значит, это сервис. `PartsPageContent` «для порядка»
 * от этого нужнее не стал.
 *
 * Сервис без состояния и без `Request`; потребители получают его через
 * контейнер, а не `new` — иначе его не подменить в тесте.
 */
final class PartsHeroContent
{
    public const string SETTING_KEY = 'parts_page.hero';

    /**
     * Блок — или `null`, если показывать нечего.
     *
     * Блок существует, только когда заполнен заголовок: без него нет H1,
     * а первый экран из одной фотографии читается как поломка. Фон,
     * описание и кнопка необязательны.
     *
     * `null` на `/parts` означает «страница с прежним заголовком из
     * `parts_page.intro_title`», на главной — «секции нет». Отсюда же
     * безопасность выката: на проде ключа нет, пока форму настроек
     * не сохранили, и страница до этого момента не меняется.
     *
     * @return ?array{
     *     title: string,
     *     text: ?string,
     *     button: ?array{text: string, url: string, external: bool},
     *     image_url: ?string,
     *     show_on_home: bool,
     * }
     */
    public function build(): ?array
    {
        $hero = Setting::get(self::SETTING_KEY);

        if (! is_array($hero)) {
            Log::debug('[Запчасти] первый экран не настроен', ['setting' => self::SETTING_KEY]);

            return null;
        }

        $title = $this->string($hero['title'] ?? null);

        // Строго `=== true`: значение приходит из jsonb, и приведение
        // к булеву сделало бы включённым показ по строке `'0'` или `'false'`.
        $showOnHome = ($hero['show_on_home'] ?? null) === true;

        if ($title === null) {
            if ($showOnHome) {
                // Противоречие в настройках, а не выключенный блок:
                // администратор включил показ того, чего нет. Снаружи это
                // неотличимо от выключенного переключателя.
                Log::warning('[Запчасти] показ первого экрана на главной включён, а заголовок пуст', [
                    'setting' => self::SETTING_KEY,
                    'hint' => 'заполните заголовок в секции «Запчасти: первый экран» настроек сайта',
                ]);
            }

            Log::debug('[Запчасти] первый экран без заголовка — блок не собирается', [
                'show_on_home' => $showOnHome,
            ]);

            return null;
        }

        $button = $this->button($hero);
        $imageUrl = $this->imageUrl($hero['image_id'] ?? null);

        Log::debug('[Запчасти] первый экран собран', [
            'has_image' => $imageUrl !== null,
            'has_button' => $button !== null,
            'show_on_home' => $showOnHome,
        ]);

        return [
            'title' => $title,
            // Описание — проза и проходит типографику; заголовок нет:
            // он короткий, и склейка хвоста ему не нужна.
            'text' => Typography::tie($this->string($hero['text'] ?? null)),
            'button' => $button,
            'image_url' => $imageUrl,
            'show_on_home' => $showOnHome,
        ];
    }

    /**
     * Кнопка — или `null` без подписи, без адреса или с негодным адресом.
     *
     * Кнопка с пустым `href` уводит на текущую страницу и при этом выглядит
     * рабочей — то же правило, что у кнопки промо-блока.
     *
     * @param  array<array-key, mixed>  $hero
     * @return ?array{text: string, url: string, external: bool}
     */
    private function button(array $hero): ?array
    {
        $text = $this->string($hero['button_text'] ?? null);
        $url = $this->string($hero['button_url'] ?? null);

        if ($url !== null && ! OutboundLink::isAllowed($url)) {
            // Форма такой адрес не принимает — значит, он приехал мимо неё.
            // Сам адрес в лог не идёт: его содержимое пишет кто угодно
            // с доступом к базе, а логи читают глазами и грепом.
            Log::warning('[Запчасти] адрес кнопки первого экрана отклонён', [
                'setting' => self::SETTING_KEY.'.button_url',
                'hint' => 'нужен полный адрес, начинающийся с https://',
            ]);

            return null;
        }

        if ($text === null || $url === null) {
            return null;
        }

        return [
            'text' => $text,
            'url' => $url,
            'external' => OutboundLink::isExternal($url),
        ];
    }

    /**
     * Фон из медиабиблиотеки — URL записи или `null`.
     *
     * `url`, а не `thumb_url`: кадр идёт во всю ширину экрана, и превью
     * шириной 600 растянулось бы в мыло.
     */
    private function imageUrl(mixed $mediaId): ?string
    {
        // Пустота проверяется строго — правило `RULES.md`: `empty()`
        // истинно и для нуля.
        if ($mediaId === null || $mediaId === '') {
            return null;
        }

        $media = Media::query()->find($mediaId);

        if ($media === null) {
            // Штатным путём сюда не попасть: удаление используемой записи
            // блокирует `Media::usages()` через реестр `MediaSettingKeys`.
            // Значит, запись пропала в обход админки, и это стоит WARN —
            // снаружи отказ неотличим от незаполненного поля: блок просто
            // останется на тёмной заливке.
            Log::warning('[Запчасти] фон первого экрана ссылается на удалённую запись медиабиблиотеки', [
                'setting' => self::SETTING_KEY.'.image_id',
                'media_id' => $mediaId,
            ]);

            return null;
        }

        return $media->url;
    }

    /**
     * Непустая строка или `null`.
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
