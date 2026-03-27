<?php

use Illuminate\Support\Facades\Route;
use Webteractive\GoogleDriveBackupManager\Http\Controllers\BackupDownloadController;
use Webteractive\GoogleDriveBackupManager\Http\Controllers\GoogleController;

Route::middleware(config('google-drive-backup-manager.middleware', ['web', 'auth']))->group(function () {
    $basePath = config('google-drive-backup-manager.oauth_base_path', 'google-drive-backup-manager-oauth');

    Route::get("{$basePath}/redirect", [GoogleController::class, 'redirect'])
        ->name('google-drive-backup-manager.google.redirect')
        ->middleware('throttle:10,1');

    Route::get("{$basePath}/callback", [GoogleController::class, 'callback'])
        ->name('google-drive-backup-manager.google.callback');

    Route::post("{$basePath}/disconnect", [GoogleController::class, 'disconnect'])
        ->name('google-drive-backup-manager.google.disconnect');

    Route::get('/backup/download/{path}', BackupDownloadController::class)
        ->name(config('google-drive-backup-manager.download_route', 'backup.download'));
});
