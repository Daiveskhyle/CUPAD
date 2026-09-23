<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanCollection extends Model
{
    protected $table = 'loan_collections';
    protected $guarded = [];
    protected $casts = ['amount_collected' => 'decimal:2', 'remaining_balance' => 'decimal:2'];
}
