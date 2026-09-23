<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $limit = min(max((int) $request->integer('limit', 20), 1), 100);

        $clients = $this->scopedQuery($request->user())
            ->whereNull('deleted_at')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%' . $request->string('q') . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                      ->orWhere('phone', 'like', $term)
                      ->orWhere('id', 'like', $term);
                });
            })
            ->paginate($limit);

        return response()->json([
            'success' => true,
            'data' => $clients->items(),
            'pagination' => [
                'total' => $clients->total(),
                'limit' => $clients->perPage(),
                'offset' => max(0, ($clients->currentPage() - 1) * $clients->perPage()),
                'has_more' => $clients->hasMorePages(),
            ],
        ]);
    }

    public function show(Request $request, Client $client)
    {
        abort_unless($this->canAccess($request->user(), $client), 404);
        abort_if($client->deleted_at !== null, 404);

        return response()->json([
            'success' => true,
            'client' => $client,
        ]);
    }

    public function savings(Request $request, Client $client)
    {
        abort_unless($this->canAccess($request->user(), $client), 404);
        abort_if($client->deleted_at !== null, 404);
        return response()->json(['success' => true, 'data' => $client->savings()->orderByDesc('created_at')->get()]);
    }

    public function loans(Request $request, Client $client)
    {
        abort_unless($this->canAccess($request->user(), $client), 404);
        abort_if($client->deleted_at !== null, 404);
        return response()->json(['success' => true, 'data' => $client->disbursements()->orderByDesc('date')->get([
            'id','principal','interest_rate','total_payable','remaining_balance','num_installments','loan_term_type','date','due_date','payoff_date','status'
        ])]);
    }

    public function transactions(Request $request, Client $client)
    {
        abort_unless($this->canAccess($request->user(), $client), 404);
        abort_if($client->deleted_at !== null, 404);
        $savings = $client->savingCollections()->get([
            'transaction_id','amount','type','date','balance_after','notes'
        ])->map(fn ($row) => array_merge($row->toArray(), ['source' => 'savings']))->all();
        $loans = $client->loanCollections()->get([
            'transaction_id','amount_collected','type','date','remaining_balance','notes'
        ])->map(fn ($row) => array_merge($row->toArray(), ['amount' => $row->amount_collected, 'balance_after' => $row->remaining_balance, 'source' => 'loan']))->all();
        $rows = collect($savings)->concat($loans)->sortByDesc('date')->take(200)->values();
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function portfolio(Request $request, Client $client)
    {
        abort_unless($this->canAccess($request->user(), $client), 404);
        abort_if($client->deleted_at !== null, 404);

        $savings = (float) $client->savings()->where('status', '<>', 'closed')->sum('balance');
        $principal = (float) $client->disbursements()->sum('principal');
        $outstanding = (float) $client->disbursements()->sum('remaining_balance');
        $deposits = (float) $client->savingCollections()
            ->whereIn('type', ['deposit','cash','return','interest'])
            ->sum('amount');
        $repayments = (float) $client->loanCollections()
            ->where('type', 'repayment')
            ->sum('amount_collected');

        return response()->json([
            'success' => true,
            'client' => $client,
            'savings' => [
                'balance' => $savings,
                'total_deposits' => $deposits,
            ],
            'loans' => [
                'count' => $client->disbursements()->count(),
                'principal' => $principal,
                'outstanding' => $outstanding,
                'total_repaid' => $repayments,
            ],
        ]);
    }
    private function scopedQuery($user)
    {
        $role = strtolower((string) $user->role);

        return Client::query()
            ->when($role === 'co', fn ($q) => $q->where('officer_username', $user->username))
            ->when($role === 'bm', fn ($q) => $q->where('branch_id', $user->branch_id))
            ->when($role === 'am', fn ($q) => $q->whereIn('branch_id', DB::table('branches')->select('id')->where('area_id', $user->area_id)))
            ->when(in_array($role, ['zm', 'dzm', 'tm'], true), fn ($q) => $q->whereIn('branch_id',
                DB::table('branches')->select('id')->where(function ($b) use ($user) {
                    $b->where('zone_id', $user->zone_id)
                      ->orWhereIn('area_id', DB::table('areas')->select('id')->where('zone_id', $user->zone_id));
                })
            ));
    }

    private function canAccess($user, Client $client): bool
    {
        if (strtolower((string) $user->role) === 'admin') return true;
        return $this->scopedQuery($user)->whereKey($client->getKey())->exists();
    }
}
