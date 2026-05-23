<?php

// config for Webteractive/GoogleDriveBackupManager
return [

    /*
    |--------------------------------------------------------------------------
    | Storage Disk (default)
    |--------------------------------------------------------------------------
    |
    | The filesystem disk used for backups when none is stored in the
    | gdbm_settings table. The settings table value (key `disk`)
    | takes precedence over this fallback.
    |
    */
    'disk' => 'gdbm',

    /*
    |--------------------------------------------------------------------------
    | Authorization Gate
    |--------------------------------------------------------------------------
    */
    'gate' => 'viewBackups',

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    */
    'navigation_group' => 'System',
    'navigation_sort' => 5,

    /*
    |--------------------------------------------------------------------------
    | Download Route
    |--------------------------------------------------------------------------
    |
    | Named route for downloading backup files. The route accepts an
    | encrypted `path` parameter.
    |
    */
    'download_route' => 'backup.download',

    /*
    |--------------------------------------------------------------------------
    | Queue (default)
    |--------------------------------------------------------------------------
    |
    | The queue to dispatch backup jobs to when none is configured in the
    | gdbm_settings table. The settings value (`queue`) wins.
    |
    */
    'queue' => null,

    /*
    |--------------------------------------------------------------------------
    | Route Middleware
    |--------------------------------------------------------------------------
    */
    'middleware' => ['web', 'auth'],

    /*
    |--------------------------------------------------------------------------
    | OAuth Base Path
    |--------------------------------------------------------------------------
    */
    'oauth_base_path' => 'google-drive-backup-manager-oauth',

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | Both the settings and backups tables are owned by this package and
    | prefixed `gdbm_` to avoid collisions with host-app tables. Override
    | here if you need different names.
    |
    */
    'settings_table' => 'gdbm_settings',
    'backups_table' => 'gdbm_backups',

];
