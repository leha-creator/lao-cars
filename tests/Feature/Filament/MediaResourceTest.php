<?php

/*
 * Медиабиблиотека в админке (веха 3.4).
 *
 * Потребителей у библиотеки до вехи 3.5 нет, поэтому проверяется само
 * хранилище: список, загрузка через ImageProcessor, правка подписей
 * и удаление вместе с файлами на диске.
 */

use App\Filament\Resources\Media\MediaResource;
use App\Filament\Resources\Media\Pages\EditMedia;
use App\Filament\Resources\Media\Pages\ListMedia;
use App\Models\Media;
use App\Models\User;
use App\Services\ImageProcessor;
use Filament\Actions\DeleteAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('shows media in the list', function () {
    $media = Media::factory()->count(3)->create();

    livewire(ListMedia::class)
        ->assertOk()
        ->assertCanSeeTableRecords($media);
});

it('has no create page: a library record without a file is meaningless', function () {
    expect(MediaResource::getPages())
        ->toHaveKeys(['index', 'edit'])
        ->not->toHaveKey('create');
});

it('creates records from an upload, converting files to webp with a thumbnail', function () {
    Storage::fake('public');

    // По имени, а не по классу: UploadMediaAction — фабрика, а не
    // наследник Action, и в таблице действие зарегистрировано как `upload`.
    livewire(ListMedia::class)
        ->callAction('upload', [
            'files' => [
                UploadedFile::fake()->image('Промо баннер.png', 1200, 800),
            ],
        ]);

    $media = Media::query()->sole();

    // Имя — исходное без расширения: по нему библиотека и ищется,
    // а путь состоит из случайного идентификатора.
    expect($media->name)->toBe('Промо баннер')
        ->and($media->mime)->toBe('image/webp')
        ->and($media->path)->toEndWith('.webp')
        ->and($media->thumb_path)->toBe(app(ImageProcessor::class)->thumbPathFor($media->path));

    Storage::disk('public')->assertExists($media->path);
    Storage::disk('public')->assertExists($media->thumb_path);
});

it('edits the name and alt text', function () {
    $media = Media::factory()->create(['name' => 'старое', 'alt' => null]);

    livewire(EditMedia::class, ['record' => $media->getRouteKey()])
        ->fillForm(['name' => 'новое', 'alt' => 'Автомобиль на фоне салона'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($media->refresh())
        ->name->toBe('новое')
        ->alt->toBe('Автомобиль на фоне салона');
});

it('deletes the record together with its files on disk', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/x.webp', 'original');
    Storage::disk('public')->put('media/thumbs/x.webp', 'thumb');

    $media = Media::factory()->create([
        'path' => 'media/x.webp',
        'thumb_path' => 'media/thumbs/x.webp',
    ]);

    livewire(EditMedia::class, ['record' => $media->getRouteKey()])
        ->callAction(DeleteAction::class);

    expect(Media::query()->whereKey($media->id)->exists())->toBeFalse();

    Storage::disk('public')->assertMissing('media/x.webp');
    Storage::disk('public')->assertMissing('media/thumbs/x.webp');
});
