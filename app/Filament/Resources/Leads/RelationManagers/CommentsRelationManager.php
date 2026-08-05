<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\RelationManagers;

use App\Enums\UserRole;
use App\Models\LeadComment;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Лента комментариев менеджера к заявке.
 *
 * Правки чужих комментариев нет намеренно, и удаление ограничено автором
 * и администратором: лента работы с заявкой — это журнал, а не документ.
 * Отредактированный задним числом комментарий обесценивает всю ленту:
 * по ней перестаёт читаться, кто и что на самом деле решил.
 */
final class CommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    protected static ?string $title = 'Комментарии менеджера';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('body')
                ->label('Комментарий')
                ->required()
                ->rows(4)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('body')
            // Автор читается через связь: без предзагрузки лента даёт
            // запрос на каждый комментарий.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('author'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Когда')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('author.name')
                    ->label('Автор'),

                TextColumn::make('body')
                    ->label('Комментарий')
                    ->wrap(),
            ])
            // Хронология, а не свежие сверху: ленту работы с заявкой
            // читают сверху вниз, как переписку.
            ->defaultSort('created_at')
            ->headerActions([
                CreateAction::make()
                    ->label('Добавить комментарий')
                    // Автор проставляется сервером и в форме не
                    // редактируется: поле выбора автора означало бы
                    // возможность подписать чужим именем.
                    ->mutateDataUsing(function (array $data): array {
                        $data['user_id'] = auth()->id();

                        return $data;
                    }),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->visible(fn (LeadComment $record): bool => self::canDeleteComment($record)),
            ]);
    }

    /**
     * Удалить комментарий может его автор или администратор.
     *
     * Проверка живёт здесь, а не в `LeadCommentPolicy`, потому что
     * политика отвечает на вопрос «есть ли у роли доступ к разделу»
     * (обе роли — да), а это правило про конкретную запись и её автора.
     *
     * Имя не `canDelete()`: у `RelationManager` уже есть нестатический
     * метод с таким именем, и статический одноимённый роняет загрузку
     * класса целиком — вместе со всей панелью.
     */
    private static function canDeleteComment(LeadComment $record): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return $record->user_id === $user->getKey() || $user->role === UserRole::Admin;
    }
}
