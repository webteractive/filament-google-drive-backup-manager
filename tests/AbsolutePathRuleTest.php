<?php

use Illuminate\Support\Facades\Validator;
use Webteractive\GoogleDriveBackupManager\Rules\AbsolutePath;

it('passes for an existing absolute file', function () {
    expect(Validator::make(['p' => __FILE__], ['p' => [new AbsolutePath]])->errors()->all())->toBe([]);
});

it('passes for an existing absolute directory', function () {
    expect(Validator::make(['p' => __DIR__], ['p' => [new AbsolutePath]])->errors()->all())->toBe([]);
});

it('rejects a relative path', function () {
    $errors = Validator::make(['p' => 'relative/path'], ['p' => [new AbsolutePath]])->errors()->first('p');

    expect($errors)->toContain('absolute');
});

it('rejects path traversal segments', function () {
    $errors = Validator::make(['p' => '/etc/../etc/passwd'], ['p' => [new AbsolutePath]])->errors()->first('p');

    expect($errors)->toContain('..');
});

it('rejects non-existent paths when mustExist defaults true', function () {
    $errors = Validator::make(
        ['p' => '/this/path/does/not/exist/'.uniqid()],
        ['p' => [new AbsolutePath]],
    )->errors()->first('p');

    expect($errors)->toContain('does not exist');
});

it('allows non-existent paths when mustExist is disabled', function () {
    $errors = Validator::make(
        ['p' => '/this/path/does/not/exist/'.uniqid()],
        ['p' => [new AbsolutePath(mustExist: false)]],
    )->errors()->all();

    expect($errors)->toBe([]);
});

it('passes for empty / null values (delegates to required)', function () {
    expect(Validator::make(['p' => null], ['p' => [new AbsolutePath]])->errors()->all())->toBe([])
        ->and(Validator::make(['p' => ''], ['p' => [new AbsolutePath]])->errors()->all())->toBe([]);
});
