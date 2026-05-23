<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->string('drive_file_id')->nullable()->after('filename');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropColumn('drive_file_id');
        });
    }

    private function table(): string
    {
        return config('google-drive-backup-manager.backups_table', 'gdbm_backups');
    }
};
