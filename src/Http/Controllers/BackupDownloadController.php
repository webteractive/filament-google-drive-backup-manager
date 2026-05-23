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

        // Default-deny when the host hasn't registered the gate — backups
        // contain DB dumps + included files and must not be exposed to
        // every authenticated user by accident.
        if (! Gate::has($gate) || ! Gate::allows($gate)) {
            abort(403);
        }

        try {
            $payload = decrypt($path);
        } catch (Throwable) {
            abort(404, 'File not found');
        }

        // Token is the {path, user_id, expires_at} bundle minted by the
        // resource's download action — bound to the requesting user and a
        // short TTL so leaked URLs can't be reused indefinitely.
        if (! is_array($payload)
            || ! is_string($payload['path'] ?? null)
            || ($payload['user_id'] ?? null) !== $request->user()?->getAuthIdentifier()
            || (int) ($payload['expires_at'] ?? 0) < now()->timestamp) {
            abort(403);
        }

        $decryptedPath = $payload['path'];

        $disk = Storage::disk(config('google-drive-backup-manager.disk', 'gdbm'));

        if (! $disk->exists($decryptedPath)) {
            abort(404, 'File not found');
        }

        return $disk->download($decryptedPath, basename($decryptedPath));
    }
}
