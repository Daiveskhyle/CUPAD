<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\SavingCollection;
use App\Services\SavingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $client = Client::query()->whereKey($data['client_id'])->whereNull('deleted_at')->firstOrFail();
        $this->authorizeClient($request, $client);
        $user = $request->user();

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

    public function withdraw(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'exists:clients,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $client = Client::query()->whereKey($data['client_id'])->whereNull('deleted_at')->firstOrFail();
        $this->authorizeClient($request, $client);
        $user = $request->user();

        try {
            $result = DB::transaction(function () use ($client, $data, $user) {
                $saving = $client->savings()
                    ->where('status', 'active')
                    ->orderBy('created_at')
                    ->lockForUpdate()
                    ->first();

                if (!$saving) {
                    throw ValidationException::withMessages(['amount' => 'No active savings account found.']);
                }

                $old = (float) $saving->balance;
                $amount = (float) $data['amount'];

                if ($amount > $old) {
                    throw ValidationException::withMessages(['amount' => 'Insufficient savings balance.']);
                }

                $new = round($old - $amount, 2);
                $saving->update(['balance' => $new]);

                $transactionId = 'WD' . now()->format('YmdHis') . random_int(1000, 9999);

                SavingCollection::create([
                    'transaction_id' => $transactionId,
                    'client_id' => $client->id,
                    'savings_id' => $saving->id,
                    'amount' => -$amount,
                    'type' => 'withdrawal',
                    'date' => now(),
                    'officer' => $user->username,
                    'balance_after' => $new,
                    'notes' => (string) ($data['notes'] ?? ''),
                ]);

                DB::table('saving_balances')->updateOrInsert(
                    ['client_id' => $client->id],
                    ['balance' => $new, 'last_updated' => now()]
                );

                return ['transaction_id' => $transactionId, 'balance' => $new];
            });
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => collect($e->errors())->flatten()->first(),
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'transaction_id' => $result['transaction_id'],
            'balance' => $result['balance'],
            'message' => 'Withdrawal recorded',
        ]);
    }

    private function authorizeClient(Request $request, Client $client): void
    {
        $u = $request->user();
        $role = strtolower((string) $u->role);

        $allowed = match ($role) {
            'admin' => true,
            'co' => $client->officer_username === $u->username,
            'bm' => (string) $client->branch_id === (string) $u->branch_id,
            'am' => $client->branch_id && DB::table('branches')->where('id', $client->branch_id)->where('area_id', $u->area_id)->exists(),
            'zm', 'dzm', 'tm' => $client->branch_id && DB::table('branches')->where('id', $client->branch_id)->where(function ($q) use ($u) {
                $q->where('zone_id', $u->zone_id)
                  ->orWhereIn('area_id', DB::table('areas')->select('id')->where('zone_id', $u->zone_id));
            })->exists(),
            default => false,
        };

        abort_unless($allowed, 403, 'Client not found or access denied.');
    }
}
