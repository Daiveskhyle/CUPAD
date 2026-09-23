<?php

namespace App\Http\Controllers\Bm;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientsController extends Controller
{
    private function branch(Request $request): int
    {
        abort_unless(strtolower((string)$request->user()->role)==='bm',403);
        $branch=(int)$request->user()->branch_id;
        abort_unless($branch>0,403,'No branch assigned.');
        return $branch;
    }

    public function index(Request $request){$this->branch($request);return view('bm.clients');}

    public function data(Request $request)
    {
        $branch=$this->branch($request);
        $page=max(1,(int)$request->input('page',1)); $per=15;
        $search=trim((string)$request->input('search','')); $sort=$request->input('sort','name_asc');

        $base=DB::table('clients as c')->leftJoin('users as u','c.officer_username','=','u.username')
            ->where('c.branch_id',$branch)->where('c.status','active')
            ->when($search,fn($q)=>$q->where(fn($x)=>$x->where('c.name','like',"%$search%")->orWhere('c.union','like',"%$search%")->orWhere('u.full_name','like',"%$search%")));

        $total=(clone $base)->count();
        $ids=(clone $base)->pluck('c.id');
        $totalSavings=(float)DB::table('saving_balances')->whereIn('client_id',$ids)->sum('balance');
        $totalLoans=(float)DB::table('disbursements')->whereIn('client_id',$ids)->where('remaining_balance','>',0)->sum('remaining_balance');

        $order=['name_asc'=>'c.name','savings_desc'=>'savings','loan_desc'=>'outstanding_loan','officer_asc'=>'officer_name'];
        $orderBy=$order[$sort]??'c.name';

        $rows=(clone $base)
            ->select(['c.id','c.name','c.union','c.guarantor_name','c.guarantor_phone','c.officer_username','u.full_name as officer_name'])
            ->selectSub(DB::table('saving_balances')->select('balance')->whereColumn('client_id','c.id')->limit(1),'savings')
            ->selectSub(DB::table('disbursements')->selectRaw('COALESCE(SUM(remaining_balance),0)')->whereColumn('client_id','c.id')->where('remaining_balance','>',0),'outstanding_loan')
            ->orderBy($orderBy)->orderBy('c.name')->forPage($page,$per)->get()
            ->map(fn($r)=>tap($r,function($x){
                $x->savings=(float)($x->savings??0);
                $x->outstanding_loan=(float)($x->outstanding_loan??0);
                $x->guarantor_name=$x->guarantor_name?:'N/A';
                $x->guarantor_phone=$x->guarantor_phone?:'N/A';
                $x->officer_name=$x->officer_name?:$x->officer_username;
            }))->values();

        return response()->json(['success'=>true,'summary'=>['total_clients'=>$total,'total_savings'=>$totalSavings,'total_loans'=>$totalLoans],'clients'=>$rows,'pagination'=>['has_more'=>$page*$per<$total,'total'=>$total,'page'=>$page]]);
    }

    public function history(Request $request,string $client)
    {
        $branch=$this->branch($request);
        $c=DB::table('clients as c')->leftJoin('users as u','c.officer_username','=','u.username')
            ->where('c.id',$client)->where('c.branch_id',$branch)
            ->first(['c.id','c.name','c.officer_username','u.full_name as officer_name']);
        if(!$c)return response()->json(['success'=>false,'message'=>'Client not found or unauthorized'],404);

        $saving=DB::table('saving_collections')->where('client_id',$client)->latest('date')->limit(40)->get(['transaction_id','amount','date','type'])
            ->map(function($x){
                $a=(float)$x->amount; $t=strtolower((string)($x->type??'deposit'));
                return ['timestamp'=>strtotime($x->date),'date_formatted'=>date('M d, Y h:i A',strtotime($x->date)),'type'=>'savings','title'=>'Savings Trans.','amount'=>abs($a),'raw_amount'=>$a,'description'=>str_contains($t,'return')?'Return Cash':(($a<0||$t==='withdrawal')?'Withdrawal':($t==='transfer'?'Transfer':'Deposit')),'transaction_id'=>$x->transaction_id];
            });

        $disb=DB::table('disbursements')->where('client_id',$client)->latest('date')->limit(20)->get(['id','principal','date'])
            ->map(fn($x)=>['timestamp'=>strtotime($x->date),'date_formatted'=>date('M d, Y h:i A',strtotime($x->date)),'type'=>'loan','title'=>'Disbursement','amount'=>(float)$x->principal,'description'=>'Loan Issued','transaction_id'=>$x->id]);

        $rep=DB::table('loan_collections')->where('client_id',$client)->latest('date')->limit(40)->get(['transaction_id','amount_collected','date'])
            ->map(fn($x)=>['timestamp'=>strtotime($x->date),'date_formatted'=>date('M d, Y h:i A',strtotime($x->date)),'type'=>'repayment','title'=>'Repayment','amount'=>(float)$x->amount_collected,'description'=>'Loan Payment','transaction_id'=>$x->transaction_id]);

        $events=$saving->concat($disb)->concat($rep)->sortByDesc('timestamp')->values();
        $savings=(float)(DB::table('savings')->where('client_id',$client)->where('status','active')->value('balance') ?? DB::table('saving_balances')->where('client_id',$client)->value('balance') ?? 0);
        $loan=(float)DB::table('disbursements')->where('client_id',$client)->where('remaining_balance','>',0)->sum('remaining_balance');

        return response()->json(['success'=>true,'client_name'=>$c->name,'officer_name'=>$c->officer_name?:$c->officer_username,'summary'=>['savings'=>$savings,'loan'=>$loan],'history'=>$events]);
    }

    public function update(Request $request,string $client)
    {
        $branch=$this->branch($request);
        $exists=DB::table('clients')->where('id',$client)->where('branch_id',$branch)->exists();
        abort_unless($exists,404);
        $data=$request->validate(['guarantor_name'=>'required|string|max:150','guarantor_phone'=>'required|string|max:30']);
        DB::table('clients')->where('id',$client)->where('branch_id',$branch)->update($data);
        return response()->json(['success'=>true,'message'=>'Updated successfully']);
    }
}
