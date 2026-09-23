<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => ['required','string'],
            'password' => ['required','string'],
        ]);

        $user = User::where('username', $data['username'])
            ->where('status', 'active')
            ->first();

        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Invalid credentials'], 401);
        }

        $stored = (string) $user->password;
        $valid = str_starts_with($stored, '$2y$') ||
                 str_starts_with($stored, '$2a$') ||
                 str_starts_with($stored, '$argon2')
            ? Hash::check($data['password'], $stored)
            : hash_equals($stored, $data['password']);

        if (!$valid) {
            return response()->json(['success' => false, 'error' => 'Invalid credentials'], 401);
        }

        $token = $user->createToken('cupad-mobile')->plainTextToken;

        $user->update(['last_login' => now(), 'is_online' => 1, 'last_activity' => now()]);

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(['success' => true, 'user' => $request->user()]);
    }
}
