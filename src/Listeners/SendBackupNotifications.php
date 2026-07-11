<?php

namespace Webteractive\GoogleDriveBackupManager\Listeners;

use Filament\Actions\Action as FilamentNotificationAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\CleanupWasSuccessful;
use Spatie\Backup\Events\HealthyBackupWasFound;
use Spatie\Backup\Events\UnhealthyBackupWasFound;
use Throwable;
use Webteractive\GoogleDriveBackupManager\Models\Backup;
use Webteractive\GoogleDriveBackupManager\Models\Setting;
use Webteractive\GoogleDriveBackupManager\Support\BackupMessages;

/**
 * Owns all backup notifications. Spatie's native notification pipeline is
 * disabled in the service provider, so this listener is the single fan-out
 * point — it formats per channel (email HTML, Slack attachments, Discord
 * embeds, Google Chat markdown, generic JSON) and sends only to channels
 * the user has configured AND for events the user has opted into.
 *
 * RecordBackupOutcome runs before this listener (registration order), so we
 * can pull the just-finalized Backup row for size, Drive URL, duration, etc.
 */
class SendBackupNotifications
{
    private const COLOR_SUCCESS_HEX = '#16a34a';

    private const COLOR_SUCCESS_INT = 1474643;

    private const COLOR_FAILURE_HEX = '#dc2626';

    private const COLOR_FAILURE_INT = 14431774;

    public function handleSuccess(BackupWasSuccessful $event): void
    {
        $row = $this->latestBackup();
        $context = $this->successContext($event, $row);

        // Filament bell goes to the user who triggered the backup (set on the
        // row by Backup::queueRun). Scheduled/CLI runs have no user, so the
        // bell is skipped and the configured external channels carry the
        // notification instead.
        $this->sendFilamentNotification($context, $row);

        if (! $this->isEventEnabled('backup_successful')) {
            return;
        }

        $this->fanOut($context);
    }

    public function handleFailure(BackupHasFailed $event): void
    {
        $row = $this->latestBackup();
        $context = $this->failureContext($event);

        $this->sendFilamentNotification($context, $row);

        if (! $this->isEventEnabled('backup_failed')) {
            return;
        }

        $this->fanOut($context);
    }

    public function handleHealthy(HealthyBackupWasFound $event): void
    {
        if (! $this->isEventEnabled('healthy_found')) {
            return;
        }

        $this->fanOut($this->monitorContext($event, isSuccess: true, eventKey: 'healthy_found', title: 'Backup is healthy'));
    }

    public function handleUnhealthy(UnhealthyBackupWasFound $event): void
    {
        if (! $this->isEventEnabled('unhealthy_found')) {
            return;
        }

        $context = $this->monitorContext($event, isSuccess: false, eventKey: 'unhealthy_found', title: 'Backup is unhealthy');

        // Spatie surfaces the actual problem(s) via the failureMessages
        // Collection (each item is ['check' => ..., 'message' => ...]).
        // Build a concise summary so notifications tell the user WHY.
        $summary = $event->failureMessages
            ->map(fn ($message): string => is_array($message)
                ? sprintf('%s: %s', $message['check'] ?? 'unknown', $message['message'] ?? '')
                : (string) $message)
            ->filter()
            ->implode('; ');

        $context['error'] = BackupMessages::redact(
            $summary !== '' ? $summary : 'Unhealthy backup detected.',
        );

        $this->fanOut($context);
    }

    public function handleCleanupSuccess(CleanupWasSuccessful $event): void
    {
        if (! $this->isEventEnabled('cleanup_successful')) {
            return;
        }

        $this->fanOut($this->monitorContext($event, isSuccess: true, eventKey: 'cleanup_successful', title: 'Cleanup successful'));
    }

    public function handleCleanupFailure(CleanupHasFailed $event): void
    {
        if (! $this->isEventEnabled('cleanup_failed')) {
            return;
        }

        $context = $this->monitorContext($event, isSuccess: false, eventKey: 'cleanup_failed', title: 'Cleanup failed');
        $context['error'] = BackupMessages::redact($event->exception->getMessage());

        $this->fanOut($context);
    }

