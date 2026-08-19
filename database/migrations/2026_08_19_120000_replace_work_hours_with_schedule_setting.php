<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Свободная строка часов работы заменяется структурным расписанием
 * по дням недели — веха 4.14.
 *
 * До этой миграции часы жили одной строкой `contacts.work_hours`
 * («Пн–Вс, 9:00–21:00»). Выбрать в форме рабочие дни было нечем, а
 * отдать часы в микроразметку по тексту нельзя в принципе. Настройка
 * `contacts.schedule` держит объект: семь дней с временем и признаком
 * выходного плюс свободное примечание.
 *
 * ДВЕ НАСТРОЙКИ ОДНОВРЕМЕННО НЕ ОСТАВЛЯЮТСЯ. Строка, на которую больше
 * никто не смотрит, однажды всплывёт в чужом запросе и собьёт с толку:
 * заполнены обе — какая настоящая, и почему администратор, поправивший
 * одну, не видит изменений. Прецедент — веха 4.13, удалившая
 * `services_page.notes` в той же миграции, что завела замену.
 *
 * КЛАССЫ ПРИЛОЖЕНИЯ ЗДЕСЬ НЕ УПОМИНАЮТСЯ — ни `App\Support\WorkSchedule`,
 * ни `App\Enums\Weekday`, ни `App\Models\Setting`. Правило то же, по
 * которому написана миграция вехи 4.13: миграция обязана исполняться
 * на схеме своего дня и пережить переименование любого класса. Поэтому
 * ключи дней, ключ кеша и правило сборки строки для `down()` записаны
 * здесь буквально. Дублирование сознательное — цена за то, что миграция
 * не сломается задним числом.
 */
