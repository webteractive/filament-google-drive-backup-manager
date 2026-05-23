# Local Fallback Backups and Manual Catch-Up Upload

## Summary

Add a package-owned fallback path so backup jobs still create local backups when Google Drive cannot be used because the stored token is unreadable, missing, expired, or the Google disk/API is unavailable. Add a manual catch-up mechanism through both Artisan and Filament to upload those local fallback backups to Google once the connection is healthy again. Uploaded local files stay local by default.

## Key Changes

- Add config keys to `google-drive-backup-manager.php`:
  - `fallback.enabled => true`
  - `fallback.disk => 'local'`
  - `fallback.delete_after_upload => false`
  - `fallback.overwrite_existing => false`
- Update `RunBackup` so it preflights the configured Google disk before running Spatie backup.
  - If Google is healthy, preserve current behavior and run `backup:run` normally.
  - If Google is unavailable and fallback is enabled, temporarily override `backup.backup.destination.disks` to only the fallback disk, then run `backup:run`.
  - If fallback is disabled, preserve the current failure behavior.
- Decouple Filament backup actions from Google token readability.
  - Connected/healthy Google: backup actions queue normal Google backup.
  - Missing/unreadable/unhealthy Google with fallback enabled: backup actions remain available and queue local fallback backup.
  - Missing/unreadable/unhealthy Google with fallback disabled: only show reconnect/configuration actions.
- Add catch-up upload support:
  - Artisan command: `google-drive-backup-manager:sync-local-backups`
  - Queue job: uploads fallback `.zip` backups from the Spatie backup folder path, using `config('backup.backup.name')` as the directory prefix.
  - Filament header action: "Upload Local Backups to Google", visible when fallback is enabled.
  - Upload uses streams from fallback disk to Google disk, skips existing remote files by default, clears the backup listing cache after successful uploads, and keeps local files unless `delete_after_upload` is true.
- Keep the Google disk driver focused on Google access only. Fallback and catch-up behavior belongs in backup orchestration, not inside `Storage::extend('google')`.

## Test Plan

- `RunBackup`:
  - runs normal `backup:run` when Google disk preflight succeeds.
  - runs fallback backup with only the fallback disk when Google token data is unreadable.
  - throws/fails as before when Google is unavailable and fallback is disabled.
  - preserves `onlyDb` behavior for both normal and fallback backups.
- Catch-up:
  - uploads local fallback zip files to the Google disk using the same relative paths.
  - skips remote files that already exist when overwrite is false.
  - keeps local files after upload by default.
  - deletes local files only when `fallback.delete_after_upload` is true.
  - clears `Backup::clearCache()` after any successful upload.
- Filament:
  - backup actions remain available when Google is unreadable but fallback is enabled.
  - catch-up action is available only when fallback is enabled.
  - reconnect action still appears when token data is unreadable.
- Run verification:
  - `vendor/bin/pest`
  - `vendor/bin/phpstan analyse`
  - `vendor/bin/pint --dirty`

## Assumptions

- Fallback strategy is package-owned runtime override, not a documentation-only Spatie setup.
- Local fallback files are retained after upload.
- Catch-up is manual through Filament and Artisan, not automatic on reconnect.
- The fallback disk must exist in `filesystems.disks`; the package will not create a disk definition.
- Catch-up scans only Spatie backup zip files under the configured backup name directory, not every file on the local disk.
