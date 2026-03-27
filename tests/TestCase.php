<?php

namespace Webteractive\GoogleDriveBackupManager\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\SocialiteServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Webteractive\GoogleDriveBackupManager\GoogleDriveBackupManagerServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();

        Route::get('/login', fn () => 'login')->name('login');
    }

    protected function getPackageProviders($app)
    {
        return [
            GoogleDriveBackupManagerServiceProvider::class,
            SocialiteServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        config()->set('auth.providers.users.model', TestUser::class);
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function setUpDatabase(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('');
            $table->string('email')->unique();
            $table->string('password')->default('');
            $table->rememberToken();
            $table->text('google_backup')->nullable();
            $table->timestamps();
        });
    }
}
