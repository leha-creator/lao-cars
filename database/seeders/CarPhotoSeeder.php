<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Car;
use App\Models\CarPhoto;
use Illuminate\Database\Seeder;
use Illuminate\Http\File;
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
 *
 * Исходники — PNG примерно по 3 МБ, для веба они не подготовлены.
 * Ресайз и оптимизация относятся к вехе 3.4 вместе с медиабиблиотекой.
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

        $cars = Car::query()->orderBy('sort_order')->orderBy('id')->get();

        if ($cars->isEmpty()) {
            $this->command?->warn('[CarPhotoSeeder] каталог пуст — сначала CarSeeder');

            return;
        }

        $disk = Storage::disk('public');
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
                $name = mb_strtolower(basename($source));
                $path = self::TARGET_DIR.'/'.$name;

                if ($disk->exists($path)) {
                    $reused++;
                } else {
                    $disk->putFileAs(self::TARGET_DIR, new File($source), $name);
                    $copied++;
                }

                $photo = CarPhoto::firstOrCreate(
                    ['car_id' => $car->id, 'path' => $path],
                    [
                        'disk' => 'public',
                        'alt' => "{$car->brand->name} {$car->model}, фото ".($i + 1),
                        'sort_order' => $i,
                    ],
                );

                if ($photo->wasRecentlyCreated) {
                    $attached++;
                }
            }
        }

        $this->command?->info(
            "[CarPhotoSeeder] файлов скопировано: {$copied}, уже было на диске: {$reused}, привязано к карточкам: {$attached}"
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
