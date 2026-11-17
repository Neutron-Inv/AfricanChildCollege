<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class PrimaryNewStudentsSeeder extends Seeder
{
    /**
     * Adds the green rows from the 2026/2027 nursery and primary register.
     * Run on the live server with: php artisan db:seed --class=PrimaryNewStudentsSeeder
     */
    public function run(): void
    {
        $exit = Artisan::call('students:import-primary-new', ['school' => 'primary']);
        $this->command?->line(Artisan::output());

        if ($exit !== 0) {
            throw new \RuntimeException('Primary new-student import failed.');
        }
    }
}
