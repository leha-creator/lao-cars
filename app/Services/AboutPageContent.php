<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\Review;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;

/**
 * Данные страницы «О компании» (веха 4.5).
 *
 * Три источника: группа настроек `about_page.*`, опубликованные сотрудники
 * и опубликованные отзывы. Прослойка здесь не пустой файл — граница
 * `ARCHITECTURE.md` («если данные надо приводить к форме, прежде чем отдать
 * их в Blade, это сервис, даже если запрос один») пройдена дважды:
 * нормализация репитера истории и настройки-объекта миссии. Прецедент
 * точный — `HomeContent` и `ServicesPageContent`; противоположный случай,
 * где сервиса нет НАМЕРЕННО, — `PartsController`.
 *
 * **Первый потребитель `Employee`.** Модель и панель к ней заведены вехами
 * 3.2 и 3.5 авансом под эту веху: до неё сотрудники не выводились нигде.
 * Отзывы, в отличие от них, второго потребителя получают — первым была
 * главная (веха 4.6).
 *
 * Нормализация живёт здесь по той же причине, что и в `HomeContent`: форма
 * настроек (веха 3.5) пишет свои ключи безусловно, даже пустыми — «очистить
 * блок» там рабочий сценарий, — а репитер при удалении всех элементов
 * отдаёт `null`, а не `[]`. Шаблон, написанный по сиду, падает на первом же
 * `foreach (null)`, и падает на проде.
 */
final class AboutPageContent
{
    /**
     * @return array{
     *     title: string,
     *     intro: ?string,
     *     mission: ?array{title: ?string, text: ?string},
     *     history: list<array{year: ?string, title: string, text: ?string}>,
     *     employees: EloquentCollection<int, Employee>,
     *     reviews: EloquentCollection<int, Review>,
     * }
     */
    public function build(): array
    {
        $employees = $this->employees();
        $reviews = $this->reviews();
        $history = $this->history();

        // Одно сообщение на всю сборку, а не по одному на блок: по нему
        // видно, КАКОЙ блок исчез со страницы, — а именно этот вопрос
        // и возникает, когда страница выглядит короче, чем вчера.
        // Образец — `HomeContent::build()`.
        Log::debug('[О компании] контент собран', [
            'history' => count($history),
            'employees' => $employees->count(),
            'reviews' => $reviews->count(),
        ]);

        return [
            // Фолбэк на ОЧИЩЕННЫЙ заголовок, а не только на отсутствующий
            // ключ: правило `RULES.md` — второй аргумент `Setting::get()`
            // срабатывает лишь когда строки нет вовсе, а форма настроек
            // пишет пустое значение как есть. Без этого очищенное поле
            // дало бы пустой `<h1>` и `<title>` из одного разделителя —
            // страница выглядела бы сломанной при живой настройке и без
            // единой ошибки в логе. Парная нормализация —
            // в `ServicesPageContent::build()` и `PartsController`.
            'title' => $this->string(Setting::get('about_page.intro_title')) ?? 'О компании',
            'intro' => $this->string(Setting::get('about_page.intro_text')),
            'mission' => $this->mission(),
            'history' => $history,
            'employees' => $employees,
            'reviews' => $reviews,
        ];
    }

    /**
     * Миссия — настройка-объект или `null`, если очищены оба поля.
     *
     * Форма значения и правило те же, что у `home.promo`: пустой заголовок
     * при заполненном тексте (и наоборот) блок оставляет, пустые оба —
     * убирают. Карточка из одного заголовка над пустотой читается
     * как поломка, а не как «не дописали».
     *
     * @return ?array{title: ?string, text: ?string}
     */
    private function mission(): ?array
    {
        $mission = Setting::get('about_page.mission');

        if (! is_array($mission)) {
            return null;
        }

        $title = $this->string($mission['title'] ?? null);
        $text = $this->string($mission['text'] ?? null);

        if ($title === null && $text === null) {
            return null;
        }

        return ['title' => $title, 'text' => $text];
    }

