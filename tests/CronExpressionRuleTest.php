<?php

use Illuminate\Support\Facades\Validator;
use Webteractive\GoogleDriveBackupManager\Rules\CronExpressionRule;

it('passes for valid cron expressions', function () {
    foreach (['0 2 * * *', '*/5 * * * *', '0 0 1 * *', '0 2 * * 0'] as $cron) {
        expect(Validator::make(['c' => $cron], ['c' => [new CronExpressionRule]])->errors()->all())->toBe([]);
    }
});

it('rejects invalid cron expressions', function () {
    foreach (['not-a-cron', '* * *', '99 99 99 99 99'] as $bogus) {
        $errors = Validator::make(['c' => $bogus], ['c' => [new CronExpressionRule]])->errors()->first('c');
        expect($errors)->toContain('not a valid cron expression');
    }
});

it('passes for empty values (delegates to required)', function () {
    expect(Validator::make(['c' => null], ['c' => [new CronExpressionRule]])->errors()->all())->toBe([])
        ->and(Validator::make(['c' => ''], ['c' => [new CronExpressionRule]])->errors()->all())->toBe([]);
});

it('rejects non-string types', function () {
    $errors = Validator::make(['c' => ['not', 'a', 'string']], ['c' => [new CronExpressionRule]])
        ->errors()->first('c');

    expect($errors)->toContain('cron expression string');
});
