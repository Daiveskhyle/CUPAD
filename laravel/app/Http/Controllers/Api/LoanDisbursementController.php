<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Disbursement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoanDisbursementController extends Controller
{
    public function store(Request $request)
    {
        $data=$request->validate([
            'client_id'=>['required','string'],
            'principal'=>['required','numeric','gt:0'],
            'interest_rate'=>['nullable','numeric','min:0'],
            'num_installments'=>['required','integer','min:1'],
            'loan_term_type'=>['nullable','in:daily,weekly,monthly'],
            'date'=>['nullable','date'],
            'due_date'=>['nullable','date'],
            'notes'=>['nullable','string','max:2000'],
        ]);

        $client=Client::query()->whereKey($data['client_id'])->where('status','active')->firstOrFail();
        $this->authorizeClient($request,$client);

        $principal=(float)$data['principal'];
        $interest=(float)($data['interest_rate']??0);
        $total=round($principal*(1+$interest/100),2);
        $date=$data['date']??now()->toDateString();

        $loan=DB::transaction(function() use($data,$request,$client,$principal,$interest,$total,$date){
            $id='DSB-'.strtoupper((string)Str::uuid());
            return Disbursement::create([
                'id'=>$id,
                'client_id'=>$client->id,
                'client_name'=>$client->name,
                'officer'=>$request->user()->username,
                'branch_id'=>$client->branch_id,
                'principal'=>$principal,
                'interest_rate'=>$interest,
                'total_payable'=>$total,
                'remaining_balance'=>$total,
                'num_installments'=>(int)$data['num_installments'],
                'loan_term_type'=>$data['loan_term_type']??'weekly',
                'client_type'=>$client->client_type??null,
                'date'=>$date,
                'due_date'=>$data['due_date']??null,
                'status'=>'active',
                'notes'=>$data['notes']??null,
            ]);
        });

        return response()->json([
            'success'=>true,
            'disbursement_id'=>$loan->id,
            'total_payable'=>(float)$loan->total_payable,
            'remaining_balance'=>(float)$loan->remaining_balance,
            'message'=>'Loan disbursed',
        ],201);
    }

    private function authorizeClient(Request $request,Client $client):void
    {
        $u=$request->user(); $role=strtolower((string)$u->role);
        $allowed=match($role){
            'admin'=>true,
            'co'=>$client->officer_username===$u->username,
            'bm'=>(string)$client->branch_id===(string)$u->branch_id,
            'am'=>DB::table('branches')->where('id',$client->branch_id)->where('area_id',$u->area_id)->exists(),
            'zm','dzm','tm'=>DB::table('branches')->where('id',$client->branch_id)->where('zone_id',$u->zone_id)->exists(),
            default=>false,
        };
        if(!$allowed) abort(403,'Unauthorized');
    }
}
