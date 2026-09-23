<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClientRegistrationController extends Controller
{
    public function store(Request $request)
    {
        $data=$request->validate([
            'name'=>['required','string','max:255'],
            'phone'=>['required','string','max:50'],
            'email'=>['nullable','email','max:255'],
            'client_type'=>['nullable','in:individual,group'],
            'branch_id'=>['nullable'],
        ]);

        $user=$request->user();
        $branchId=$data['branch_id']??$user->branch_id??null;
        $id='C'.now()->format('ymd').strtoupper(Str::random(5));

        if(!$branchId) return response()->json(['success'=>false,'error'=>'A branch is required'],422);

        $client=DB::transaction(fn()=>Client::create([
            'id'=>$id,
            'name'=>$data['name'],
            'phone'=>$data['phone'],
            'email'=>$data['email']??null,
            'branch_id'=>$branchId,
            'officer_username'=>$user->username,
            'client_type'=>$data['client_type']??'individual',
            'status'=>'active',
            'date_registered'=>now()->toDateString(),
        ]));

        return response()->json(['success'=>true,'client_id'=>$client->id,'message'=>'Client registered'],201);
    }
}
