<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Saving extends Model
{
    protected $table = 'savings';
    protected $guarded = [];
    protected $casts = ['balance' => 'decimal:2'];
}
