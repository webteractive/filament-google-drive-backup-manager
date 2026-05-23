<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            if (Schema::hasColumn($this->table(), 'type')) {
                $table->dropColumn('type');
            }
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            if (! Schema::hasColumn($this->table(), 'type')) {
                $table->string('type')->default('full')->after('filename');
            }
        });
    }

    private function table(): string
    {
        return config('google-drive-backup-manager.backups_table', 'gdbm_backups');
    }
};
