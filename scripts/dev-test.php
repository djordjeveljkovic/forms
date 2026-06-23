<?php

// Helper script to log in and test the dashboard through HTTP.

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

// Make sure the user exists
$user = User::query()->firstOrCreate(
    ['email' => 'test@example.com'],
    ['name' => 'Test User', 'password' => Hash::make('password'), 'email_verified_at' => now()]
);
$user->update(['password' => Hash::make('password')]);
Auth::login($user);
echo 'Logged in as: '.Auth::user()->email."\n";
