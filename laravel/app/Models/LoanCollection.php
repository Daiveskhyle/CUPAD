<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanCollection extends Model
{
    protected $table = 'loan_collections';
    protected $primaryKey = 'transaction_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['amount_collected' => 'decimal:2', 'remaining_balance' => 'decimal:2'];
}
