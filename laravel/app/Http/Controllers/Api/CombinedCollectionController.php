<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Disbursement;
use App\Models\LoanCollection;
use App\Models\Saving;
use App\Models\SavingCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CombinedCollectionController extends Controller
{
    public function unionData(Request $request)
    {
        $user = $request->user();
        $date = $request->date('date')?->toDateString() ?? now()->toDateString();
        $union = trim((string) $request->input('union', ''));
        $role = strtolower((string) $user->role);

        $clients = Client::query()
            ->where('status', 'active')->whereNull('deleted_at')
            ->when($role === 'co', fn ($q) => $q->where('officer_username', $user->username))
            ->when($role === 'bm', fn ($q) => $q->where('branch_id', $user->branch_id))
            ->when($role === 'am', fn ($q) => $q->whereIn('branch_id', DB::table('branches')->select('id')->where('area_id', $user->area_id)))
            ->when(in_array($role, ['zm','dzm','tm'], true), function ($q) use ($user) {
                $q->whereIn('branch_id', function ($sub) use ($user) {
                    $sub->select('id')->from('branches')
                        ->where('zone_id', $user->zone_id)
                        ->orWhereIn('area_id', DB::table('areas')->select('id')->where('zone_id', $user->zone_id));
                });
            })
            ->when($union !== '', fn ($q) => $q->whereRaw('LOWER(TRIM(COALESCE(union, ?))) = LOWER(TRIM(?))', ['', $union]))
            ->orderBy('name')->get(['id','name','union']);

        $defaultInstallments = (bool) $user->is_weekly ? 24 : 23;
        $ids = $clients->pluck('id');

        $loanToday = LoanCollection::query()->whereDate('date', $date)->whereIn('client_id', $ids)->orderBy('id')->get()->groupBy('client_id')->map->first();
        $savingToday = SavingCollection::query()->whereDate('date', $date)->whereIn('client_id', $ids)->get()->groupBy('client_id');
        $balances = Saving::query()->where('status', 'active')->whereIn('client_id', $ids)->orderBy('created_at')->get()->groupBy('client_id')->map(fn ($rows) => (float) $rows->first()->balance);
        $loans = Disbursement::query()->whereIn('client_id', $ids)
            ->where(fn ($q) => $q->where('status', '<>', 'completed')->orWhereDate('payoff_date', '>=', $date))
            ->orderByDesc('date')->get()->groupBy('client_id')->map->first();

        $data = $clients->map(function ($client) use ($loanToday, $savingToday, $balances, $loans, $defaultInstallments) {
            $loan = $loans->get($client->id);
            if ($loan) {
                $total=(float)$loan->total_payable; $remaining=(float)$loan->remaining_balance;
                $num=(int)($loan->num_installments ?: $defaultInstallments); $inst=$num>0?$total/$num:0;
                $loan->installments_paid=$inst>0?(int)floor(($total-$remaining)/$inst):0;
                $loan->inst_amt=$inst;
            }
            $existing=['loan_amt'=>0,'sav_amt'=>0,'wth_type'=>'','wth_amt'=>0];
            if ($tx=$loanToday->get($client->id)) $existing['loan_amt']=(float)$tx->amount_collected;
            foreach ($savingToday->get($client->id, collect()) as $tx) {
                if (strtolower((string)$tx->type)==='deposit') $existing['sav_amt']+=(float)$tx->amount;
                else { $existing['wth_type']=$tx->type; $existing['wth_amt']=abs((float)$tx->amount); }
            }
            return ['id'=>$client->id,'name'=>$client->name,'loan'=>$loan,'savings_balance'=>$balances->get($client->id,0),'existing'=>$existing];
        })->values();

        return response()->json(['success'=>true,'data'=>$data,'settings'=>$this->settings()]);
    }

    private function settings(): array
    {
        $first=fn(string $table)=>DB::table($table)->first();
        return [
            'collection'=>array_merge(['max_installments_per_payment'=>3,'min_installments_per_payment'=>1,'grace_period_days'=>2,'allow_partial_payments'=>0,'allow_overpayment'=>0],(array)($first('loan_collection_settings')??[])),
            'savings'=>array_merge(['min_savings_amount'=>100,'max_savings_amount'=>500000,'allow_weekend_collection'=>0],(array)($first('savings_settings')??[])),
            'withdrawal'=>array_merge(['max_cash_withdrawal'=>50000,'require_image_for_cash'=>1,'allow_weekend_withdrawals'=>0,'max_withdrawals_per_day'=>1,'blocked_withdrawal_types'=>'[]','buffer_cash'=>10,'buffer_withdrawal'=>10,'buffer_return'=>10],(array)($first('withdrawal_settings')??[])),
            'date_readonly'=>(bool)(DB::table('date_control_settings')->value('date_readonly')??false),
        ];
    }
}
