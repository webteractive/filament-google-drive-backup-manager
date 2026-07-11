<?php

namespace Webteractive\GoogleDriveBackupManager\Filament\Resources\Backups;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use UnitEnum;
use Webteractive\GoogleDriveBackupManager\Enums\BackupStatus;
use Webteractive\GoogleDriveBackupManager\Jobs\DeleteDriveBackupFile;
use Webteractive\GoogleDriveBackupManager\Models\Backup;
use Webteractive\GoogleDriveBackupManager\Services\GoogleDriveConnection;

class BackupResource extends Resource
{
    protected static ?string $model = Backup::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    public static function getNavigationLabel(): string
    {
        return self::trans('resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return self::trans('resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return self::trans('resource.plural_model_label');
    }

    /**
     * Short helper so call sites don't repeat the full Spatie-style namespace.
     */
    public static function trans(string $key, array $replace = []): string
    {
        return __("google-drive-backup-manager::google-drive-backup-manager.{$key}", $replace);
    }

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

        // Default-deny when the host hasn't registered the gate — backups
        // contain DB dumps and let users reconfigure OAuth, so they must
        // never be exposed by misconfiguration.
        if (! Gate::has($gate)) {
            return false;
        }

        return Gate::allows($gate);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->emptyStateIcon(fn () => self::emptyState()['icon'])
            ->emptyStateHeading(fn () => self::emptyState()['heading'])
            ->emptyStateDescription(fn () => self::emptyState()['description'])
            ->columns([
                TextColumn::make('filename')
                    ->label(self::trans('columns.file'))
                    ->description(fn (Backup $record): ?string => self::fullDrivePath($record))
                    ->searchable(query: fn ($query, string $search) => $query
                        ->where('path', 'like', "%{$search}%")
                        ->orWhere('filename', 'like', "%{$search}%"))
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (BackupStatus $state): string => $state->color())
                    ->formatStateUsing(fn (BackupStatus $state): string => $state->label()),
                TextColumn::make('formatted_size')
                    ->label(self::trans('columns.size'))
                    ->placeholder('—'),
                TextColumn::make('started_at')
                    ->label(self::trans('columns.started'))
                    ->dateTime('M j, Y H:i:s')
                    ->description(fn (Backup $record): ?string => $record->started_at?->diffForHumans())
                    ->placeholder('—'),
                TextColumn::make('completed_at')
                    ->label(self::trans('columns.completed'))
                    ->dateTime('M j, Y H:i:s')
                    ->description(fn (Backup $record): ?string => $record->completed_at?->diffForHumans())
                    ->placeholder('—'),
                TextColumn::make('error_message')
                    ->label(self::trans('columns.error'))
                    ->state(fn (Backup $record): string => $record->error_message ?: 'N/A')
                    ->color(fn (Backup $record): ?string => $record->error_message ? 'danger' : 'gray')
                    ->limit(80)
                    ->tooltip(fn (Backup $record): ?string => $record->error_message),
                TextColumn::make('disk')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('details')
                    ->label(self::trans('actions.details'))
                    ->icon('heroicon-o-information-circle')
                    ->color('gray')
                    ->modalHeading(fn (Backup $record): string => $record->filename ?? "Backup #{$record->id}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('2xl')
                    ->schema(fn (Backup $record): array => self::detailsSchema($record)),
                Action::make('view')
                    ->label(self::trans('actions.view'))
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->visible(fn (Backup $record): bool => $record->drive_file_id !== null)
                    ->url(fn (Backup $record): ?string => $record->drive_url, shouldOpenInNewTab: true),
                Action::make('download')
                    ->label(self::trans('actions.download'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (Backup $record): bool => $record->status === BackupStatus::Completed && $record->path !== null)
                    ->action(function (Backup $record, $livewire): void {
                        $route = config('google-drive-backup-manager.download_route', 'backup.download');

                        // User-bound, short-TTL token. The download controller
                        // verifies user_id and expires_at on every hit so a
                        // leaked URL can't be replayed by another user or
                        // after the TTL.
                        $token = encrypt([
                            'path' => $record->path,
                            'user_id' => auth()->id(),
                            'expires_at' => now()->addMinutes(5)->timestamp,
                        ]);

                        $livewire->redirect(
                            route($route, ['path' => $token]),
                            navigate: false,
                        );
                    }),
                Action::make('retry')
                    ->label(self::trans('actions.retry'))
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Backup $record): bool => $record->status === BackupStatus::Failed)
                    ->action(function (Backup $record): void {
                        Backup::queueRun();

                        Notification::make()
                            ->success()
                            ->title(self::trans('notifications.backup_queued_title'))
                            ->send();
                    }),
                DeleteAction::make()
                    ->visible(fn (Backup $record): bool => ! in_array(
                        $record->status,
                        [BackupStatus::Pending, BackupStatus::Running],
                        true,
                    ))
                    ->before(function (Backup $record): void {
                        if ($record->path) {
                            DeleteDriveBackupFile::for($record->disk, $record->path);
                        }
                    })
                    ->successNotificationTitle(self::trans('notifications.backup_deleted')),
            ], position: RecordActionsPosition::BeforeColumns)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('delete')
                        ->label(self::trans('actions.delete'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(self::trans('notifications.bulk_delete_modal_heading'))
                        ->modalDescription(self::trans('notifications.bulk_delete_modal_description'))
                        ->modalSubmitActionLabel(self::trans('actions.delete'))
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $deleted = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                if (in_array($record->status, [BackupStatus::Pending, BackupStatus::Running], true)) {
                                    $skipped++;

                                    continue;
                                }

                                if ($record->path) {
                                    DeleteDriveBackupFile::for($record->disk, $record->path);
                                }

                                $record->delete();
                                $deleted++;
                            }

                            Notification::make()
                                ->success()
                                ->title("Deleted {$deleted} backup".($deleted === 1 ? '' : 's'))
                                ->body(trim(implode(' ', array_filter([
                                    $deleted > 0 ? 'Drive files will be removed in the background.' : null,
                                    $skipped > 0 ? "{$skipped} in-progress backup".($skipped === 1 ? '' : 's').' skipped.' : null,
                                ]))) ?: null)
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * Context-aware empty-state copy. Walks four states in priority order:
     * (1) creds missing, (2) saved OAuth is unreadable, (3) creds set but
     * never authenticated, (4) fully set up — no backups yet.
     *
     * @return array{icon: string, heading: string, description: string}
     */
    private static function emptyState(): array
    {
        $connection = app(GoogleDriveConnection::class);

        if (! $connection->hasCredentials()) {
            return [
                'icon' => 'heroicon-o-key',
                'heading' => self::trans('empty_state.no_credentials.heading'),
                'description' => self::trans('empty_state.no_credentials.description'),
            ];
        }

        if ($connection->hasUnreadableOauth()) {
            return [
                'icon' => 'heroicon-o-exclamation-triangle',
                'heading' => self::trans('empty_state.unreadable_oauth.heading'),
                'description' => self::trans('empty_state.unreadable_oauth.description'),
            ];
        }

        if (! $connection->isConnected()) {
            return [
                'icon' => 'heroicon-o-link',
                'heading' => self::trans('empty_state.not_connected.heading'),
                'description' => self::trans('empty_state.not_connected.description'),
            ];
        }

        return [
            'icon' => 'heroicon-o-circle-stack',
            'heading' => self::trans('empty_state.no_backups.heading'),
            'description' => self::trans('empty_state.no_backups.description'),
        ];
    }

    /**
     * Build the schema shown inside the Details modal — a read-only key/value
     * description list summarising what the backup did and where it went.
     * These are infolist entries (not form fields): the modal only displays
     * data, so entries render as clean label/value pairs rather than editable
     * inputs, and the Drive URL becomes a real link.
     *
     * @return array<int, mixed>
     */
    private static function detailsSchema(Backup $record): array
    {
        $rows = [
            'Status' => $record->status->label(),
            'Disk' => $record->disk,
            'Drive path' => self::fullDrivePath($record),
            'Drive URL' => $record->drive_url,
            'Drive file ID' => $record->drive_file_id,
            'Size' => $record->formatted_size,
            'Started' => $record->started_at?->format('M j, Y H:i:s'),
            'Completed' => $record->completed_at?->format('M j, Y H:i:s'),
            'Duration' => ($record->started_at && $record->completed_at)
                ? ((int) $record->started_at->diffInSeconds($record->completed_at)).'s'
                : null,
            'Triggered by' => $record->triggered_by_label,
            'Created' => $record->created_at?->format('M j, Y H:i:s'),
        ];

        $components = [];

        foreach ($rows as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $entry = TextEntry::make(Str::slug($label, '_'))
                ->label($label)
                ->state((string) $value)
                ->copyable();

            // Drive path / URL / file ID are long — give them the full row.
            if (str_starts_with($label, 'Drive')) {
                $entry->columnSpanFull();
            }

            if ($label === 'Drive URL') {
                $entry->url(Str::sanitizeUrl((string) $value))
                    ->openUrlInNewTab();
            }

            $components[] = $entry;
        }

        if ($record->error_message) {
            $components[] = TextEntry::make('error_message')
                ->label('Error')
                ->state($record->error_message)
                ->color('danger')
                ->columnSpanFull();
        }

        return [
            Grid::make(2)->schema($components),
        ];
    }

    private static function fullDrivePath(Backup $record): ?string
    {
        if ($record->path === null) {
            return null;
        }

        $folder = app(GoogleDriveConnection::class)->getFolderName();

        return ($folder !== null ? trim($folder, '/').'/' : '').ltrim($record->path, '/');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageBackups::route('/'),
        ];
    }
}
