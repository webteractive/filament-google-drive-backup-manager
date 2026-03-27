<?php

// config for Webteractive/GoogleDriveBackupManager
return [

    /*
    |--------------------------------------------------------------------------
    | Storage Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk to use for backups. This should match a disk defined
    | in your config/filesystems.php that uses the 'google' driver.
    |
    */
    'disk' => 'google',

    /*
    |--------------------------------------------------------------------------
    | Authorization Gate
    |--------------------------------------------------------------------------
    |
    | The gate name used to authorize access to the backup resource.
    | Define this gate in your AppServiceProvider.
    |
    */
    'gate' => 'viewBackups',

    /*
    |--------------------------------------------------------------------------
    | Navigation Group
    |--------------------------------------------------------------------------
    |
    | The navigation group label in the Filament sidebar.
    |
    */
    'navigation_group' => 'System',

    /*
    |--------------------------------------------------------------------------
    | Navigation Sort
    |--------------------------------------------------------------------------
    |
    | The sort order of the backup resource in the navigation.
    |
    */
    'navigation_sort' => 5,

    /*
    |--------------------------------------------------------------------------
    | Download Route
    |--------------------------------------------------------------------------
    |
    | The named route used for downloading backup files. The route should
    | accept an encrypted 'path' parameter.
    |
    */
    'download_route' => 'backup.download',

    /*
    |--------------------------------------------------------------------------
    | Google Token Column
    |--------------------------------------------------------------------------
    |
    | The column name on the users table used to store Google OAuth tokens.
    | Change this if your users table already has a 'google_backup' column.
    |
    */
    'google_token_column' => 'google_backup',

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | The queue name to dispatch backup jobs to. Set to null to use the default
    | queue connection.
    |
    */
    'queue' => null,

    /*
    |--------------------------------------------------------------------------
    | OAuth Base Path
    |--------------------------------------------------------------------------
    |
    | The base path for Google OAuth routes. The following routes will be
    | registered under this path:
    |   - GET  {base}/redirect
    |   - GET  {base}/callback
    |   - POST {base}/disconnect
    |
    */
    'oauth_base_path' => 'google-drive-backup-manager-oauth',

    /*
    |--------------------------------------------------------------------------
    | Refresh Token Resolver
    |--------------------------------------------------------------------------
    |
    | A callable that resolves the Google Drive refresh token. This allows you
    | to pull the token from your own storage (e.g. a user's database column)
    | instead of relying on the environment variable.
    |
    | Example:
    |   'refresh_token_resolver' => function () {
    |       $user = \App\Models\User::where('email', config('app.admin_email'))->first();
    |       return $user?->google['refresh_token'] ?? null;
    |   },
    |
    */
    'refresh_token_resolver' => null,

];
