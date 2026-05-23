<?php

use Webteractive\GoogleDriveBackupManager\Support\BackupMessages;

it('redacts credential-shaped substrings from exception messages', function () {
    // Each sample's secret value MUST NOT survive into the redacted output.
    $samples = [
        ['input' => 'Access denied (using password=ssh-very-secret-pass) end', 'secret' => 'ssh-very-secret-pass'],
        ['input' => 'auth failed token=abc.def.ghi extra',                       'secret' => 'abc.def.ghi'],
        ['input' => 'connect string apikey=ABCDEF1234567890',                    'secret' => 'ABCDEF1234567890'],
        ['input' => 'request had Authorization: Bearer my-secret-bearer-value',  'secret' => 'my-secret-bearer-value'],
    ];

    foreach ($samples as $sample) {
        expect(BackupMessages::redact($sample['input']))
            ->not->toContain($sample['secret'])
            ->and(BackupMessages::redact($sample['input']))->toContain('***');
    }
});

it('limits message length to 500 chars', function () {
    expect(mb_strlen((string) BackupMessages::redact(str_repeat('a', 1000))))
        ->toBeLessThanOrEqual(503); // 500 + ellipsis
});

it('leaves benign messages alone', function () {
    expect(BackupMessages::redact('Backup failed: disk full'))->toBe('Backup failed: disk full');
});

it('redacts Bearer / Basic tokens that follow space-separated', function () {
    $out = BackupMessages::redact('Auth: Bearer eyJhbGciOiJSUzI1NiJ9.abc.def');

    expect($out)->toContain('Bearer ***')
        ->and($out)->not->toContain('eyJhbGciOiJSUzI1NiJ9');
});

it('redacts space-separated credential keywords (CLI-style)', function () {
    $out = BackupMessages::redact('Process failed: --password sup3rs3cret extra context');

    expect($out)->toContain('--password ***')
        ->and($out)->not->toContain('sup3rs3cret');
});

it('redacts space-separated api_key shape', function () {
    $out = BackupMessages::redact('Bad api_key ABCDEF1234567890 was rejected');

    expect($out)->toContain('api_key ***')
        ->and($out)->not->toContain('ABCDEF1234567890');
});

it('redactUrls strips full webhook URLs', function () {
    $input = 'POST https://hooks.slack.com/services/T000/B000/secret-token failed: connection refused';

    expect(BackupMessages::redactUrls($input))
        ->toContain('<redacted-url>')
        ->and(BackupMessages::redactUrls($input))->not->toContain('hooks.slack.com');
});
