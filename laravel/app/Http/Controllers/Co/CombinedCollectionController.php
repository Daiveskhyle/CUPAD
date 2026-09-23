<?php

namespace App\Http\Controllers\Co;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\CombinedCollectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CombinedCollectionController extends Controller
{
    public function __construct(private CombinedCollectionService $service) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless(strtolower((string) $user->role) === 'co', 403);

        $unions = Client::query()
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->where('officer_username', $user->username)
            ->whereNotNull('union')
            ->where('union', '<>', '')
            ->distinct()
            ->orderBy('union')
            ->pluck('union');

        return view('co.combined-collection', [
            'user' => $user,
            'unions' => $unions,
            'settings' => $this->service->settings(),
        ]);
    }

    public function data(Request $request)
    {
        abort_unless(strtolower((string) $request->user()->role) === 'co', 403);

        $union = trim((string) $request->input('union', ''));
        $date = $request->date('date')?->toDateString() ?? now()->toDateString();
        $user = $request->user();

        $clients = Client::query()
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->where('officer_username', $user->username)
            ->when($union !== '', fn ($q) => $q->where('union', $union))
            ->orderBy('name')
            ->get(['id', 'name', 'union']);

        $ids = $clients->pluck('id');
        $loanToday = DB::table('loan_collections')
            ->whereDate('date', $date)->whereIn('client_id', $ids)->orderBy('id')
            ->get()->groupBy('client_id')->map->first();
        $savingToday = DB::table('saving_collections')
            ->whereDate('date', $date)->whereIn('client_id', $ids)->get()->groupBy('client_id');
        $balances = DB::table('savings')->where('status', 'active')->whereIn('client_id', $ids)
            ->orderBy('created_at')->get()->groupBy('client_id')->map(fn ($rows) => (float) $rows->first()->balance);
        $loans = DB::table('disbursements')->whereIn('client_id', $ids)
            ->where(fn ($q) => $q->where('status', '<>', 'completed')->orWhereDate('payoff_date', '>=', $date))
            ->orderByDesc('date')->get()->groupBy('client_id')->map->first();

        $weekly = (bool) $user->is_weekly;
        $rows = $clients->map(function ($client) use ($loanToday, $savingToday, $balances, $loans, $weekly) {
            $loan = $loans->get($client->id);
            if ($loan) {
                $total = (float) $loan->total_payable;
                $remaining = (float) $loan->remaining_balance;
                $num = (int) ($loan->num_installments ?: ($weekly ? 24 : 23));
                $inst = $num > 0 ? $total / $num : 0;
                $loan->installments_paid = $inst > 0 ? (int) floor(($total - $remaining) / $inst) : 0;
                $loan->inst_amt = $inst;
            }

            $existing = ['loan_amt' => 0, 'sav_amt' => 0, 'wth_type' => '', 'wth_amt' => 0];
            if ($tx = $loanToday->get($client->id)) $existing['loan_amt'] = (float) $tx->amount_collected;
            foreach ($savingToday->get($client->id, collect()) as $tx) {
                if (strtolower((string) $tx->type) === 'deposit') $existing['sav_amt'] += (float) $tx->amount;
                else {
                    $existing['wth_type'] = $tx->type;
                    $existing['wth_amt'] = abs((float) $tx->amount);
                }
            }

            return [
                'id' => $client->id,
                'name' => $client->name,
                'loan' => $loan,
                'savings_balance' => $balances->get($client->id, 0),
                'existing' => $existing,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $rows,
            'settings' => $this->service->settings(),
        ]);
    }

    public function save(Request $request)
    {
        abort_unless(strtolower((string) $request->user()->role) === 'co', 403);

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

        $client = Client::query()
            ->whereKey($data['client_id'])
            ->where('status', 'active')
            ->where('officer_username', $request->user()->username)
            ->firstOrFail();

        try {
            return response()->json(
                $this->service->saveClient($data, $request->user(), $request->file('picture'))
            );
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
