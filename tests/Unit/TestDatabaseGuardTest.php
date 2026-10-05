<?php

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

it('runs against the test database only', function () {
    expect(DB::connection()->getDatabaseName())->toBe('ticketing_test');
});
