<?php

namespace App\Services;

use App\Models\Saving;
use App\Models\SavingCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SavingsService
{
    /**
     * Record a deposit using savings.balance as the source of truth.
     * saving_balances is synchronized as a legacy compatibility cache.
     */
    public function deposit(
        string $clientId,
        float $amount,
        ?string $officer = null,
        ?string $date = null,
        string $notes = ''
    ): array {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
        }

        $paymentDate = $date ? date('Y-m-d', strtotime($date)) : now()->toDateString();

        return DB::transaction(function () use ($clientId, $amount, $officer, $paymentDate, $notes) {
            $duplicate = SavingCollection::query()
                ->where('client_id', $clientId)
                ->where('type', 'deposit')
                ->whereDate('date', $paymentDate)
                ->lockForUpdate()
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'date' => 'A savings transaction already exists for this client on the selected date.',
                ]);
            }

            $saving = Saving::query()
                ->where('client_id', $clientId)
                ->where('status', 'active')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();

            $oldBalance = (float) ($saving?->balance ?? 0);
            $newBalance = $oldBalance + $amount;

            if ($saving) {
                $saving->update([
                    'balance' => $newBalance,
                    'updated_at' => now(),
                ]);
            } else {
                $saving = Saving::create([
                    'id' => 'SVG-' . strtoupper(bin2hex(random_bytes(16))),
                    'client_id' => $clientId,
                    'officer' => $officer,
                    'balance' => $newBalance,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $transactionId = 'SV' . now()->format('YmdHis') . random_int(1000, 9999);

            SavingCollection::create([
                'transaction_id' => $transactionId,
                'client_id' => $clientId,
                'savings_id' => $saving->id,
                'amount' => $amount,
                'type' => 'deposit',
                'date' => $paymentDate . ' ' . now()->format('H:i:s'),
                'officer' => $officer,
                'balance_after' => $newBalance,
                'notes' => $notes,
                'created_at' => now(),
            ]);

            // Keep the old cache synchronized for existing reports/mobile clients.
            DB::statement(
                'INSERT INTO saving_balances (client_id, balance, last_updated)
                 VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE balance = VALUES(balance), last_updated = NOW()',
                [$clientId, $newBalance]
            );

            return [
                'transaction_id' => $transactionId,
                'balance' => $newBalance,
                'saving_id' => $saving->id,
            ];
        });
    }
}
