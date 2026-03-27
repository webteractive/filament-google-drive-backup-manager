<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $column = config('google-drive-backup-manager.google_token_column', 'google_backup');

        Schema::table('users', function (Blueprint $table) use ($column) {
            $table->text($column)->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        $column = config('google-drive-backup-manager.google_token_column', 'google_backup');

        Schema::table('users', function (Blueprint $table) use ($column) {
            $table->dropColumn($column);
        });
    }
};
