<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Media;
use App\Models\Setting;
use App\Services\ImageProcessor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Фотография шоу-рума: `assets/showroom.png` → медиабиблиотека → `home.trust`.
 *
 * Заказчик просил, чтобы посетитель полосы доверия «открыл и понял: они
 * в реале есть, можно прийти посмотреть», — то есть блок обязан показывать
 * снимок, а не обещать салон текстом.
 *
 * Идёт ПОСЛЕ `SiteSettingSeeder` и не может идти раньше: тот пишет
 * `home.trust` целиком и затёр бы проставленный здесь `image_id`.
 *
 * Устройство и оба правила — те же, что у `StepPhotoSeeder`, и по тем же
 * причинам:
 *
 * 1. В тестах не выполняется. Исходник весит около 7 МБ, и прогон
 *    `ImageProcessor` на каждом `RefreshDatabase` превратил бы набор
 *    в наказание. Тесты задают `home.trust` явно.
 * 2. Копирование идемпотентно: уже лежащий на диске `.webp` пропускается,
 *    повторный `db:seed` не переобрабатывает файл заново.
 *
 * **Снимок временный и подлежит замене заказчиком.** На кадре — чужой
 * дилерский центр, а не салон на Осенней, 17: настоящей фотографии
 * на момент вехи 4.5 не было. Строка про это стоит во «внешних
 * зависимостях» роадмапа рядом с `hero-*.webp` и `service-panel.webp`,
 * и она там не для порядка — снаружи блок выглядит готовым, и заметить
 * чужое здание больше некому. Замена делается через панель, без
 * разработчика и без этого сида: ключ настройки для того и заведён.
 */
class ShowroomPhotoSeeder extends Seeder
{
    private const SOURCE = 'assets/showroom.png';

    private const TARGET_DIR = 'media';

    private const SETTING_KEY = 'home.trust';

    public function run(): void
    {
        if (app()->runningUnitTests()) {
            $this->command?->info('[ShowroomPhotoSeeder] тестовое окружение — фотография шоу-рума пропущена');

            return;
        }

        $source = base_path(self::SOURCE);

        if (! is_file($source)) {
            $this->command?->warn("[ShowroomPhotoSeeder] файл {$source} не найден — полоса доверия осталась текстовой");

            return;
        }

        $trust = Setting::get(self::SETTING_KEY);

        if (! is_array($trust)) {
            $this->command?->warn('[ShowroomPhotoSeeder] ключ home.trust не засеян — сначала SiteSettingSeeder');

            return;
        }

        // Уже выбранную в панели фотографию не трогаем: сид расставляет
        // умолчания, а не отменяет решения администратора. Проверка строгая
        // — правило `RULES.md` про `empty()`.
        if (($trust['image_id'] ?? null) !== null && $trust['image_id'] !== '') {
            $this->command?->info('[ShowroomPhotoSeeder] фотография шоу-рума уже выбрана — пропуск');

            return;
        }

        $trust['image_id'] = $this->storeSource($source);

        Setting::set(self::SETTING_KEY, $trust);

        $this->command?->info('[ShowroomPhotoSeeder] фотография шоу-рума привязана к полосе доверия');
    }

    /**
     * Обработать исходник и завести запись библиотеки.
     */
    private function storeSource(string $source): int
    {
        $disk = Storage::disk('public');
        $processor = app(ImageProcessor::class);

        // Имя предсказуемо — на нём и держится идемпотентность,
        // как в `StepPhotoSeeder` и `CarPhotoSeeder`.
        $stem = 'showroom';
        $path = self::TARGET_DIR.'/'.$stem.'.webp';

        if ($disk->exists($path)) {
            $thumbPath = $processor->thumbPathFor($path);
            $thumbPath = $disk->exists($thumbPath) ? $thumbPath : null;
            $mime = 'image/webp';
            $size = (int) $disk->size($path);
        } else {
            $stored = $processor->storeFile(
                sourcePath: $source,
                disk: 'public',
                directory: self::TARGET_DIR,
                originalName: basename($source),
                basename: $stem,
                // Фон полосы доверия — служебное изображение, а не витрина
                // товара: логотип компании поверх собственного шоу-рума
                // на собственном сайте (веха 4.14, решение 9).
                watermark: false,
            );

            $path = $stored->path;
            $thumbPath = $stored->thumbPath;
            $mime = $stored->mime;
            $size = $stored->size;
        }

        // По пути, а не по имени: имя администратор правит на форме
        // редактирования, и второй прогон завёл бы дубль записи
        // на тот же файл.
        $record = Media::firstOrCreate(
            ['path' => $path],
            [
                'disk' => 'public',
                'thumb_path' => $thumbPath,
                'name' => 'Шоу-рум в Москве',
                // `alt` осмысленный, а не пустой: кадр в полосе доверия
                // несёт смысл сам, а не подпирает текст поверх себя,
                // — разбор в шаблоне главной.
                'alt' => 'Шоу-рум ЛАО КАРС в Москве',
                'mime' => $mime,
                'size' => $size,
            ],
        );

        return (int) $record->getKey();
    }
}
