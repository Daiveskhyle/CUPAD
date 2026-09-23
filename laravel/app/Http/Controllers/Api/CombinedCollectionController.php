<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Disbursement;
use App\Models\LoanCollection;
use App\Models\Saving;
use App\Models\SavingCollection;
use App\Services\CombinedCollectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CombinedCollectionController extends Controller
{
    public function __construct(private CombinedCollectionService $service) {}

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
            ->when($union !== '', fn ($q) => $q->whereRaw('LOWER(TRIM(COALESCE(\`union\`, ?))) = LOWER(TRIM(?))', ['', $union]))
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

        return response()->json(['success'=>true,'data'=>$data,'settings'=>$this->service->settings()]);
    }

    public function saveClient(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'string'],
            'date' => ['nullable', 'date'],
            'installment' => ['nullable', 'integer', 'min:0', 'max:3'],
            'savings_amount' => ['nullable', 'numeric', 'min:0'],
            'withdrawal_type' => ['nullable', 'in:cash,withdrawal,return'],
            'withdrawal_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'picture' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        $this->authorizeClient($request, $data['client_id']);

        try {
            return response()->json($this->service->saveClient($data, $request->user(), $request->file('picture')));
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    private function authorizeClient(Request $request, string $clientId): void
    {
        $user = $request->user();
        $client = Client::query()->whereKey($clientId)->firstOrFail();
        $role = strtolower((string) $user->role);

        $allowed = match ($role) {
            'admin' => true,
            'co' => $client->officer_username === $user->username,
            'bm' => (string) $client->branch_id === (string) $user->branch_id,
            'am' => DB::table('branches')->where('id', $client->branch_id)->where('area_id', $user->area_id)->exists(),
            'zm', 'dzm', 'tm' => DB::table('branches')->where('id', $client->branch_id)->where('zone_id', $user->zone_id)->exists(),
            default => false,
        };

        if (!$allowed) {
            abort(403, 'Unauthorized');
        }
    }
}