return new class extends Migration
{
    /**
     * Дни недели: ключ значения настройки => короткая подпись.
     *
     * Порядок — с понедельника, и он же определение слова «подряд»
     * при сборке диапазонов в `down()`.
     *
     * @var array<string, string>
     */
    private const array WEEKDAYS = [
        'mon' => 'Пн',
        'tue' => 'Вт',
        'wed' => 'Ср',
        'thu' => 'Чт',
        'fri' => 'Пт',
        'sat' => 'Сб',
        'sun' => 'Вс',
    ];

    private const string SCHEDULE_KEY = 'contacts.schedule';

    private const string WORK_HOURS_KEY = 'contacts.work_hours';

    /**
     * Ключ кеша настроек — литералом, а не `Setting::CACHE_KEY`.
     *
     * Миграция правит `site_settings` запросом, минуя события модели,
     * то есть кеш сам не сбросится. Без явного сброса задеплоенный прод
     * продолжит отдавать старое значение из Redis, и «настройка не
     * применилась» будут искать в форме.
     */
    private const string SETTINGS_CACHE_KEY = 'site_settings';

    /**
     * Часы компании на момент вехи: то же, что стояло строкой.
     */
    private const string DEFAULT_OPEN = '09:00';

    private const string DEFAULT_CLOSE = '21:00';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Миграция ПЕРЕНОСИТ данные, а не заводит их. На пустой базе (CI,
        // `migrate:fresh`) переносить нечего, и умолчание туда класть
        // не её дело: значения по умолчанию принадлежат
        // `SiteSettingSeeder`, и вторая рука, пишущая в `site_settings`,
        // сделала бы «настройки после migrate:fresh» зависящими от того,
        // какая из них отработала последней.
        if (! DB::table('site_settings')->where('key', self::WORK_HOURS_KEY)->exists()) {
            Log::info('[Веха 4.14] переносить нечего: настройки часов работы в базе нет');

            return;
        }

        $now = now();

        // Значение по умолчанию, а не разбор старой строки: свободный текст
        // («Пн–Вс, 9:00–21:00», «круглосуточно», «звоните») структурой
        // не является, и попытка его распарсить дала бы расписание,
        // похожее на правду ровно настолько, чтобы никто не проверил.
        // Умолчание совпадает с тем, чем компания работает сегодня,
        // а расхождение администратор увидит в форме, где дни видны глазом.
        DB::table('site_settings')->updateOrInsert(
            ['key' => self::SCHEDULE_KEY],
            [
                'value' => json_encode($this->defaultSchedule(), JSON_UNESCAPED_UNICODE),
                'group' => 'contacts',
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $legacy = DB::table('site_settings')->where('key', self::WORK_HOURS_KEY)->value('value');

        DB::table('site_settings')->where('key', self::WORK_HOURS_KEY)->delete();

        Cache::forget(self::SETTINGS_CACHE_KEY);

        // Миграция данных без следа в логе неотличима от миграции,
        // которая ничего не нашла. Старое значение пишется в лог целиком:
        // оно единственное свидетельство того, что стояло у компании
        // до переноса, и восстановить его иначе будет неоткуда.
        Log::info('[Веха 4.14] часы работы заменены структурным расписанием', [
            'added' => self::SCHEDULE_KEY,
            'removed' => self::WORK_HOURS_KEY,
            'previous_value' => is_string($legacy) ? json_decode($legacy, true) : $legacy,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Симметрично `up()`: нет расписания — нечего сворачивать обратно,
        // и заводить строку часов на базе, где настроек нет вовсе, откат
        // не должен.
        if (! DB::table('site_settings')->where('key', self::SCHEDULE_KEY)->exists()) {
            Log::info('[Веха 4.14] сворачивать нечего: настройки расписания в базе нет');

            return;
        }

        $schedule = $this->currentSchedule();

        $now = now();

        DB::table('site_settings')->updateOrInsert(
            ['key' => self::WORK_HOURS_KEY],
            [
                'value' => json_encode($this->legacyLabel($schedule), JSON_UNESCAPED_UNICODE),
                'group' => 'contacts',
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        DB::table('site_settings')->where('key', self::SCHEDULE_KEY)->delete();

        Cache::forget(self::SETTINGS_CACHE_KEY);

        Log::info('[Веха 4.14] расписание свёрнуто обратно в строку часов работы', [
            'added' => self::WORK_HOURS_KEY,
            'removed' => self::SCHEDULE_KEY,
        ]);
    }

    /**
     * Значение настройки по умолчанию: семь рабочих дней 09:00–21:00.
     *
     * @return array{days: array<string, array{closed: bool, open: string, close: string}>, note: null}
     */
    private function defaultSchedule(): array
    {
        $days = [];

        foreach (array_keys(self::WEEKDAYS) as $key) {
            $days[$key] = [
                'closed' => false,
                'open' => self::DEFAULT_OPEN,
                'close' => self::DEFAULT_CLOSE,
            ];
        }

        return ['days' => $days, 'note' => null];
    }

    /**
     * Нормализованное расписание из базы: «ключ дня => [open, close]» или `null`.
     *
     * Драйвер отдаёт jsonb строкой, поэтому распаковка здесь, а не кастом
     * модели, которой у миграции нет.
     *
     * @return array<string, array{open: string, close: string}|null>
     */
    private function currentSchedule(): array
    {
        $raw = DB::table('site_settings')->where('key', self::SCHEDULE_KEY)->value('value');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $rawDays = is_array($decoded) && is_array($decoded['days'] ?? null) ? $decoded['days'] : [];

        $days = [];

        foreach (array_keys(self::WEEKDAYS) as $key) {
            $days[$key] = $this->normalizeDay($rawDays[$key] ?? null);
        }

        return $days;
    }

    /**
     * @return array{open: string, close: string}|null
     */
    private function normalizeDay(mixed $day): ?array
    {
        if (! is_array($day) || filter_var($day['closed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $open = $this->normalizeTime($day['open'] ?? null);
        $close = $this->normalizeTime($day['close'] ?? null);

        if ($open === null || $close === null || $close <= $open) {
            return null;
        }

        return ['open' => $open, 'close' => $close];
    }

    private function normalizeTime(mixed $time): ?string
    {
        if (! is_string($time) && ! is_int($time)) {
            return null;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim((string) $time), $m) !== 1) {
            return null;
        }

        $hours = (int) $m[1];
        $minutes = (int) $m[2];

        if ($hours === 24 && $minutes === 0) {
            return '23:59';
        }

        return $hours > 23 || $minutes > 59 ? null : sprintf('%02d:%02d', $hours, $minutes);
    }

    /**
     * Строка старой настройки, собранная по тому же правилу, что
     * `App\Support\WorkSchedule::label()`.
     *
     * Повторена здесь, а не вызвана из класса, по причине из шапки файла.
     * Расхождение форматировки при откате безобидно — значение уходит
     * в свободный текст, который администратор всё равно правит руками;
     * а вот миграция, падающая из-за удалённого класса, безобидной
     * не бывает.
     *
     * @param  array<string, array{open: string, close: string}|null>  $days
     */
    private function legacyLabel(array $days): string
    {
        $working = array_filter($days, static fn (?array $hours): bool => $hours !== null);

        if ($working === []) {
            return 'По договорённости';
        }

        $groups = [];

        foreach ($days as $key => $hours) {
            $last = $groups === [] ? null : array_key_last($groups);

            if ($last !== null && $groups[$last]['hours'] === $hours) {
                $groups[$last]['days'][] = $key;

                continue;
            }

            $groups[] = ['days' => [$key], 'hours' => $hours];
        }

        if (count($groups) === 1) {
            return 'Пн–Вс, '.$this->timeRange($groups[0]['hours']);
        }

        $parts = [];

        foreach ($groups as $group) {
            $names = self::WEEKDAYS[$group['days'][0]];

            if (count($group['days']) > 1) {
                $names .= (count($group['days']) === 2 ? ', ' : '–')
                    .self::WEEKDAYS[$group['days'][count($group['days']) - 1]];
            }

            $parts[] = $group['hours'] === null
                ? $names.(count($group['days']) === 1 ? ' выходной' : ' выходные')
                : $names.' '.$this->timeRange($group['hours']);
        }

        return implode(', ', $parts);
    }

    /**
     * @param  array{open: string, close: string}  $hours
     */
    private function timeRange(array $hours): string
    {
        return preg_replace('/^0(\d:)/', '$1', $hours['open'])
            .'–'
            .preg_replace('/^0(\d:)/', '$1', $hours['close']);
    }
};
