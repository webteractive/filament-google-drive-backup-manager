<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $column = config('google-drive-backup-manager.google_token_column', 'google_backup');

        if (Schema::hasColumn('users', $column)) {
            Schema::table('users', function (Blueprint $table) use ($column): void {
                $table->dropColumn($column);
            });
        }
    }

    public function down(): void
    {
        // No-op: token storage has moved to gdbm_settings. Restoring the
        // legacy column would not restore any data, so down() intentionally
        // does nothing rather than recreating a useless empty column.
    }
};
