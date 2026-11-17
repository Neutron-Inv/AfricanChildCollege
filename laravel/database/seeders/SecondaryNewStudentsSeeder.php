<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class SecondaryNewStudentsSeeder extends Seeder
{
    /**
     * Adds the 2026/2027 junior and senior register.
     * Run on the live server with: php artisan db:seed --class=SecondaryNewStudentsSeeder
     */
    public function run(): void
    {
        $exit = Artisan::call('students:import-secondary-new', [
            'school' => 'secondary',
            'session' => '2026/2027',
        ]);
        $this->command?->line(Artisan::output());

        if ($exit !== 0) {
            throw new \RuntimeException('Secondary new-student import failed.');
        }
    }
}
