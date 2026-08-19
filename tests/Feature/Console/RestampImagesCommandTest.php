<?php

use App\Models\Car;
use App\Models\CarPhoto;
use App\Models\Media;
use App\Models\Service;
use Illuminate\Support\Facades\Storage;

/*
 * Перепроход по уже загруженным фотографиям (веха 4.14).
 *
 * Команда ПЕРЕЗАПИСЫВАЕТ ФАЙЛЫ БЕЗ РЕЗЕРВНОЙ КОПИИ, поэтому здесь
 * проверяются в первую очередь тормоза: пробный прогон не трогает диск,
 * повторный не ставит второй штамп, битая строка не роняет проход,
 * а служебные файлы библиотеки не попадают под раздачу вовсе.
 *
 * `--no-interaction` в живых прогонах обязателен: подтверждение перед
 * стартом иначе ждёт ответа, которого в тесте некому дать. Само
 * подтверждение — интерактивный тормоз и проверяется руками.
 */

beforeEach(function (): void {
    Storage::fake('public');
    config()->set('images.max_width', 400);
    config()->set('images.thumb_width', 150);
    config()->set('images.watermark.min_width', 50);
});

/**
 * Положить на фейковый диск настоящий webp без штампа.
 *
 * Файл именно настоящий, а не `UploadedFile::fake()`: команда читает его
 * с диска и обязана декодировать — фейк с подставным содержимым проверял
 * бы обработку ошибок, а не обработку изображений.
 */
function plainPhoto(string $path): void
{
    $gd = imagecreatetruecolor(600, 400);
    imagefill($gd, 0, 0, imagecolorallocate($gd, 200, 200, 200));
    ob_start();
    imagewebp($gd);
    Storage::disk('public')->put($path, (string) ob_get_clean());
}

it('touches nothing at all in a dry run', function () {
    plainPhoto('cars/a.webp');
    $photo = CarPhoto::factory()->for(Car::factory())->create([
        'disk' => 'public', 'path' => 'cars/a.webp', 'watermarked_at' => null,
    ]);

    $before = Storage::disk('public')->get('cars/a.webp');

    $this->artisan('images:restamp', ['--dry-run' => true])
        // Сколько тронет — команда сообщает всегда, а не только живьём:
        // число, увиденное до старта, — единственный момент, когда «упс,
        // не та база» ещё поправимо.
        ->expectsOutputToContain('К обработке: 1')
        ->assertSuccessful();

    expect($photo->refresh()->watermarked_at)->toBeNull()
        ->and(Storage::disk('public')->get('cars/a.webp'))->toBe($before);
});

it('stamps the file and records its dimensions on a live pass', function () {
    plainPhoto('cars/a.webp');
    $photo = CarPhoto::factory()->for(Car::factory())->create([
        'disk' => 'public', 'path' => 'cars/a.webp', 'watermarked_at' => null, 'width' => null,
    ]);

    $this->artisan('images:restamp', ['--no-interaction' => true])->assertSuccessful();

    $photo->refresh();

    expect($photo->watermarked_at)->not->toBeNull()
        ->and($photo->width)->toBe(400)
        ->and($photo->height)->toBe(267)
        // Превью пересобирается тем же правилом: старое осталось бы
        // без логотипа, и карточка каталога показывала бы чистый кадр
        // при заштампованном оригинале.
        ->and(Storage::disk('public')->exists($photo->thumb_path))->toBeTrue();
});

it('skips what is already stamped on a second pass', function () {
    // Без отметки второй запуск поставит второй штамп поверх первого,
    // и вернуть файл будет неоткуда — резервной копии команда не делает.
    plainPhoto('cars/a.webp');
    CarPhoto::factory()->for(Car::factory())->create([
        'disk' => 'public', 'path' => 'cars/a.webp', 'watermarked_at' => null,
    ]);

    $this->artisan('images:restamp', ['--no-interaction' => true])->assertSuccessful();
    $afterFirst = Storage::disk('public')->get('cars/a.webp');

    $this->artisan('images:restamp', ['--no-interaction' => true])
        ->expectsOutputToContain('Нечего обрабатывать')
        ->assertSuccessful();

    expect(Storage::disk('public')->get('cars/a.webp'))->toBe($afterFirst);
});

it('counts an unreadable file as a skip instead of stopping', function () {
    // Одна битая строка не должна отменять проход по тысяче целых.
    CarPhoto::factory()->for(Car::factory())->create([
        'disk' => 'public', 'path' => 'cars/missing.webp', 'watermarked_at' => null,
    ]);

    plainPhoto('cars/ok.webp');
    $ok = CarPhoto::factory()->for(Car::factory())->create([
        'disk' => 'public', 'path' => 'cars/ok.webp', 'watermarked_at' => null,
    ]);

    $this->artisan('images:restamp', ['--no-interaction' => true])
        ->expectsOutputToContain('Пропущено')
        ->assertSuccessful();

    expect($ok->refresh()->watermarked_at)->not->toBeNull();
});

it('leaves library files that no service uses alone', function () {
    // Решение 9: всё, что в библиотеке не является фото услуги или
    // запчасти, — служебное. Портрет сотрудника, аватар автора отзыва,
    // фон блока: логотип компании на них был бы не исправлением, а порчей.
    plainPhoto('media/loose.webp');
    plainPhoto('media/used.webp');

    $loose = Media::factory()->create(['disk' => 'public', 'path' => 'media/loose.webp', 'watermarked_at' => null]);
    $used = Media::factory()->create(['disk' => 'public', 'path' => 'media/used.webp', 'watermarked_at' => null]);

    Service::factory()->create(['media_id' => $used->id]);

    $this->artisan('images:restamp', ['--only' => 'media', '--no-interaction' => true])->assertSuccessful();

    expect($used->refresh()->watermarked_at)->not->toBeNull()
        ->and($loose->refresh()->watermarked_at)->toBeNull();
});

it('restamps an already stamped file when forced', function () {
    plainPhoto('cars/a.webp');
    $photo = CarPhoto::factory()->for(Car::factory())->create([
        'disk' => 'public', 'path' => 'cars/a.webp', 'watermarked_at' => now()->subDay(),
    ]);

    // Нужно, когда сменили сам логотип: отметка стоит, а штамп на файле
    // старый, и обычный проход такую строку не увидит.
    $this->artisan('images:restamp', ['--force' => true, '--no-interaction' => true])
        ->expectsOutputToContain('К обработке: 1')
        ->assertSuccessful();

    expect($photo->refresh()->watermarked_at->isToday())->toBeTrue();
});

it('spends the limit across both kinds, not per kind', function () {
    // `--limit=1` означает одну запись всего. Лимит, отсчитываемый заново
    // на каждый вид, обещал бы одну, а тронул две — и на проде это две
    // тысячи вместо тысячи.
    plainPhoto('cars/a.webp');
    plainPhoto('media/used.webp');

    CarPhoto::factory()->for(Car::factory())->create([
        'disk' => 'public', 'path' => 'cars/a.webp', 'watermarked_at' => null,
    ]);

    $used = Media::factory()->create(['disk' => 'public', 'path' => 'media/used.webp', 'watermarked_at' => null]);
    Service::factory()->create(['media_id' => $used->id]);

    $this->artisan('images:restamp', ['--limit' => 1, '--dry-run' => true])
        ->expectsOutputToContain('К обработке: 1')
        ->assertSuccessful();
});

it('refuses an unknown --only value instead of silently doing everything', function () {
    $this->artisan('images:restamp', ['--only' => 'photos'])->assertFailed();
});
