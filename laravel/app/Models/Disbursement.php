<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Disbursement extends Model
{
    protected $table = 'disbursements';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = [
        'principal' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'total_payable' => 'decimal:2',
        'remaining_balance' => 'decimal:2',
        'date' => 'date',
        'due_date' => 'date',
        'payoff_date' => 'date',
    ];
}
