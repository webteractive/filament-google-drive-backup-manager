<?php

namespace Webteractive\GoogleDriveBackupManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class BackupDownloadController extends Controller
{
    public function __invoke(Request $request, string $path): StreamedResponse
    {
        $gate = config('google-drive-backup-manager.gate', 'viewBackups');

        if (Gate::has($gate)) {
            abort_unless(Gate::allows($gate), 403);
        }

        try {
            $decryptedPath = decrypt($path);
        } catch (Throwable) {
            abort(404, 'File not found');
        }

        $disk = Storage::disk(config('google-drive-backup-manager.disk', 'google'));

        if (! $disk->exists($decryptedPath)) {
            abort(404, 'File not found');
        }

        return $disk->download($decryptedPath, basename($decryptedPath));
    }
}
