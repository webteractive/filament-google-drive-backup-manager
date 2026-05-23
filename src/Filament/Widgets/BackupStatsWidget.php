<?php

namespace Webteractive\GoogleDriveBackupManager\Filament\Widgets;

use Carbon\CarbonInterface;
use Cron\CronExpression;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Throwable;
use Webteractive\GoogleDriveBackupManager\Enums\BackupStatus;
use Webteractive\GoogleDriveBackupManager\Models\Backup;
use Webteractive\GoogleDriveBackupManager\Models\Setting;
use Webteractive\GoogleDriveBackupManager\Services\GoogleDriveConnection;

/**
 * Three-stat overview for the admin dashboard:
 *   1. Last backup outcome + when
 *   2. Next scheduled backup fire time
 *   3. Total backups + cumulative size
 *
 * Hidden when the host hasn't granted access via the configured gate.
 */
class BackupStatsWidget extends StatsOverviewWidget
{
    protected ?string $heading = null;

    public function getHeading(): ?string
    {
        return __('google-drive-backup-manager::google-drive-backup-manager.widget.heading');
    }

    public static function canView(): bool
    {
        $gate = config('google-drive-backup-manager.gate', 'viewBackups');

        return Gate::has($gate) && Gate::allows($gate);
    }

    protected function getStats(): array
    {
        return [
            $this->lastBackupStat(),
            $this->nextRunStat(),
            $this->totalsStat(),
        ];
    }

    private function lastBackupStat(): Stat
    {
        $last = Backup::query()->orderByDesc('id')->first();

        if (! $last) {
            return Stat::make('Last backup', 'No runs yet')
                ->description(app(GoogleDriveConnection::class)->isConnected()
                    ? 'Click Run Backup to create one.'
                    : 'Configure Google OAuth in Settings.')
                ->color('gray')
                ->icon('heroicon-o-clock');
        }

        $status = $last->status;

        return Stat::make('Last backup', $status->label())
            ->description($last->completed_at?->diffForHumans() ?? $last->created_at?->diffForHumans() ?? '—')
            ->descriptionIcon(match ($status) {
                BackupStatus::Completed => 'heroicon-o-check-circle',
                BackupStatus::Failed => 'heroicon-o-x-circle',
                BackupStatus::Running => 'heroicon-o-arrow-path',
                default => 'heroicon-o-clock',
            })
            ->color($status->color());
    }

    private function nextRunStat(): Stat
    {
        if (! (bool) Setting::get('schedule_backup_enabled')) {
            return Stat::make('Next scheduled', 'Disabled')
                ->description('Enable scheduled backups in Settings → Schedule.')
                ->color('gray')
                ->icon('heroicon-o-pause-circle');
        }

        $cron = Setting::get('schedule_backup_cron');

        try {
            $next = is_string($cron) ? (new CronExpression(trim($cron)))->getNextRunDate() : null;
        } catch (Throwable) {
            $next = null;
        }

        if (! $next) {
            return Stat::make('Next scheduled', 'Invalid cron')
                ->color('warning')
                ->icon('heroicon-o-exclamation-triangle');
        }

        return Stat::make('Next scheduled', $next->format('M j, H:i'))
            ->description('in '.now()->diffForHumans($next, ['parts' => 2, 'syntax' => CarbonInterface::DIFF_ABSOLUTE]))
            ->color('success')
            ->icon('heroicon-o-clock');
    }

    private function totalsStat(): Stat
    {
        // One round-trip instead of count() + sum() — meaningful once the
        // gdbm_backups table has accumulated many rows.
        $row = Backup::query()
            ->completed()
            ->toBase()
            ->selectRaw('count(*) as total_count, coalesce(sum(size_bytes), 0) as total_bytes')
            ->first();

        $count = (int) ($row->total_count ?? 0);
        $bytes = (int) ($row->total_bytes ?? 0);

        return Stat::make('Stored', Number::fileSize($bytes))
            ->description($count.' completed backup'.($count === 1 ? '' : 's'))
            ->color('primary')
            ->icon('heroicon-o-circle-stack');
    }
}
