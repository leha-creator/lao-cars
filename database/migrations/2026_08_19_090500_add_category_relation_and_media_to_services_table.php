<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Позиция прайса переезжает на справочник категорий и получает
 * фотографию, подробное описание и флаг акцента — веха 4.13.
 *
 * ОДНА МИГРАЦИЯ НА ВСЕ ИЗМЕНЕНИЯ ТАБЛИЦЫ. Разнести их по трём значит
 * получить три состояния схемы, в двух из которых приложение не работает,
 * — а откат на проде (`deploy.sh`) идёт по одной миграции за раз.
 *
 * ПЕРЕНОС ДАННЫХ ИДЁТ ЧЕРЕЗ `DB`, А НЕ ЧЕРЕЗ МОДЕЛИ. Модель `Service`
 * к моменту следующей правки будет знать только про новую схему,
 * и миграция, написанная через неё, сломается задним числом — на свежей
 * базе, где её никто уже не проверяет. По той же причине здесь нет ни
 * `App\Enums\ServicePage`, ни `App\Models\Setting`: значения страниц
 * и ключ кеша настроек записаны строками, чтобы миграция пережила
 * переименование любого из этих классов.
 *
 * `down()` ВОССТАНАВЛИВАЕТ И КОЛОНКУ, И НАСТРОЙКУ. Это не формальность:
 * откат релиза на проде предусмотрен `deploy.sh`, и миграция без
 * рабочего отката превращает его в ручную работу с psql ночью.
 */
