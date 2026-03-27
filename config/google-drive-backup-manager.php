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
    | Google OAuth Credentials
    |--------------------------------------------------------------------------
    |
    | The OAuth credentials used for the Google Drive connection flow.
    | These are kept within this package's config so they don't conflict
    | with other Google integrations in your application.
    |
    */
    'google' => [
        'client_id' => env('GOOGLE_DRIVE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_DRIVE_REDIRECT_URI'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Middleware
    |--------------------------------------------------------------------------
    |
    | The middleware applied to all package routes (OAuth and download).
    | Override this to add custom middleware like Sanctum or Filament auth.
    |
    */
    'middleware' => ['web', 'auth'],

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
    | Cache TTL
    |--------------------------------------------------------------------------
    |
    | How long (in minutes) to cache the backup file listing fetched from
    | Google Drive. The cache is automatically cleared after a successful
    | backup or when a backup file is deleted. Set to 0 to disable caching.
    |
    */
    'cache_ttl' => 60,

];