    /**
     * История компании: список вех.
     *
     * Форма элемента намеренно повторяет `home.advantages` и
     * `services_page.advantages` — «номер, заголовок, текст», — и первое
     * поле переименовано в `year` только ради подписи в админке: «Год»
     * человеку понятнее, чем «Номер». Элемент без заголовка выпадает
     * по тому же правилу, что и там: карточка из одного года выглядит
     * как поломка вёрстки, а не как незаполненное поле.
     *
     * @return list<array{year: ?string, title: string, text: ?string}>
     */
    private function history(): array
    {
        $history = [];

        foreach ($this->listFrom(Setting::get('about_page.history')) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = $this->string($item['title'] ?? null);

            if ($title === null) {
                continue;
            }

            $history[] = [
                'year' => $this->string($item['year'] ?? null),
                'title' => $title,
                'text' => $this->string($item['text'] ?? null),
            ];
        }

        return $history;
    }

    /**
     * Команда — опубликованные сотрудники в заданном порядке.
     *
     * `with('media')` обязателен, и это не оптимизация «на всякий случай»:
     * PHPDoc `Employee::photoUrl()` требует его дословно, а без него сетка
     * команды даёт запрос на каждую карточку — антипаттерн N+1
     * из `ARCHITECTURE.md`. Сторож стоит в `AboutPageTest`.
     *
     * Пустая выдача убирает секцию «Команда» целиком (решение 9 плана,
     * оно же правило проекта о блоках, управляемых данными) — и это тот
     * случай, когда WARN нужен: сотрудники заведены вехой 3.2, панель
     * к ним есть, и пустой список означает незаполненную админку, а не
     * выключенный блок. Снаружи страница при этом отдаёт 200 и выглядит
     * работающей, поэтому заметить отказ больше нечем. То же основание,
     * что у пустого прайса в `ServicesPageContent`.
     *
     * @return EloquentCollection<int, Employee>
     */
    private function employees(): EloquentCollection
    {
        $employees = Employee::query()
            ->published()
            ->ordered()
            ->with('media')
            ->get();

        if ($employees->isEmpty()) {
            Log::warning('[О компании] на странице нет ни одного опубликованного сотрудника — блок «Команда» не выведен', [
                'hint' => 'опубликуйте карточки в разделе «Команда» админки',
            ]);
        }

        return $employees;
    }

    /**
     * Отзывы — ВСЕ опубликованные, без лимита главной.
     *
     * Лимит `home.reviews_limit` сюда не переиспользуется, и это записано
     * в самом конфиге, который его заводит: `config/home.php` объясняет
     * выбор тройки дословно — «Полный список отзывов — задача страницы
     * „О компании“, а не главной». Взять его сюда значило бы противоречить
     * обоснованию собственного конфига.
     *
     * Общими с главной остаются модель, скоуп и `with('media')`: второй
     * способ выбирать отзывы разошёлся бы с первым, и посетитель увидел бы
     * на двух страницах разный порядок. Разным остаётся только объём.
     *
     * Если потолок здесь всё же понадобится (сотня отзывов на одной
     * странице), он заводится СВОИМ ключом `about.reviews_limit`,
     * а комментарий `config/home.php` правится тем же коммитом: два
     * противоречащих утверждения в конфиге и в коде переживут любого,
     * и победит то, которое прочтут вторым.
     *
     * WARN здесь не пишется, в отличие от команды: отзывов может
     * не быть просто потому, что их ещё не собрали, — то же решение,
     * что и на главной.
     *
     * @return EloquentCollection<int, Review>
     */
    private function reviews(): EloquentCollection
    {
        return Review::query()
            ->published()
            ->ordered()
            ->with('media')
            ->get();
    }

    /**
     * Значение репитера настроек в виде списка.
     *
     * Удаление всех элементов в форме настроек даёт `null`, а не `[]`, —
     * это и есть причина, по которой нормализация живёт в сервисе.
     *
     * @return list<mixed>
     */
    private function listFrom(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
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
