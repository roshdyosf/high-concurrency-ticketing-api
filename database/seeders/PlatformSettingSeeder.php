<?php

namespace Database\Seeders;

use App\Models\PlatformSetting;
use Illuminate\Database\Seeder;

class PlatformSettingSeeder extends Seeder
{
    public function run(): void
    {
        PlatformSetting::firstOrCreate(
            ['key' => 'commission_percentage'],
            ['value' => '10'],
        );
    }
}
