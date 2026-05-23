<?php

namespace Webteractive\GoogleDriveBackupManager\Filament\Resources\Backups\Pages;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Throwable;
use Webteractive\GoogleDriveBackupManager\Filament\Resources\Backups\BackupResource;
use Webteractive\GoogleDriveBackupManager\Models\Backup;
use Webteractive\GoogleDriveBackupManager\Models\Setting;
use Webteractive\GoogleDriveBackupManager\Rules\AbsolutePath;
use Webteractive\GoogleDriveBackupManager\Rules\CronExpressionRule;
use Webteractive\GoogleDriveBackupManager\Services\GoogleDriveConnection;

class ManageBackups extends ManageRecords
{
    protected static string $resource = BackupResource::class;

    /**
     * Translation shortcut — same namespace as the resource.
     */
    protected static function trans(string $key, array $replace = []): string
    {
        return BackupResource::trans($key, $replace);
    }

    public function mount(): void
    {
        parent::mount();

        if (app(GoogleDriveConnection::class)->hasUnreadableOauth()) {
            Notification::make()
                ->danger()
                ->title(self::trans('notifications.unreadable_oauth_title'))
                ->body(self::trans('notifications.unreadable_oauth_body'))
                ->persistent()
                ->send();
        }
    }

    private const ENCRYPTED_SETTING_KEYS = ['client_secret'];

    private const SETTING_FORM_MAP = [
        'google_client_id' => 'client_id',
        'google_client_secret' => 'client_secret',
        'backup_queue' => 'queue',
        'backup_jobs_run_sync' => 'jobs_run_sync',
        'backup_database_targets' => 'database_targets',
        'backup_file_targets' => 'file_targets',
        'notify_email_to' => 'notify_email_to',
        'notify_email_from_address' => 'notify_email_from_address',
        'notify_email_from_name' => 'notify_email_from_name',
        'notify_slack_webhook' => 'notify_slack_webhook',
        'notify_discord_webhook' => 'notify_discord_webhook',
        'notify_google_chat_webhook' => 'notify_google_chat_webhook',
        'notify_generic_webhook' => 'notify_generic_webhook',
        'notify_events' => 'notify_events',
        'schedule_backup_enabled' => 'schedule_backup_enabled',
        'schedule_backup_cron' => 'schedule_backup_cron',
        'schedule_cleanup_enabled' => 'schedule_cleanup_enabled',
        'schedule_cleanup_cron' => 'schedule_cleanup_cron',
        'schedule_monitor_enabled' => 'schedule_monitor_enabled',
        'schedule_monitor_cron' => 'schedule_monitor_cron',
        'cleanup_keep_all_days' => 'cleanup_keep_all_days',
        'cleanup_keep_daily_days' => 'cleanup_keep_daily_days',
        'cleanup_keep_weekly_weeks' => 'cleanup_keep_weekly_weeks',
        'cleanup_keep_monthly_months' => 'cleanup_keep_monthly_months',
        'cleanup_keep_yearly_years' => 'cleanup_keep_yearly_years',
        'cleanup_max_megabytes' => 'cleanup_max_megabytes',
        'cleanup_prune_rows_after_days' => 'cleanup_prune_rows_after_days',
    ];

    /**
     * Settings key → Spatie cleanup.default_strategy.* key. Applied in the
     * service provider only when the user has explicitly set a value; empty
     * keeps Spatie's vendor defaults.
     */
    public const CLEANUP_FIELD_MAP = [
        'cleanup_keep_all_days' => 'keep_all_backups_for_days',
        'cleanup_keep_daily_days' => 'keep_daily_backups_for_days',
        'cleanup_keep_weekly_weeks' => 'keep_weekly_backups_for_weeks',
        'cleanup_keep_monthly_months' => 'keep_monthly_backups_for_months',
        'cleanup_keep_yearly_years' => 'keep_yearly_backups_for_years',
        'cleanup_max_megabytes' => 'delete_oldest_backups_when_using_more_megabytes_than',
    ];

