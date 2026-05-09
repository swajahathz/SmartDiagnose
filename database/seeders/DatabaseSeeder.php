<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Set in .env (then run php artisan db:seed):
     * SMARTDIAGNOSE_ADMIN_EMAIL=you@example.com
     * SMARTDIAGNOSE_ADMIN_PASSWORD=your-secret
     * SMARTDIAGNOSE_ADMIN_NAME="Your Name"   (optional)
     */
    public function run(): void
    {
        $email = env('SMARTDIAGNOSE_ADMIN_EMAIL');
        $password = env('SMARTDIAGNOSE_ADMIN_PASSWORD');

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            if ($this->command) {
                $this->command->warn('Skipping user: add SMARTDIAGNOSE_ADMIN_EMAIL and SMARTDIAGNOSE_ADMIN_PASSWORD to .env');
            }

            return;
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => env('SMARTDIAGNOSE_ADMIN_NAME', 'Administrator'),
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );

        if ($this->command) {
            $this->command->info('Admin user ready: '.$email);
        }
    }
}