return new class extends Migration
{
    /**
     * Категории старого енама `App\Enums\ServiceCategory`.
     *
     * «значение кейса» => [имя, слаг, страница].
     *
     * Слаг — старое значение енама с подчёркиванием, заменённым на дефис
     * (`tire_service` → `tire-service`): ровно то, что делал метод
     * `ServiceCategory::anchor()`. Якоря вида `/services#tire-service`
     * уже разошлись по документации, планам и прототипу, и менять их
     * молча нельзя.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const array LEGACY_CATEGORIES = [
        'maintenance' => ['ТО и ремонт', 'maintenance', 'services'],
        'tire_service' => ['Шиномонтаж', 'tire-service', 'services'],
        'detailing' => ['Детейлинг', 'detailing', 'services'],
        'extra' => ['Дополнительные сервисы', 'extra', 'services'],
        'parts' => ['Запчасти', 'parts', 'parts'],
    ];

    /**
     * Ключ настройки, в которой до вехи 4.13 жили описания категорий.
     */
    private const string NOTES_KEY = 'services_page.notes';

    /**
     * Ключ кеша настроек — литералом, а не `Setting::CACHE_KEY`.
     *
     * Миграция удаляет строку `site_settings` запросом, минуя события
     * модели, то есть кеш сам не сбросится. Ссылаться ради этого на класс,
     * который миграция пережить обязана, — обмен одной поломки на другую.
     */
    private const string SETTINGS_CACHE_KEY = 'site_settings';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            // Nullable НА ВРЕМЯ ПЕРЕНОСА: строки уже существуют, и колонка
            // с `NOT NULL` без умолчания их не примет. Обязательной она
            // становится ниже, после того как все позиции привязаны.
            $table->foreignId('service_category_id')
                ->nullable()
                ->constrained(table: 'service_categories')
                // Категорию с позициями удалять нельзя. Запрет продублирован
                // в `DeleteServiceCategoryAction` объяснением для человека:
                // внешний ключ отдаёт администратору ошибку драйвера.
                ->restrictOnDelete();

            // Фотография услуги из общей медиабиблиотеки. `nullOnDelete()`,
            // а не каскад: удалённый файл оставляет карточку без кадра,
            // а не уносит позицию из прайса.
            $table->foreignId('media_id')
                ->nullable()
                ->constrained(table: 'media')
                ->nullOnDelete();

            // Подробное описание, раскрываемое по кнопке. Отдельная колонка,
            // а не удлинённое `description`: короткое описание — одна-две
            // строки под названием, и в карточке они выводятся порознь.
            $table->text('details')->nullable();

            // Широкая карточка во всю ширину контента с фотографией на фоне.
            $table->boolean('is_featured')->default(false);
        });

        $this->migrateData();

        // Позиция без категории с этого момента невозможна: страница
        // собирается блоками категорий, и позиция вне блока не выводится
        // нигде — то есть существует, но невидима.
        DB::statement('ALTER TABLE services ALTER COLUMN service_category_id SET NOT NULL');

        Schema::table('services', function (Blueprint $table): void {
            $table->dropIndex(['category', 'sort_order']);
            $table->dropColumn('category');

            // Блок категории на странице: выборка «категория + порядок».
            $table->index(['service_category_id', 'sort_order']);

            // Порядок выдачи вехи 4.13: акцентные, затем позиции
            // с фотографией, затем остальные (`Service::ordered()`).
            $table->index(['service_category_id', 'is_featured', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->string('category')->nullable();
        });

        // Значение старой колонки восстанавливается из слага категории тем
        // же преобразованием, каким слаг из него получился, — в обратную
        // сторону. Для пяти исходных категорий это точное восстановление;
        // позиции категорий, заведённых после вехи 4.13, получат значение,
        // которого старый енам не знает, и код до вехи на них упадёт.
        // Так и должно быть: откат к схеме с пятью категориями на базе
        // с шестью — потеря данных, и молчать о ней хуже, чем упасть.
        DB::statement(<<<'SQL'
            UPDATE services
               SET category = replace(service_categories.slug, '-', '_')
              FROM service_categories
             WHERE service_categories.id = services.service_category_id
        SQL);

        $this->restoreNotesSetting();

        DB::statement('ALTER TABLE services ALTER COLUMN category SET NOT NULL');

        Schema::table('services', function (Blueprint $table): void {
            $table->dropIndex(['service_category_id', 'is_featured', 'sort_order']);
            $table->dropIndex(['service_category_id', 'sort_order']);

            // Снимает внешний ключ вместе с колонкой; голый `dropColumn()`
            // при живом ключе — не тот идиом.
            $table->dropConstrainedForeignId('service_category_id');
            $table->dropConstrainedForeignId('media_id');

            $table->dropColumn(['details', 'is_featured']);

            $table->index(['category', 'sort_order']);
        });
    }

    /**
     * Наполнить справочник и привязать к нему позиции.
     *
     * На пустой базе (CI, свежая машина) читать нечего и обновлять нечего,
     * но пять категорий заводятся всё равно: без них свежая установка
     * получает прайс, который некуда положить. Ветвления «если прод»
     * здесь нет намеренно — оно означало бы, что миграция проверяется
     * в одном окружении, а работает в другом.
     */
    private function migrateData(): void
    {
        $notes = $this->legacyNotes();

        $now = now();
        $sortOrder = 0;
        $linked = 0;

        foreach (self::LEGACY_CATEGORIES as $value => [$name, $slug, $page]) {
            $id = DB::table('service_categories')->insertGetId([
                'name' => $name,
                'slug' => $slug,
                'page' => $page,
                // У запчастей описания не было вовсе — настройка держала
                // поле на каждую категорию СТРАНИЦЫ УСЛУГ, четыре штуки.
                'description' => $notes[$value] ?? null,
                'sort_order' => $sortOrder++,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $linked += DB::table('services')
                ->where('category', $value)
                ->update(['service_category_id' => $id]);
        }

        DB::table('site_settings')->where('key', self::NOTES_KEY)->delete();
        Cache::forget(self::SETTINGS_CACHE_KEY);

        // Миграция данных без следа в логе не отличается от миграции,
        // которая ничего не нашла.
        Log::info('[Веха 4.13] справочник категорий услуг заполнен', [
            'categories' => count(self::LEGACY_CATEGORIES),
            'descriptions' => count($notes),
            'services_linked' => $linked,
        ]);
    }

    /**
     * Описания категорий из настройки `services_page.notes`.
     *
     * Значение — jsonb вида «значение кейса енама => абзац». Драйвер
     * отдаёт его строкой, поэтому распаковывается здесь, а не кастом
     * модели, которой у миграции нет.
     *
     * @return array<string, string>
     */
    private function legacyNotes(): array
    {
        $raw = DB::table('site_settings')->where('key', self::NOTES_KEY)->value('value');

        if (! is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        $notes = [];

        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_string($value) && trim($value) !== '') {
                $notes[$key] = $value;
            }
        }

        return $notes;
    }

    /**
     * Вернуть настройку `services_page.notes` из описаний категорий.
     *
     * Ключи собираются обратным преобразованием слага — тем же, каким
     * восстанавливается колонка `category`. Категории страницы запчастей
     * в настройку не попадали никогда и не попадают при откате.
     */
    private function restoreNotesSetting(): void
    {
        $notes = [];

        $categories = DB::table('service_categories')
            ->where('page', 'services')
            ->orderBy('sort_order')
            ->get(['slug', 'description']);

        foreach ($categories as $category) {
            if (is_string($category->description) && trim($category->description) !== '') {
                $notes[str_replace('-', '_', $category->slug)] = $category->description;
            }
        }

        $now = now();

        DB::table('site_settings')->updateOrInsert(
            ['key' => self::NOTES_KEY],
            [
                'value' => json_encode($notes, JSON_UNESCAPED_UNICODE),
                'group' => 'services_page',
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        Cache::forget(self::SETTINGS_CACHE_KEY);
    }
};
