<?php

declare(strict_types=1);

namespace App\Filament\Resources\Reviews\Tables;

use App\Filament\Resources\Reviews\Actions\PublishReviewAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ReviewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('author_name')
                    ->label('Автор')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('author_context')
                    ->label('Контекст')
                    ->placeholder('—'),

                TextColumn::make('rating')
                    ->label('Оценка')
                    ->formatStateUsing(fn (int $state): string => str_repeat('★', $state))
                    ->placeholder('—'),

                TextColumn::make('body')
                    ->label('Текст')
                    ->limit(60)
                    ->wrap(),

                // Намеренно IconColumn, а не ToggleColumn: инлайн-колонки
                // обходят политики, а публикация — то, что видит весь
                // интернет. Менять состояние можно только действием
                // с подтверждением и записью в лог.
                IconColumn::make('is_published')
                    ->label('На сайте')
                    ->boolean(),

                TextColumn::make('published_at')
                    ->label('Опубликован')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                PublishReviewAction::publish(),
                PublishReviewAction::unpublish(),
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation(),
            ]);
    }
}
