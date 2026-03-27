<?php

namespace Webteractive\GoogleDriveBackupManager\Filament\Resources\Backups;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use UnitEnum;
use Webteractive\GoogleDriveBackupManager\Models\Backup;

class BackupResource extends Resource
{
    protected static ?string $model = Backup::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?string $navigationLabel = 'Backups';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return config('google-drive-backup-manager.navigation_group', 'System');
    }

    public static function getNavigationSort(): ?int
    {
        return config('google-drive-backup-manager.navigation_sort', 5);
    }

    public static function canAccess(): bool
    {
        $gate = config('google-drive-backup-manager.gate', 'viewBackups');

        if (! Gate::has($gate)) {
            return true;
        }

        return Gate::allows($gate);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_modified', 'desc')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label('File Name')
                    ->searchable(),
                TextColumn::make('formatted_size')
                    ->label('Size'),
                TextColumn::make('date')
                    ->label('Date'),
                TextColumn::make('path')
                    ->label('Path')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function (Backup $record, $livewire): void {
                        $route = config('google-drive-backup-manager.download_route', 'backup.download');

                        $livewire->redirect(
                            route($route, ['path' => encrypt($record->path)]),
                            navigate: false,
                        );
                    }),
                Action::make('delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Backup $record): void {
                        $disk = config('google-drive-backup-manager.disk', 'google');
                        Storage::disk($disk)->delete($record->path);

                        Notification::make()
                            ->success()
                            ->title('Backup deleted')
                            ->send();
                    }),
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageBackups::route('/'),
        ];
    }
}
