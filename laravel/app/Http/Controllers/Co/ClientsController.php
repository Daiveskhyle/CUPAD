<?php
namespace App\Http\Controllers\Co;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientsController extends Controller
{
 public function index(){abort_unless(strtolower((string)auth()->user()->role)==='co',403);return view('co.clients');}
 public function data(Request $r){
  $u=$r->user();$page=max(1,(int)$r->input('page',1));$per=10;$search=trim((string)$r->input('search',''));$status=$r->input('loan_status','all_active');$sort=$r->input('sort','name_asc');
  $q=Client::query()->where('officer_username',$u->username)->where('status','active')->when($search,fn($q)=>$q->where(fn($x)=>$x->where('name','like',"%$search%")->orWhere('union','like',"%$search%")));
  $rows=$q->get(['id','name','phone','union','guarantor_name','guarantor_phone']);
  $ids=$rows->pluck('id');
  $balances=DB::table('saving_balances')->whereIn('client_id',$ids)->pluck('balance','client_id');
  $loans=DB::table('disbursements')->whereIn('client_id',$ids)->where('remaining_balance','>',0)->select('client_id','remaining_balance')->get()->groupBy('client_id')->map(fn($x)=>$x->sum('remaining_balance'));
  $rows=$rows->map(function($c)use($balances,$loans){$c->savings=(float)($balances[$c->id]??0);$c->outstanding_loan=(float)($loans[$c->id]??0);return$c;});
  $rows=$rows->filter(fn($c)=>$status==='active_loan'?$c->outstanding_loan>0:$status==='active_savings'?$c->savings>0&&$c->outstanding_loan<=0:$status==='inactive'?$c->outstanding_loan<=0&&DB::table('disbursements')->where('client_id',$c->id)->exists():$c->outstanding_loan>0||$c->savings>0);
  $rows=$sort==='savings_desc'?$rows->sortByDesc('savings'):$sort==='loan_desc'?$rows->sortByDesc('outstanding_loan'):$rows->sortBy('name');
  $total=$rows->count();$pageRows=$rows->forPage($page,$per)->values();
  $sumIds=$q->pluck('id');$totalSavings=(float)DB::table('saving_balances')->whereIn('client_id',$sumIds)->sum('balance');$totalLoans=(float)DB::table('disbursements')->where('officer',$u->username)->where('remaining_balance','>',0)->sum('remaining_balance');
  return response()->json(['success'=>true,'summary'=>['total_clients'=>$q->count(),'total_savings'=>$totalSavings,'total_loans'=>$totalLoans],'clients'=>$pageRows,'pagination'=>['has_more'=>$page*$per<$total,'total'=>$total]]);
 }
 public function history(Request $r,$client){
  $u=$r->user();$c=Client::where('id',$client)->where('officer_username',$u->username)->first();if(!$c)return response()->json(['success'=>false,'message'=>'Client not found or unauthorized'],404);
  $saving=DB::table('saving_collections')->where('client_id',$c->id)->latest('date')->limit(40)->get(['transaction_id','amount','date','type'])->map(fn($x)=>$this->savingEvent($x));
  $disb=DB::table('disbursements')->where('client_id',$c->id)->latest('date')->limit(20)->get(['id','principal','date'])->map(fn($x)=>['timestamp'=>strtotime($x->date),'date_formatted'=>date('M d, Y h:i A',strtotime($x->date)),'type'=>'loan','title'=>'Disbursement','amount'=>(float)$x->principal,'description'=>'Loan Issued','icon'=>'fa-hand-holding-usd','transaction_id'=>$x->id]);
  $rep=DB::table('loan_collections')->where('client_id',$c->id)->latest('date')->limit(40)->get(['transaction_id','amount_collected','date'])->map(fn($x)=>['timestamp'=>strtotime($x->date),'date_formatted'=>date('M d, Y h:i A',strtotime($x->date)),'type'=>'repayment','title'=>'Repayment','amount'=>(float)$x->amount_collected,'description'=>'Loan Payment','icon'=>'fa-money-bill-wave','transaction_id'=>$x->transaction_id]);
  $events=$saving->concat($disb)->concat($rep)->sortByDesc('timestamp')->values();
  $loan=(float)DB::table('disbursements')->where('client_id',$c->id)->where('remaining_balance','>',0)->sum('remaining_balance');
  $sav=(float)(DB::table('savings')->where('client_id',$c->id)->where('status','active')->value('balance')??DB::table('saving_balances')->where('client_id',$c->id)->value('balance')??0);
  return response()->json(['success'=>true,'client_name'=>$c->name,'summary'=>['savings'=>$sav,'loan'=>$loan],'history'=>$events]);
 }
 public function update(Request $r,$client){
  $u=$r->user();$c=Client::where('id',$client)->where('officer_username',$u->username)->firstOrFail();
  $d=$r->validate(['phone'=>'required|string|max:30','guarantor_name'=>'required|string|max:150','guarantor_phone'=>'required|string|max:30']);
  $c->update($d);return response()->json(['success'=>true,'message'=>'Details updated successfully','client'=>$c->only(['id','phone','guarantor_name','guarantor_phone'])]);
 }
 private function savingEvent($x){$a=(float)$x->amount;$t=strtolower((string)($x->type??'deposit'));$withdraw=$a<0||in_array($t,['withdrawal','return_cash','return cash','debit','charge'],true);$desc=str_contains($t,'return')?'Return Cash':($t==='withdrawal'||$a<0?'Withdrawal':($t==='transfer'?'Transfer':(in_array($t,['charge','fee'],true)?'Fee/Charges':'Deposit')));return['timestamp'=>strtotime($x->date),'date_formatted'=>date('M d, Y h:i A',strtotime($x->date)),'type'=>'savings','title'=>'Savings Trans.','amount'=>abs($a),'raw_amount'=>$a,'description'=>$desc,'icon'=>'fa-piggy-bank','transaction_id'=>$x->transaction_id,'is_withdrawal'=>$withdraw];}
}