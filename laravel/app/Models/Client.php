<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $table = 'clients';
    protected $guarded = [];

    public $incrementing = false;
    protected $keyType = 'string';

    public function savings()
    {
        return $this->hasMany(Saving::class, 'client_id');
    }

    public function disbursements()
    {
        return $this->hasMany(Disbursement::class, 'client_id');
    }

    public function savingCollections()
    {
        return $this->hasMany(SavingCollection::class, 'client_id');
    }

    public function loanCollections()
    {
        return $this->hasMany(LoanCollection::class, 'client_id');
    }
}