    /**
     * @return array<string, mixed>
     */
    private function monitorContext(object $event, bool $isSuccess, string $eventKey, string $title): array
    {
        // All four monitor/cleanup events expose `$diskName` and `$backupName`
        // as public string props (Spatie v10). They do NOT have a
        // `$backupDestination` accessor — reading it triggers an E_DEPRECATED
        // dynamic-property notice and produces 'unknown'.
        $diskName = property_exists($event, 'diskName') && is_string($event->diskName)
            ? $event->diskName
            : 'unknown';
        $backupName = property_exists($event, 'backupName') && is_string($event->backupName)
            ? $event->backupName
            : 'unknown';

        return [
            'event' => $eventKey,
            'is_success' => $isSuccess,
            'icon' => $isSuccess ? '✅' : '⚠️',
            'title' => $title,
            'color_hex' => $isSuccess ? self::COLOR_SUCCESS_HEX : self::COLOR_FAILURE_HEX,
            'color_int' => $isSuccess ? self::COLOR_SUCCESS_INT : self::COLOR_FAILURE_INT,
            'app' => config('app.name'),
            'environment' => app()->environment(),
            'disk' => $diskName,
            'backup_name' => $backupName,
            'filename' => null,
            'path' => null,
            'size_bytes' => null,
            'size_human' => null,
            'drive_file_id' => null,
            'drive_url' => null,
            'duration_seconds' => null,
            'started_at' => null,
            'completed_at' => null,
            'timestamp' => now()->toIso8601String(),
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function fanOut(array $context): void
    {
        $this->sendEmail($context);
        $this->sendSlack($context);
        $this->sendDiscord($context);
        $this->sendGoogleChat($context);
        $this->sendGenericWebhook($context);
    }

    private function isEventEnabled(string $eventKey): bool
    {
        return in_array($eventKey, (array) (Setting::get('notify_events') ?? []), true);
    }

    private function latestBackup(): ?Backup
    {
        return Backup::query()->orderByDesc('id')->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function successContext(BackupWasSuccessful $event, ?Backup $row): array
    {
        return [
            'event' => 'backup_successful',
            'is_success' => true,
            'icon' => '✅',
            'title' => 'Backup successful',
            'color_hex' => self::COLOR_SUCCESS_HEX,
            'color_int' => self::COLOR_SUCCESS_INT,
            'app' => config('app.name'),
            'environment' => app()->environment(),
            'disk' => $event->diskName,
            'backup_name' => $event->backupName,
            'filename' => $row?->filename,
            'path' => $row?->path,
            'size_bytes' => $row?->size_bytes,
            'size_human' => $row?->formatted_size,
            'drive_file_id' => $row?->drive_file_id,
            'drive_url' => $row?->drive_url,
            'duration_seconds' => $this->durationSeconds($row),
            'started_at' => optional($row?->started_at)->toIso8601String(),
            'completed_at' => optional($row?->completed_at)->toIso8601String(),
            'timestamp' => now()->toIso8601String(),
            'error' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failureContext(BackupHasFailed $event): array
    {
        return [
            'event' => 'backup_failed',
            'is_success' => false,
            'icon' => '❌',
            'title' => 'Backup failed',
            'color_hex' => self::COLOR_FAILURE_HEX,
            'color_int' => self::COLOR_FAILURE_INT,
            'app' => config('app.name'),
            'environment' => app()->environment(),
            'disk' => $event->diskName,
            'backup_name' => $event->backupName,
            'filename' => null,
            'path' => null,
            'size_bytes' => null,
            'size_human' => null,
            'drive_file_id' => null,
            'drive_url' => null,
            'duration_seconds' => null,
            'started_at' => null,
            'completed_at' => null,
            'timestamp' => now()->toIso8601String(),
            'error' => BackupMessages::redact($event->exception->getMessage()),
            'error_class' => $event->exception::class,
        ];
    }

    private function durationSeconds(?Backup $row): ?int
    {
        if (! $row?->started_at || ! $row?->completed_at) {
            return null;
        }

        // Carbon v3 returns signed diffs by default — swap the receiver so
        // start→end reads as a positive elapsed value.
        return (int) $row->started_at->diffInSeconds($row->completed_at);
    }

    /**
     * Field rows used by every channel that supports key/value layouts
     * (Slack fields, Discord fields, email table rows). Order matters and
     * empty values are dropped at render time.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<int, array{label: string, value: ?string}>
     */
    private function detailRows(array $ctx): array
    {
        $rows = [
            ['label' => 'App', 'value' => $ctx['app']],
            ['label' => 'Environment', 'value' => $ctx['environment']],
            ['label' => 'Disk', 'value' => $ctx['disk']],
            ['label' => 'File', 'value' => $ctx['filename']],
            ['label' => 'Size', 'value' => $ctx['size_human']],
            ['label' => 'Duration', 'value' => $ctx['duration_seconds'] !== null ? $ctx['duration_seconds'].'s' : null],
            ['label' => 'Error', 'value' => $ctx['error']],
        ];

        return array_values(array_filter($rows, fn (array $r): bool => $r['value'] !== null && $r['value'] !== ''));
    }

    // ------------------------------------------------------------------
    // Channels
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function sendEmail(array $ctx): void
    {
        $recipients = (array) (Setting::get('notify_email_to') ?? []);

        if ($recipients === []) {
            return;
        }

        try {
            $fromAddress = Setting::get('notify_email_from_address') ?: config('mail.from.address');
            $fromName = Setting::get('notify_email_from_name') ?: config('mail.from.name');

            $subject = sprintf('[%s] %s', $ctx['app'], $ctx['title']);
            $html = $this->buildEmailHtml($ctx);

            Mail::html($html, function ($message) use ($recipients, $subject, $fromAddress, $fromName): void {
                $message->to($recipients)->subject($subject);
                if ($fromAddress) {
                    $message->from($fromAddress, $fromName ?: null);
                }
            });
        } catch (Throwable $e) {
            Log::warning('gdbm email notification failed', ['error' => BackupMessages::redactUrls($e->getMessage())]);
        }
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function buildEmailHtml(array $ctx): string
    {
        $rowsHtml = collect($this->detailRows($ctx))
            ->map(fn (array $r): string => sprintf(
                '<tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;border-bottom:1px solid #f3f4f6;">%s</td>'
                .'<td style="padding:6px 12px;color:#111827;font-size:13px;border-bottom:1px solid #f3f4f6;">%s</td></tr>',
                e($r['label']),
                e($r['value']),
            ))
            ->implode('');

        $driveButton = $ctx['drive_url']
            ? sprintf(
                '<p style="margin:24px 0 0;"><a href="%s" style="background:#16a34a;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;font-size:14px;display:inline-block;">Open on Google Drive</a></p>',
                e($ctx['drive_url']),
            )
            : '';

        return sprintf(
            '<!doctype html><html><body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;background:#f9fafb;margin:0;padding:24px;">'
            .'<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:8px;border:1px solid #e5e7eb;overflow:hidden;">'
            .'<div style="background:%s;padding:16px 20px;color:#fff;font-size:16px;font-weight:600;">%s %s</div>'
            .'<div style="padding:20px;"><table style="width:100%%;border-collapse:collapse;">%s</table>%s</div>'
            .'</div></body></html>',
            $ctx['color_hex'],
            $ctx['icon'],
            e($ctx['title']),
            $rowsHtml,
            $driveButton,
        );
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function sendSlack(array $ctx): void
    {
        $url = Setting::get('notify_slack_webhook');

        if (! is_string($url) || $url === '') {
            return;
        }

        $fields = collect($this->detailRows($ctx))
            ->map(fn (array $r): array => [
                'title' => $r['label'],
                'value' => (string) $r['value'],
                'short' => mb_strlen((string) $r['value']) < 40,
            ])
            ->all();

        $attachment = [
            'color' => $ctx['color_hex'],
            'title' => sprintf('%s %s', $ctx['icon'], $ctx['title']),
            'fields' => $fields,
            'ts' => now()->timestamp,
        ];

        if ($ctx['drive_url']) {
            $attachment['title_link'] = $ctx['drive_url'];
        }

        try {
            Http::asJson()
                ->timeout(5)
                ->post($url, [
                    'text' => sprintf('%s %s — %s', $ctx['icon'], $ctx['title'], $ctx['app']),
                    'attachments' => [$attachment],
                ])
                ->throw();
        } catch (Throwable $e) {
            Log::warning('gdbm slack notification failed', ['error' => BackupMessages::redactUrls($e->getMessage())]);
        }
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function sendDiscord(array $ctx): void
    {
        $url = Setting::get('notify_discord_webhook');

        if (! is_string($url) || $url === '') {
            return;
        }

        $fields = collect($this->detailRows($ctx))
            ->map(fn (array $r): array => [
                'name' => $r['label'],
                'value' => (string) $r['value'],
                'inline' => mb_strlen((string) $r['value']) < 40,
            ])
            ->all();

        $embed = [
            'title' => sprintf('%s %s', $ctx['icon'], $ctx['title']),
            'color' => $ctx['color_int'],
            'fields' => $fields,
            'timestamp' => $ctx['timestamp'],
        ];

        if ($ctx['drive_url']) {
            $embed['url'] = $ctx['drive_url'];
        }

        try {
            Http::asJson()
                ->timeout(5)
                ->post($url, [
                    'username' => $ctx['app'],
                    'embeds' => [$embed],
                ])
                ->throw();
        } catch (Throwable $e) {
            Log::warning('gdbm discord notification failed', ['error' => BackupMessages::redactUrls($e->getMessage())]);
        }
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function sendGoogleChat(array $ctx): void
    {
        $url = Setting::get('notify_google_chat_webhook');

        if (! is_string($url) || $url === '') {
            return;
        }

        $lines = [sprintf('%s *%s*', $ctx['icon'], $ctx['title']), ''];

        foreach ($this->detailRows($ctx) as $row) {
            $lines[] = sprintf('*%s:* %s', $row['label'], $row['value']);
        }

        if ($ctx['drive_url']) {
            $lines[] = '';
            $lines[] = sprintf('<%s|Open on Google Drive>', $ctx['drive_url']);
        }

        try {
            Http::asJson()
                ->timeout(5)
                ->post($url, ['text' => implode("\n", $lines)])
                ->throw();
        } catch (Throwable $e) {
            Log::warning('gdbm google chat notification failed', ['error' => BackupMessages::redactUrls($e->getMessage())]);
        }
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function sendFilamentNotification(array $ctx, ?Backup $row): void
    {
        try {
            $user = $this->resolveTriggeringUser($row);

            if (! $user) {
                return;
            }

            $body = $ctx['is_success']
                ? collect([$ctx['filename'], $ctx['size_human']])->filter()->implode(' · ')
                : (string) $ctx['error'];

            $notification = FilamentNotification::make()
                ->title(sprintf('%s · %s', $ctx['title'], $ctx['app']))
                ->body($body)
                ->icon($ctx['is_success'] ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle');

            $ctx['is_success']
                ? $notification->success()
                : $notification->danger();

            if ($ctx['drive_url']) {
                $notification->actions([
                    FilamentNotificationAction::make('viewOnDrive')
                        ->label('Open on Drive')
                        ->url($ctx['drive_url'], shouldOpenInNewTab: true),
                ]);
            }

            // sendToDatabase: persisted bell entry (always works).
            // broadcast: pushes via the configured broadcaster — no-op today
            // (broadcasting.default = log) but will deliver an instant toast
            // once Echo + Reverb/Pusher are wired up.
            $notification
                ->sendToDatabase($user)
                ->broadcast($user);
        } catch (Throwable $e) {
            Log::warning('gdbm filament notification failed', ['error' => BackupMessages::redactUrls($e->getMessage())]);
        }
    }

    /**
     * The user who clicked Run Backup (captured on the row by Backup::queueRun).
     * Null for scheduled or CLI-triggered runs.
     */
    private function resolveTriggeringUser(?Backup $row): ?Authenticatable
    {
        return $row?->triggeringUser();
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function sendGenericWebhook(array $ctx): void
    {
        $url = Setting::get('notify_generic_webhook');

        if (! is_string($url) || $url === '') {
            return;
        }

        // Strip presentation-only keys before shipping JSON to a generic consumer.
        $payload = collect($ctx)
            ->except(['icon', 'title', 'color_hex', 'color_int'])
            ->all();

        try {
            Http::asJson()
                ->timeout(5)
                ->post($url, $payload)
                ->throw();
        } catch (Throwable $e) {
            Log::warning('gdbm generic webhook failed', ['error' => BackupMessages::redactUrls($e->getMessage())]);
        }
    }
}
