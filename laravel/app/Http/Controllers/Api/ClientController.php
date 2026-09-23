<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $limit = min(max((int) $request->integer('limit', 20), 1), 100);

        $clients = Client::query()
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
            'data' => $clients,
        ]);
    }

    public function show(Client $client)
    {
        abort_if($client->deleted_at !== null, 404);

        return response()->json([
            'success' => true,
            'client' => $client,
        ]);
    }

    public function portfolio(Client $client)
    {
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
}
