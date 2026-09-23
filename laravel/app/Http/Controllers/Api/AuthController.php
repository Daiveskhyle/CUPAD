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

    public function profile(Request $request)
    {
        $data = $request->validate([
            'full_name' => ['nullable', 'string', 'min:2', 'max:255'],
            'name' => ['nullable', 'string', 'min:2', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'current_password' => ['nullable', 'string'],
            'new_password' => ['nullable', 'string', 'min:6'],
        ]);

        $user = $request->user();
        $name = trim((string) ($data['full_name'] ?? $data['name'] ?? ''));

        if (!empty($data['new_password'])) {
            if (empty($data['current_password'])) {
                return response()->json(['success' => false, 'error' => 'Current password is required to change your password'], 422);
            }

            $stored = (string) $user->password;
            $valid = str_starts_with($stored, '$2y    {
        return response()->json(['success' => true, 'user' => $request->user()]);
    }
}
) || str_starts_with($stored, '$2a    {
        return response()->json(['success' => true, 'user' => $request->user()]);
    }
}
) || str_starts_with($stored, '$argon2')
                ? Hash::check($data['current_password'], $stored)
                : hash_equals($stored, $data['current_password']);

            if (!$valid) {
                return response()->json(['success' => false, 'error' => 'Current password is incorrect'], 422);
            }

            $user->password = Hash::make($data['new_password']);
        }

        if ($name !== '') {
            $user->name = $name;
            $user->full_name = $name;
        }

        if (array_key_exists('email', $data)) {
            $user->email = $data['email'];
        }

        $user->save();

        return response()->json([
            'success' => true,
            'data' => $user->fresh(),
            'message' => 'Profile updated successfully',
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(['success' => true, 'user' => $request->user()]);
    }
}
