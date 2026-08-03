<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Employee;
use Illuminate\Database\Seeder;

/**
 * Команда для блока «О компании». Имена и должности — из макета.
 */
class EmployeeSeeder extends Seeder
{
    /**
     * @var list<array{name: string, position: string, bio: string}>
     */
    private const EMPLOYEES = [
        [
            'name' => 'Андрей Волков',
            'position' => 'Руководитель отдела импорта',
            'bio' => 'Ведёт сделки по прямым контрактам с китайскими и европейскими поставщиками.',
        ],
        [
            'name' => 'Мария Соколова',
            'position' => 'Менеджер по подбору автомобилей',
            'bio' => 'Подбирает автомобиль под бюджет и задачи клиента, сопровождает сделку до выдачи.',
        ],
        [
            'name' => 'Игорь Ким',
            'position' => 'Старший мастер автосервиса',
            'bio' => 'Отвечает за ТО и ремонт, включая гибридные силовые установки.',
        ],
        [
            'name' => 'Елена Панова',
            'position' => 'Специалист по детейлингу',
            'bio' => 'Полировка, защитные покрытия и химчистка салона.',
        ],
    ];

    public function run(): void
    {
        $created = 0;
        $updated = 0;

        foreach (self::EMPLOYEES as $index => $employee) {
            $record = Employee::updateOrCreate(
                ['name' => $employee['name']],
                [
                    'position' => $employee['position'],
                    'bio' => $employee['bio'],
                    'is_published' => true,
                    'sort_order' => $index,
                ],
            );

            $record->wasRecentlyCreated ? $created++ : $updated++;
        }

        $this->command?->info("[EmployeeSeeder] сотрудников создано: {$created}, обновлено: {$updated}");
    }
}
