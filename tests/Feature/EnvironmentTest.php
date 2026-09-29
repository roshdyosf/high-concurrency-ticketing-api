<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

it('uses the test database and redis', function () {
    expect(DB::connection()->getDatabaseName())->toBe('ticketing_test');
    expect(config('database.redis.default.database'))->toBe('10');

    Redis::set('ping_test', 'ok');
    expect(Redis::get('ping_test'))->toBe('ok');
    Redis::del('ping_test');
});
