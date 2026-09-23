<?php

namespace App\Http\Controllers\Co;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class AnalyticsController extends Controller
{
    public function index()
    {
        abort_unless(strtolower((string) auth()->user()->role) === 'co', 403);
        return view('co.analytics');
    }

    public function data(Request $request)
    {
        abort_unless(strtolower((string) auth()->user()->role) === 'co', 403);
        $u = $request->user();
        [$from, $to] = $this->range($request);

        $clients = Client::where('officer_username', $u->username)->get(['id','name','union','status']);
        $ids = $clients->pluck('id');

        $savings = DB::table('saving_collections')->whereIn('client_id',$ids)->get(['client_id','amount','date','type']);
        $loans = DB::table('disbursements')->whereIn('client_id',$ids)->get(['id','client_id','principal as principal_amount','total_payable','remaining_balance','date','due_date','payoff_date']);
        $collections = DB::table('loan_collections')->whereIn('client_id',$ids)->get(['client_id','amount_collected','date']);
        $registrations = DB::table('registrations')->where(function($q) use ($ids,$u){$q->whereIn('client_id',$ids)->orWhere('officer',$u->username);})->get(['client_id','union','amount','date']);

        $fs = $this->between($savings,$from,$to);
        $fl = $this->between($loans,$from,$to);
        $fc = $this->between($collections,$from,$to);
        $fr = $this->between($registrations,$from,$to);

        $dep=$wit=$cash=0;
        foreach($fs as $r){$a=(float)$r->amount;$t=strtolower((string)($r->type??'')); if(in_array($t,['cash','return_cash','return cash'],true)) $cash+=abs($a); elseif($a<0||in_array($t,['withdrawal','return','adjust','debit','charge','fee'],true)) $wit+=abs($a); else $dep+=$a;}
        $disb=$fl->sum(fn($r)=>(float)$r->principal_amount);
        $coll=$fc->sum(fn($r)=>(float)$r->amount_collected);
        $charges=$fl->sum(fn($r)=>max(0,(float)$r->total_payable-(float)$r->principal_amount));

        $active=0;$outstanding=0;$overdue=0;$expected=0;
        foreach($loans as $l){$rem=(float)$l->remaining_balance;$tp=(float)$l->total_payable;if($rem>0.01){$active++;$outstanding+=$rem;$due=$l->due_date?Carbon::parse($l->due_date):$this->workingDue($l->date);if(now()->startOfDay()->gt($due))$overdue+=$rem;}else{$expected+=$tp;}}
        $unions=$clients->pluck('union')->filter()->unique()->sort()->values();
        $unionTable=[];
        foreach($unions as $union){
            $uc=$clients->where('union',$union);$uids=$uc->pluck('id');
            $us=$fs->whereIn('client_id',$uids);$ul=$fl->whereIn('client_id',$uids);$ucol=$fc->whereIn('client_id',$uids);$ur=$fr->where('union',$union);
            $d=$w=$ca=0;foreach($us as $r){$a=(float)$r->amount;$t=strtolower((string)($r->type??''));if(in_array($t,['cash','return_cash','return cash'],true))$ca+=abs($a);elseif($a<0||in_array($t,['withdrawal','return','adjust','debit','charge','fee'],true))$w+=abs($a);else$d+=$a;}
            $paid=0;$ov=0;foreach($uc as $c){foreach($loans->where('client_id',$c->id) as $l){$rem=(float)$l->remaining_balance;if($rem<=.01&&$l->payoff_date&&substr($l->payoff_date,0,10)>=$from&&substr($l->payoff_date,0,10)<=$to)$paid++;if($rem>.01){$due=$l->due_date?Carbon::parse($l->due_date):$this->workingDue($l->date);if(now()->startOfDay()->gt($due)){$ov++;break;}}}}
            $unionTable[]=['name'=>$union,'clients'=>$uc->count(),'total_savings'=>$d,'withdrawals'=>$w,'cash_payouts'=>$ca,'net_savings'=>$d-$w-$ca,'disbursements'=>$ul->sum(fn($r)=>(float)$r->principal_amount),'collections'=>$ucol->sum(fn($r)=>(float)$r->amount_collected),'registrations'=>$ur->count(),'paid_off'=>$paid,'overdue_count'=>$ov];
        }
        $daily=[]; foreach(CarbonPeriod::create($from,$to) as $day){$d=$day->toDateString();$daily[]=['date'=>$day->format('M d'),'savings'=>$fs->filter(fn($r)=>substr((string)$r->date,0,10)===$d && (float)$r->amount>0)->sum('amount'),'collections'=>$fc->filter(fn($r)=>substr((string)$r->date,0,10)===$d)->sum('amount_collected')];}

        return response()->json(['metrics'=>['total_clients'=>$clients->count(),'total_savings'=>$dep,'total_withdrawals'=>$wit,'total_cash'=>$cash,'net_savings'=>$dep-$wit-$cash,'total_disbursements'=>$disb,'total_collections'=>$coll,'collection_rate'=>$expected>0?round($coll/$expected*100,1):0,'par_ratio'=>$outstanding>0?round($overdue/$outstanding*100,1):0,'active_loans'=>$active,'total_outstanding'=>$outstanding,'total_service_charges'=>$charges,'total_registrations'=>$fr->count()],'chart_daily'=>$daily,'chart_comp'=>['Savings'=>$dep,'Disbursements'=>$disb,'Collections'=>$coll],'union_table'=>$unionTable,'dates'=>['from'=>$from,'to'=>$to]]);
    }

    public function unionDetails(Request $request)
    {
        $data=$this->data($request)->getData(true);
        $union=(string)$request->input('union_name','');
        $q=strtolower(trim((string)$request->input('q','')));
        $u=$request->user();$clients=Client::where('officer_username',$u->username)->where('union',$union)->get(['id','name','status']);
        $out=[];
        foreach($clients as $c){if($q && !str_contains(strtolower($c->name.' '.$c->id),$q))continue;$loan=DB::table('disbursements')->where('client_id',$c->id)->where('remaining_balance','>',0)->latest('id')->first();$sav=DB::table('savings')->where('client_id',$c->id)->where('status','active')->value('balance');$out[]=['name'=>$c->name,'id'=>$c->id,'status'=>$c->status,'savings_balance'=>(float)($sav??0),'loan_outstanding'=>(float)($loan->remaining_balance??0)];}
        return response()->json(['clients'=>$out]);
    }

    private function range(Request $r): array { $p=$r->input('preset','month'); $today=now()->toDateString(); if($p==='today')return[$today,$today];if($p==='week')return[now()->startOfWeek()->toDateString(),$today];if($p==='last_month'){return[now()->subMonth()->startOfMonth()->toDateString(),now()->subMonth()->endOfMonth()->toDateString()];}return[$r->input('date_from',now()->startOfMonth()->toDateString()),$r->input('date_to',$today)];}
    private function between($c,$f,$t){return $c->filter(fn($r)=>($d=substr((string)$r->date,0,10))>=$f&&$d<=$t)->values();}
    private function workingDue($date){$d=Carbon::parse($date);$n=0;while($n<26){$d->addDay();if($d->isWeekday()&&!in_array($d->format('m-d'),['01-01','05-01','06-12','10-01','12-25','12-26'],true))$n++;}return$d;}
}