    private const SCHEDULE_PRESETS = [
        '0 * * * *' => 'Every hour',
        '0 */6 * * *' => 'Every 6 hours',
        '0 */12 * * *' => 'Every 12 hours',
        '0 0 * * *' => 'Daily at midnight',
        '0 2 * * *' => 'Daily at 2:00 AM',
        '0 6 * * *' => 'Daily at 6:00 AM',
        '0 2 * * 0' => 'Weekly (Sunday 2:00 AM)',
        '0 2 1 * *' => 'Monthly (1st at 2:00 AM)',
    ];

    protected function getHeaderActions(): array
    {
        $actions = [
            $this->settingsAction(),
        ];

        if (app(GoogleDriveConnection::class)->isConnected()) {
            $actions[] = $this->runBackupAction();
        }

        return $actions;
    }

    /**
     * Visibility closure used by every non-OAuth settings tab — they only make
     * sense once both credentials and an active OAuth connection are present.
     */
    private function whenReady(): Closure
    {
        return fn (): bool => app(GoogleDriveConnection::class)->isReady();
    }

    private function settingsAction(): Action
    {
        return Action::make('settings')
            ->label(self::trans('actions.settings'))
            ->icon('heroicon-o-cog-6-tooth')
            ->color('gray')
            ->modalHeading('Backup Manager Settings')
            ->modalDescription('OAuth credentials and backup target. Client secret is stored encrypted.')
            ->modalSubmitActionLabel('Save')
            ->modalWidth('3xl')
            ->fillForm(fn (): array => $this->settingsFormDefaults())
            ->schema([
                Tabs::make('settings')
                    ->tabs([
                        Tab::make(self::trans('tabs.oauth'))
                            ->icon('heroicon-o-key')
                            ->schema([
                                TextInput::make('google_client_id')
                                    ->label('Client ID')
                                    ->maxLength(255),
                                TextInput::make('google_client_secret')
                                    ->label('Client Secret')
                                    ->password()
                                    ->revealable()
                                    ->maxLength(255)
                                    ->helperText('Stored encrypted at rest. Leave blank to keep the existing secret — it is never sent back to the browser.'),
                                TextInput::make('google_redirect_uri')
                                    ->label('Redirect URI')
                                    ->readOnly()
                                    ->dehydrated(false)
                                    ->helperText('Copy this and paste it into your Google Cloud OAuth client\'s Authorized redirect URIs. This value is managed by the package.')
                                    ->suffixAction(
                                        Action::make('copyRedirectUri')
                                            ->icon('heroicon-m-clipboard-document')
                                            ->tooltip('Copy')
                                            ->action(function ($state, $livewire): void {
                                                $livewire->js(
                                                    'window.navigator.clipboard.writeText('.json_encode((string) $state).')'
                                                );
                                            })
                                    ),
                                SchemaActions::make([
                                    Action::make('saveAndAuthenticate')
                                        ->label(self::trans('actions.save_and_authenticate'))
                                        ->icon('heroicon-o-link')
                                        ->color('success')
                                        ->action(function ($livewire) {
                                            // The settings modal is the outer (index 0) mounted action.
                                            // Without the index, Filament returns this inner action's
                                            // own (empty) schema and we'd persist nothing.
                                            $data = $livewire->getMountedActionSchema(0)?->getState()
                                                ?? ($livewire->mountedActionsData[0] ?? []);

                                            $this->persistSettings($data);

                                            if (! app(GoogleDriveConnection::class)->hasCredentials()) {
                                                Notification::make()
                                                    ->warning()
                                                    ->title(self::trans('notifications.fill_in_creds'))
                                                    ->send();

                                                return null;
                                            }

                                            return redirect()->to(route('google-drive-backup-manager.google.redirect'));
                                        }),
                                    Action::make('disconnectGoogle')
                                        ->label(self::trans('actions.disconnect'))
                                        ->icon('heroicon-o-x-circle')
                                        ->color('danger')
                                        ->visible(fn (): bool => app(GoogleDriveConnection::class)->isConnected())
                                        ->requiresConfirmation()
                                        ->modalHeading('Disconnect Google Account')
                                        ->modalDescription('Backups will no longer run until a Google account is reconnected.')
                                        ->modalSubmitActionLabel('Disconnect')
                                        ->action(function (): void {
                                            app(GoogleDriveConnection::class)->disconnect();

                                            Notification::make()
                                                ->success()
                                                ->title('Google account disconnected.')
                                                ->send();
                                        }),
                                ]),
                            ]),
                        Tab::make(self::trans('tabs.backup'))
                            ->icon('heroicon-o-circle-stack')
                            ->visible($this->whenReady())
                            ->schema([
                                TextInput::make('backup_folder')
                                    ->label('Folder Name')
                                    ->placeholder('Leave empty to use Drive root')
                                    ->maxLength(255)
                                    ->helperText('The name of the folder in your Google Drive where backups go. If it doesn\'t exist, we\'ll create it under your Drive root on save.'),
                                TextInput::make('backup_queue')
                                    ->label('Queue name')
                                    ->placeholder('default')
                                    ->helperText('Optional queue to dispatch backup jobs to.'),
                                Toggle::make('backup_jobs_run_sync')
                                    ->label('Run jobs synchronously')
                                    ->helperText('Run backup + Drive cleanup inline in the request instead of queuing them. Useful when no queue worker is running. Backups will block the UI until they finish.'),
                            ]),
                        Tab::make(self::trans('tabs.databases'))
                            ->icon('heroicon-o-server-stack')
                            ->visible($this->whenReady())
                            ->schema([
                                Repeater::make('backup_database_targets')
                                    ->hiddenLabel()
                                    ->required()
                                    ->minItems(1)
                                    ->reorderable(false)
                                    ->addActionLabel('Add database target')
                                    ->itemLabel(fn (array $state): ?string => is_string($state['connection'] ?? null)
                                        ? $state['connection'].(empty($state['databases']) ? '' : ' → '.implode(', ', (array) $state['databases']))
                                        : null)
                                    ->schema([
                                        Select::make('connection')
                                            ->label('Connection')
                                            ->required()
                                            ->options(fn () => collect(array_keys(config('database.connections', [])))
                                                ->mapWithKeys(fn (string $name) => [$name => $name])
                                                ->all())
                                            ->searchable(),
                                        TagsInput::make('databases')
                                            ->label('Specific databases')
                                            ->placeholder('Leave empty to use the connection\'s default')
                                            ->nestedRecursiveRules(['regex:/^[A-Za-z0-9_\-]+$/'])
                                            ->helperText('Names of databases on this connection\'s server to dump. Letters, digits, dash and underscore only — values flow into the PDO DSN. Empty = dump only the database configured on that connection.'),
                                    ])
                                    ->helperText('Required — backups will not run unless at least one target is configured. Leave the specific databases empty to back up only the connection\'s configured database.'),
                            ]),
                        Tab::make(self::trans('tabs.files'))
                            ->icon('heroicon-o-folder')
                            ->visible($this->whenReady())
                            ->schema([
                                Repeater::make('backup_file_targets')
                                    ->hiddenLabel()
                                    ->reorderable(false)
                                    ->addActionLabel('Add file target')
                                    ->itemLabel(fn (array $state): ?string => is_string($state['path'] ?? null)
                                        ? $state['path'].(empty($state['exclude']) ? '' : ' (− '.count((array) $state['exclude']).')')
                                        : null)
                                    ->schema([
                                        TextInput::make('path')
                                            ->label('Include path')
                                            ->required()
                                            ->placeholder('/absolute/path/to/include')
                                            ->rules([new AbsolutePath]),
                                        TagsInput::make('exclude')
                                            ->label('Exclude paths')
                                            ->placeholder('Absolute path to exclude')
                                            ->nestedRecursiveRules([new AbsolutePath])
                                            ->helperText('Optional. Any path under the include path that should be skipped.'),
                                    ])
                                    ->helperText('Optional — if no targets are added, backups will contain databases only.'),
                            ]),
                        Tab::make(self::trans('tabs.schedule'))
                            ->icon('heroicon-o-clock')
                            ->visible($this->whenReady())
                            ->schema([
                                Section::make('Backup schedule')
                                    ->description('Run a backup automatically using the configured database and file targets.')
                                    ->compact()
                                    ->schema($this->scheduleSectionFields('backup', '0 2 * * *'))
                                    ->columnSpanFull(),
                                Section::make('Cleanup schedule')
                                    ->description('Run Spatie\'s retention strategy against the gdbm disk to prune old backups.')
                                    ->compact()
                                    ->schema($this->scheduleSectionFields('cleanup', '0 3 * * *'))
                                    ->columnSpanFull(),
                                Section::make('Monitor schedule')
                                    ->description('Run Spatie\'s health check — fires HealthyBackupWasFound / UnhealthyBackupWasFound events which feed into the Notifications tab.')
                                    ->compact()
                                    ->schema($this->scheduleSectionFields('monitor', '0 4 * * *'))
                                    ->columnSpanFull(),
                            ]),
                        Tab::make(self::trans('tabs.cleanup'))
                            ->icon('heroicon-o-trash')
                            ->visible($this->whenReady())
                            ->schema([
                                TextInput::make('cleanup_keep_all_days')
                                    ->label('Keep all backups for (days)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->placeholder('7')
                                    ->helperText('Every backup is kept untouched for this many days, regardless of strategy.'),
                                TextInput::make('cleanup_keep_daily_days')
                                    ->label('Keep one daily backup for (days)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->placeholder('16'),
                                TextInput::make('cleanup_keep_weekly_weeks')
                                    ->label('Keep one weekly backup for (weeks)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->placeholder('8'),
                                TextInput::make('cleanup_keep_monthly_months')
                                    ->label('Keep one monthly backup for (months)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->placeholder('4'),
                                TextInput::make('cleanup_keep_yearly_years')
                                    ->label('Keep one yearly backup for (years)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->placeholder('2'),
                                TextInput::make('cleanup_max_megabytes')
                                    ->label('Max total size (MB)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->placeholder('5000')
                                    ->helperText('When the total size of all backups exceeds this, the oldest ones get deleted first.'),
                                TextInput::make('cleanup_prune_rows_after_days')
                                    ->label('Prune table rows older than (days)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->placeholder('Empty = never')
                                    ->helperText('Spatie cleans Drive files via its retention strategy above; this prunes our gdbm_backups rows so the table doesn\'t grow forever. Runs as part of the cleanup schedule.'),
                            ]),
                        Tab::make(self::trans('tabs.notifications'))
                            ->icon('heroicon-o-bell')
                            ->visible($this->whenReady())
                            ->schema([
                                TagsInput::make('notify_email_to')
                                    ->label('Email recipients')
                                    ->placeholder('user@example.com')
                                    ->nestedRecursiveRules(['email'])
                                    ->helperText('Add one or more addresses. Empty disables email notifications.'),
                                TextInput::make('notify_email_from_address')
                                    ->label('Email from address')
                                    ->email()
                                    ->placeholder(config('mail.from.address') ?? 'hello@example.com')
                                    ->helperText('Optional. Falls back to mail.from.address.'),
                                TextInput::make('notify_email_from_name')
                                    ->label('Email from name')
                                    ->placeholder(config('mail.from.name') ?? 'Backups')
                                    ->helperText('Optional. Falls back to mail.from.name.'),
                                TextInput::make('notify_slack_webhook')
                                    ->label('Slack webhook URL')
                                    ->url()
                                    ->placeholder('https://hooks.slack.com/services/…'),
                                TextInput::make('notify_discord_webhook')
                                    ->label('Discord webhook URL')
                                    ->url()
                                    ->placeholder('https://discord.com/api/webhooks/…'),
                                TextInput::make('notify_google_chat_webhook')
                                    ->label('Google Chat webhook URL')
                                    ->url()
                                    ->placeholder('https://chat.googleapis.com/v1/spaces/…'),
                                TextInput::make('notify_generic_webhook')
                                    ->label('Generic webhook URL')
                                    ->url()
                                    ->placeholder('https://your-service.example.com/hooks/backup')
                                    ->helperText('Receives a JSON payload describing the event on POST.'),
                                CheckboxList::make('notify_events')
                                    ->label('Send notifications for')
                                    ->options([
                                        'backup_successful' => 'Backup successful',
                                        'backup_failed' => 'Backup failed',
                                        'healthy_found' => 'Healthy backup found',
                                        'unhealthy_found' => 'Unhealthy backup found',
                                        'cleanup_successful' => 'Cleanup successful',
                                        'cleanup_failed' => 'Cleanup failed',
                                    ])
                                    ->columns(2)
                                    ->helperText('Pick which events trigger notifications. Channels above must have at least one destination configured.'),
                            ]),
                    ]),
            ])
            ->action(function (array $data): void {
                $this->persistSettings($data);

                Notification::make()
                    ->success()
                    ->title(self::trans('notifications.settings_saved'))
                    ->send();
            });
    }

    /** @return array<string, mixed> */
    private function settingsFormDefaults(): array
    {
        // Encrypted secrets must never be pre-filled into the form: Livewire
        // serializes form state into the page snapshot, so ->password() alone
        // would still ship the plaintext to the browser. We omit them from the
        // hydrate-fetch and instead read them back in persistSettings only
        // when the user explicitly typed a new value.
        $hydrateKeys = array_filter(
            array_values(self::SETTING_FORM_MAP),
            fn (string $key): bool => ! in_array($key, self::ENCRYPTED_SETTING_KEYS, true),
        );

        $values = Setting::getMany($hydrateKeys);

        $defaults = [];

        foreach (self::SETTING_FORM_MAP as $formKey => $settingKey) {
            $defaults[$formKey] = in_array($settingKey, self::ENCRYPTED_SETTING_KEYS, true)
                ? null
                : ($values[$settingKey] ?? null);
        }

        $connection = app(GoogleDriveConnection::class);
        $defaults['google_redirect_uri'] = $connection->getRedirectUri();
        $defaults['backup_folder'] = $connection->getFolderName();

        foreach (['backup', 'cleanup', 'monitor'] as $task) {
            $cron = $defaults["schedule_{$task}_cron"] ?? null;
            $defaults["schedule_{$task}_preset"] = is_string($cron) && array_key_exists($cron, self::SCHEDULE_PRESETS)
                ? $cron
                : 'custom';
        }

        return $defaults;
    }

    /** @param array<string, mixed> $data */
    private function persistSettings(array $data): void
    {
        if (isset($data['backup_file_targets'])) {
            $data['backup_file_targets'] = $this->normalizeFileTargets($data['backup_file_targets']);
        }

        foreach (self::SETTING_FORM_MAP as $formKey => $settingKey) {
            $value = $data[$formKey] ?? null;
            $isEncrypted = in_array($settingKey, self::ENCRYPTED_SETTING_KEYS, true);

            if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                // Encrypted fields are never pre-filled — an empty submission
                // means "no change", not "delete". The user clears an
                // encrypted secret by explicitly removing it via tinker or a
                // future dedicated "clear" action.
                if ($isEncrypted) {
                    continue;
                }

                Setting::forget($settingKey);

                continue;
            }

            Setting::set($settingKey, $value, encrypted: $isEncrypted);
        }

        $folderName = $data['backup_folder'] ?? null;

        try {
            app(GoogleDriveConnection::class)->setFolder(
                is_string($folderName) ? $folderName : null,
            );
        } catch (Throwable $e) {
            Notification::make()
                ->warning()
                ->title(self::trans('notifications.folder_resolve_failed'))
                ->body($e->getMessage())
                ->send();
        }
    }

    private function runBackupAction(): Action
    {
        return Action::make('runBackup')
            ->label(self::trans('actions.run_backup'))
            ->icon('heroicon-o-play-circle')
            ->requiresConfirmation()
            ->modalDescription('Run a backup using the database and file targets configured in Settings.')
            ->modalSubmitActionLabel(self::trans('actions.run'))
            ->action(function (): void {
                if (! $this->backupSourcesConfigured()) {
                    Notification::make()
                        ->warning()
                        ->title(self::trans('notifications.backup_not_configured_title'))
                        ->body(self::trans('notifications.backup_not_configured_body'))
                        ->send();

                    return;
                }

                Backup::queueRun();

                Notification::make()
                    ->success()
                    ->title(self::trans('notifications.backup_queued_title'))
                    ->body(self::trans('notifications.backup_queued_body'))
                    ->send();
            });
    }

    /**
     * Trim each path and strip trailing slashes so `/foo/` and `/foo` don't
     * end up as separate dedupe candidates downstream.
     *
     * @param  mixed  $targets
     * @return array<int, array{path: string, exclude: array<int, string>}>
     */
    private function normalizeFileTargets($targets): array
    {
        if (! is_array($targets)) {
            return [];
        }

        $normalized = [];

        foreach ($targets as $target) {
            if (! is_array($target)) {
                continue;
            }

            $path = $this->normalizePath($target['path'] ?? null);

            if ($path === null) {
                continue;
            }

            $exclude = [];
            foreach ((array) ($target['exclude'] ?? []) as $entry) {
                $clean = $this->normalizePath($entry);
                if ($clean !== null) {
                    $exclude[] = $clean;
                }
            }

            $normalized[] = ['path' => $path, 'exclude' => array_values(array_unique($exclude))];
        }

        return $normalized;
    }

    private function normalizePath(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        // Strip trailing slashes (but keep "/" itself).
        return $trimmed === '/' ? '/' : rtrim($trimmed, '/');
    }

    /**
     * Three fields used for both backup + cleanup schedules. Cron TextInput
     * is shown only when the preset is set to "custom" — otherwise the cron
     * value is fully driven by the preset Select.
     *
     * @return array<int, mixed>
     */
    private function scheduleSectionFields(string $task, string $defaultCron): array
    {
        $enabledKey = "schedule_{$task}_enabled";
        $cronKey = "schedule_{$task}_cron";
        $presetKey = "schedule_{$task}_preset";

        return [
            Toggle::make($enabledKey)
                ->label('Enabled')
                ->live(),
            Select::make($presetKey)
                ->label('Frequency')
                ->dehydrated(false)
                ->visible(fn (Get $get): bool => (bool) $get($enabledKey))
                ->options(self::SCHEDULE_PRESETS + ['custom' => 'Custom…'])
                ->afterStateUpdated(function ($state, callable $set) use ($cronKey, $defaultCron): void {
                    if ($state !== null && $state !== 'custom') {
                        $set($cronKey, $state);
                    } elseif ($state === 'custom') {
                        // Seed a sensible default so the visible TextInput isn't empty.
                        $set($cronKey, $defaultCron);
                    }
                })
                ->live(),
            TextInput::make($cronKey)
                ->label('Cron expression')
                ->placeholder($defaultCron)
                ->required(fn (Get $get): bool => (bool) $get($enabledKey))
                ->visible(fn (Get $get): bool => (bool) $get($enabledKey) && $get($presetKey) === 'custom')
                ->dehydratedWhenHidden()
                ->rules([new CronExpressionRule])
                ->helperText('Standard 5-field cron: minute hour day-of-month month day-of-week.'),
        ];
    }

    private function backupSourcesConfigured(): bool
    {
        // Databases are required; files are optional (empty file_targets just
        // means a DB-only zip).
        return (array) (Setting::get('database_targets') ?? []) !== [];
    }
}
