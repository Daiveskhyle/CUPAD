<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => ['required','string','max:100'],
            'password' => ['required','string'],
        ]);

        $user = User::where('username', $data['username'])
            ->where('status', 'active')
            ->first();

        if (!$user) {
            Log::warning('CUPAD login failed: unknown user', ['username' => $data['username']]);
            return back()->withInput()->with('error', 'Invalid credentials.');
        }

        if ($user->lockout_until && now()->lt($user->lockout_until)) {
            $minutes = max(1, now()->diffInMinutes($user->lockout_until));
            return back()->withInput()->with('error', "Account locked. Try again in {$minutes} minutes.");
        }

        $stored = (string) $user->password;
        $valid = str_starts_with($stored, '$2y$') ||
                 str_starts_with($stored, '$2a$') ||
                 str_starts_with($stored, '$argon2')
            ? Hash::check($data['password'], $stored)
            : hash_equals($stored, $data['password']);

        if (!$valid) {
            $attempts = is_array($user->failed_login_attempts)
                ? $user->failed_login_attempts
                : (json_decode((string) $user->failed_login_attempts, true) ?: []);

            $attempts[] = now()->timestamp;
            $attempts = array_values(array_filter($attempts, fn ($ts) => now()->timestamp - (int) $ts < 900));

            $update = ['failed_login_attempts' => json_encode($attempts)];

            if (count($attempts) >= 5) {
                $update['lockout_until'] = now()->addMinutes(10);
            }

            $user->update($update);

            return back()->withInput()->with(
                'error',
                count($attempts) >= 5 ? 'Account locked. Try again in 10 minutes.' : 'Invalid credentials.'
            );
        }

        $user->update([
            'failed_login_attempts' => json_encode([]),
            'lockout_until' => null,
            'last_login' => now(),
            'is_online' => 1,
            'last_activity' => now(),
        ]);

        $request->session()->regenerate();
        auth()->login($user, $request->boolean('remember_me'));

        return redirect()->route('dashboard');
    }

    public function logout(Request $request)
    {
        if (auth()->check()) {
            auth()->user()->update([
                'is_online' => 0,
                'last_logout' => now(),
            ]);
        }

        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
