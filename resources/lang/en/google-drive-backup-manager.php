<?php

return [
    'resource' => [
        'navigation_label' => 'Google Drive Backups',
        'model_label' => 'Google Drive Backup',
        'plural_model_label' => 'Google Drive Backups',
    ],

    'columns' => [
        'file' => 'File',
        'status' => 'Status',
        'size' => 'Size',
        'started' => 'Started',
        'completed' => 'Completed',
        'error' => 'Error',
        'disk' => 'Disk',
    ],

    'empty_state' => [
        'no_credentials' => [
            'heading' => 'Google credentials not configured',
            'description' => 'Open Settings → Google OAuth and add your Client ID and Client Secret to get started.',
        ],
        'unreadable_oauth' => [
            'heading' => 'Saved credentials are unreadable',
            'description' => 'The stored OAuth payload can no longer be decrypted (the APP_KEY likely changed). Re-authenticate in Settings → Google OAuth.',
        ],
        'not_connected' => [
            'heading' => 'Not connected to Google',
            'description' => 'Open Settings → Google OAuth and click Save and Authenticate to connect your account.',
        ],
        'no_backups' => [
            'heading' => 'No backups yet',
            'description' => 'Click Run Backup to create your first one.',
        ],
    ],

    'actions' => [
        'details' => 'Details',
        'view' => 'View',
        'download' => 'Download',
        'retry' => 'Retry',
        'delete' => 'Delete',
        'settings' => 'Settings',
        'run_backup' => 'Run Backup',
        'run' => 'Run',
        'save' => 'Save',
        'save_and_authenticate' => 'Save and Authenticate',
        'disconnect' => 'Disconnect Google',
        'copy_redirect_uri' => 'Copy',
        'open_on_drive' => 'Open on Drive',
    ],

    'tabs' => [
        'oauth' => 'Google OAuth',
        'backup' => 'Backup',
        'databases' => 'Databases',
        'files' => 'Files',
        'schedule' => 'Schedule',
        'cleanup' => 'Cleanup',
        'notifications' => 'Notifications',
    ],

    'notifications' => [
        'backup_queued_title' => 'Backup queued',
        'backup_queued_body' => 'Your backup has been queued and will run shortly.',
        'backup_not_configured_title' => 'Backup is not configured',
        'backup_not_configured_body' => 'Add at least one database target in Settings → Backup before running a backup.',
        'settings_saved' => 'Settings saved',
        'google_connected_title' => 'Google account connected',
        'google_connected_body' => 'Now finish setup in the Settings modal: pick the databases to back up, add file paths (optional), and configure a schedule.',
        'google_disconnected' => 'Google account disconnected.',
        'google_auth_failed' => 'Google authentication failed. Please try again.',
        'fill_in_creds' => 'Fill in Client ID and Client Secret first.',
        'unreadable_oauth_title' => 'Saved Google credentials are unreadable',
        'unreadable_oauth_body' => 'The stored OAuth payload can no longer be decrypted — usually because APP_KEY changed. Open Settings → Google OAuth and re-authenticate.',
        'folder_resolve_failed' => 'Folder could not be resolved on Google Drive',
        'select_at_least_one_database' => 'Select at least one database',
        'backup_deleted' => 'Backup deleted',
        'bulk_delete_modal_heading' => 'Delete selected backups',
        'bulk_delete_modal_description' => 'This permanently deletes the rows and the corresponding files on Google Drive. In-progress backups are skipped.',
    ],

    'widget' => [
        'heading' => 'Google Drive Backups',
        'last_backup' => 'Last backup',
        'no_runs_yet' => 'No runs yet',
        'next_scheduled' => 'Next scheduled',
        'disabled' => 'Disabled',
        'invalid_cron' => 'Invalid cron',
        'stored' => 'Stored',
    ],
];
