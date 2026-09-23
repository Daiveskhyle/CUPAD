<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\SavingsService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SavingsController extends Controller
{
    public function __construct(private SavingsService $savings) {}

    public function collect(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'exists:clients,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $client = Client::query()
            ->whereKey($data['client_id'])
            ->whereNull('deleted_at')
            ->firstOrFail();

        $user = $request->user();

        // Preserve CUPAD role visibility rules for mobile/API writes.
        $role = strtolower((string) $user->role);
        $allowed = match ($role) {
            'co' => $client->officer_username === $user->username,
            'bm' => (string) $client->branch_id === (string) $user->branch_id,
            'am' => $client->branch_id && DB::table('branches')->where('id', $client->branch_id)->where('area_id', $user->area_id)->exists(),
            'zm', 'dzm', 'tm' => $client->branch_id && DB::table('branches')->where('id', $client->branch_id)->where(function ($q) use ($user) {
                $q->where('zone_id', $user->zone_id)
                  ->orWhereIn('area_id', DB::table('areas')->select('id')->where('zone_id', $user->zone_id));
            })->exists(),
            'admin' => true,
            default => false,
        };

        abort_unless($allowed, 403, 'Client not found or access denied.');

        try {
            $result = $this->savings->deposit(
                $client->id,
                (float) $data['amount'],
                $user->username,
                $data['date'] ?? null,
                (string) ($data['notes'] ?? '')
            );
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => collect($e->errors())->flatten()->first(),
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
            'transaction_id' => $result['transaction_id'],
            'balance' => $result['balance'],
            'message' => 'Savings collected',
        ]);
    }
}
