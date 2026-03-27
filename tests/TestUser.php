<?php

namespace Webteractive\GoogleDriveBackupManager\Tests;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Webteractive\GoogleDriveBackupManager\Concerns\HasGoogleToken;

class TestUser extends Authenticatable
{
    use HasGoogleToken;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
    ];
}
