<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'is_online' => 'boolean',
            'last_login' => 'datetime',
            'last_activity' => 'datetime',
            'lockout_until' => 'datetime',
        ];
    }

    public function getAuthPassword()
    {
        return $this->password;
    }
}
