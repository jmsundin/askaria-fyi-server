<?php

namespace Database\Seeders;

use App\Models\Call;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (User::count() === 0) {
            User::factory()
                ->count(3)
                ->hasAgentProfile()
                ->create();
        }

        if (Call::count() === 0) {
            User::all()->each(function (User $user): void {
                Call::factory()
                    ->count(4)
                    ->for($user)
                    ->create();
            });
        }
    }
}
