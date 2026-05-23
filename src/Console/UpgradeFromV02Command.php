<?php

namespace Webteractive\GoogleDriveBackupManager\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UpgradeFromV02Command extends Command
{
    protected $signature = 'gdbm:upgrade-from-0.2
        {--column= : Legacy users column name to drop (defaults to "google_backup" or the value of google-drive-backup-manager.google_token_column)}';

    protected $description = 'One-time cleanup after upgrading from v0.2.0: drops the legacy users.google_backup column and removes the orphaned migrations row.';

    public function handle(): int
    {
        $column = (string) ($this->option('column')
            ?: config('google-drive-backup-manager.google_token_column', 'google_backup'));

        $this->dropLegacyColumn($column);
        $this->removeOrphanMigrationRow();

        $this->newLine();
        $this->info('Upgrade cleanup complete. Reconnect Google Drive via Settings → Google OAuth if you have not already.');

        return self::SUCCESS;
    }

    protected function dropLegacyColumn(string $column): void
    {
        if (! Schema::hasTable('users')) {
            $this->line('No users table found — skipping column drop.');

            return;
        }

        if (! Schema::hasColumn('users', $column)) {
            $this->line("users.{$column} column already absent — nothing to drop.");

            return;
        }

        Schema::table('users', function (Blueprint $table) use ($column): void {
            $table->dropColumn($column);
        });

        $this->info("Dropped users.{$column} column.");
    }

    protected function removeOrphanMigrationRow(): void
    {
        if (! Schema::hasTable('migrations')) {
            $this->line('No migrations table found — skipping orphan row cleanup.');

            return;
        }

        $deleted = DB::table('migrations')
            ->where('migration', 'add_google_token_column_to_users_table')
            ->delete();

        if ($deleted === 0) {
            $this->line('No orphaned migrations row found — nothing to remove.');

            return;
        }

        $this->info("Removed {$deleted} orphaned migrations row(s).");
    }
}
