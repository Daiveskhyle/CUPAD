<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Disbursement;
use App\Models\LoanCollection;
use App\Services\LoanCollectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanCollectionController extends Controller
{
    public function __construct(private LoanCollectionService $loans) {}

    public function collect(Request $request)
    {
        // The mobile CUPAD API contract uses amount + optional loan_id.
        // Keep installment support for the Laravel web/mobile migration as a compatibility path.
        if ($request->filled('amount')) {
            return $this->collectAmount($request);
        }

        $data = $request->validate([
            'client_id' => ['required', 'string', 'exists:clients,id'],
            'installment' => ['required', 'integer', 'between:1,3'],
            'date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $client = Client::query()->whereKey($data['client_id'])->whereNull('deleted_at')->firstOrFail();
        $this->authorizeClient($request, $client);
        $user = $request->user();

        try {
            $result = $this->loans->repay(
                $client->id,
                (int) $data['installment'],
                $user->username,
                (bool) $user->is_weekly,
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

        return response()->json(['success' => true, 'data' => $result] + $result);
    }

    private function collectAmount(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'exists:clients,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'loan_id' => ['nullable'],
        ]);

        $client = Client::query()->whereKey($data['client_id'])->whereNull('deleted_at')->firstOrFail();
        $this->authorizeClient($request, $client);
        $user = $request->user();

        $result = DB::transaction(function () use ($data, $client, $user) {
            $loanQuery = Disbursement::query()
                ->where('client_id', $client->id)
                ->where('remaining_balance', '>', 0)
                ->orderByDesc('date')
                ->lockForUpdate();

            if (!empty($data['loan_id'])) {
                $loanQuery->whereKey($data['loan_id']);
            }

            $loan = $loanQuery->first();

            if (!$loan) {
                throw ValidationException::withMessages(['loan_id' => 'No active loan found.']);
            }

            $amount = (float) $data['amount'];
            $remaining = (float) $loan->remaining_balance;

            if ($amount > $remaining) {
                throw ValidationException::withMessages(['amount' => 'Payment exceeds remaining balance']);
            }

            $newBalance = round(max(0, $remaining - $amount), 2);
            $transactionId = 'LP' . now()->format('YmdHis') . random_int(1000, 9999);

            LoanCollection::create([
                'transaction_id' => $transactionId,
                'client_id' => $client->id,
                'disbursement_id' => $loan->id,
                'amount_collected' => $amount,
                'date' => now(),
                'officer' => $user->username,
                'type' => 'repayment',
                'disbursement_date' => $loan->date,
                'remaining_balance' => $newBalance,
                'notes' => (string) ($data['notes'] ?? ''),
            ]);

            $loan->update([
                'remaining_balance' => $newBalance,
                'status' => $newBalance <= 0 ? 'completed' : 'active',
                'payoff_date' => $newBalance <= 0 ? now()->toDateString() : $loan->payoff_date,
            ]);

            return [
                'transaction_id' => $transactionId,
                'remaining_balance' => $newBalance,
            ];
        });

        return response()->json([
            'success' => true,
            'transaction_id' => $result['transaction_id'],
            'remaining_balance' => $result['remaining_balance'],
            'message' => 'Loan payment recorded',
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
