<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavingCollection extends Model
{
    protected $table = 'saving_collections';
    protected $primaryKey = 'transaction_id';
    public $incrementing = false;
    public $timestamps = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:2', 'balance_after' => 'decimal:2'];
}
