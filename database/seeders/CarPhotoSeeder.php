<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Car;
use App\Models\CarPhoto;
use App\Services\ImageProcessor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\Finder;

/**
 * Раскладывает по карточкам реальные фотографии из `assets/cars/`.
 *
 * Сид намеренно ограничен двумя правилами:
 *
 * 1. В тестах не выполняется. В `assets/cars/` 46 файлов общим весом
 *    около 128 МБ; копирование на каждом прогоне `RefreshDatabase`
 *    превратило бы тесты в наказание.
 * 2. Копирование идемпотентно — уже лежащий на диске файл
 *    пропускается, повторный `db:seed` не переливает 128 МБ заново.
 *    Проверка идёт по итоговому `.webp`-имени, а не по исходному:
 *    иначе каждый прогон переобрабатывал бы все 46 файлов заново.
 *
 * Исходники — PNG примерно по 3 МБ. Через `ImageProcessor` (веха 3.4)
 * они превращаются в WebP с превью: без этого демо-данные оставались бы
 * тяжелее того, что заливает через админку администратор, и список
 * из 12 карточек весил бы десятки мегабайт.
 */
class CarPhotoSeeder extends Seeder
{
    private const SOURCE_DIR = 'assets/cars';

    private const TARGET_DIR = 'cars';

    private const PHOTOS_PER_CAR = 4;

    public function run(): void
    {
        if (app()->runningUnitTests()) {
            $this->command?->info('[CarPhotoSeeder] тестовое окружение — копирование фото пропущено');

            return;
        }

        $sourceDir = base_path(self::SOURCE_DIR);

        if (! is_dir($sourceDir)) {
            $this->command?->warn("[CarPhotoSeeder] каталог {$sourceDir} не найден — фото не разложены");

            return;
        }

        $files = $this->sourceFiles($sourceDir);

        if ($files === []) {
            $this->command?->warn("[CarPhotoSeeder] в {$sourceDir} нет изображений");

            return;
        }

        // with('brand'): марка нужна каждой карточке для подписи alt,
        // без предзагрузки цикл даёт запрос на каждый автомобиль.
        $cars = Car::query()->with('brand')->orderBy('sort_order')->orderBy('id')->get();

        if ($cars->isEmpty()) {
            $this->command?->warn('[CarPhotoSeeder] каталог пуст — сначала CarSeeder');

            return;
        }

        $disk = Storage::disk('public');
        $processor = app(ImageProcessor::class);
        $copied = 0;
        $reused = 0;
        $attached = 0;
        $cursor = 0;

        foreach ($cars as $car) {
            for ($i = 0; $i < self::PHOTOS_PER_CAR; $i++) {
                // Фотографий меньше, чем карточек × PHOTOS_PER_CAR:
                // последние карточки получают столько, сколько осталось.
                if (! isset($files[$cursor])) {
                    break 2;
                }

                $source = $files[$cursor++];

                // Имя выводится из исходного и потому предсказуемо:
                // на нём и держится идемпотентность. Случайное имя от
                // ImageProcessor заставляло бы каждый прогон
                // переобрабатывать все 46 файлов заново.
                $stem = mb_strtolower(pathinfo($source, PATHINFO_FILENAME));
                $path = self::TARGET_DIR.'/'.$stem.'.webp';

                $stored = null;

                if ($disk->exists($path)) {
                    $reused++;
                    $thumbPath = $processor->thumbPathFor($path);
                    $thumbPath = $disk->exists($thumbPath) ? $thumbPath : null;
                } else {
                    // Файл, на котором обработка сорвалась, сохраняется
                    // как есть под исходным расширением — проверка выше
                    // его не увидит и следующий прогон попробует снова.
                    // Это и нужно: битый исходник стоит перечитать.
                    $stored = $processor->storeFile(
                        sourcePath: $source,
                        disk: 'public',
                        directory: self::TARGET_DIR,
                        originalName: basename($source),
                        basename: $stem,
                    );

                    $path = $stored->path;
                    $thumbPath = $stored->thumbPath;
                    $copied++;
                }

                $photo = CarPhoto::firstOrCreate(
                    ['car_id' => $car->id, 'path' => $path],
                    [
                        'disk' => 'public',
                        'thumb_path' => $thumbPath,
                        'alt' => "{$car->brand->name} {$car->model}, фото ".($i + 1),
                        'sort_order' => $i,
                        // Размеры и отметка о штампе (веха 4.14). Отметка
                        // обязательна именно здесь: без неё `images:restamp`
                        // посчитает засеянные фотографии необработанными
                        // и поставит второй логотип поверх первого.
                        // При повторном прогоне на готовом файле `$stored`
                        // нет — но нет и записи, которую надо заводить:
                        // `firstOrCreate` вернёт существующую.
                        'width' => $stored?->width,
                        'height' => $stored?->height,
                        'watermarked_at' => $stored?->watermarked ? now() : null,
                    ],
                );

                if ($photo->wasRecentlyCreated) {
                    $attached++;
                }
            }
        }

        $this->command?->info(
            "[CarPhotoSeeder] файлов обработано: {$copied}, уже было на диске: {$reused}, привязано к карточкам: {$attached}"
        );
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(string $dir): array
    {
        $files = [];

        foreach (Finder::create()->files()->in($dir)->name('/\.(png|jpe?g|webp)$/i')->sortByName() as $file) {
            $files[] = $file->getRealPath();
        }

        return $files;
    }
}
