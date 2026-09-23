<?php

namespace App\Services;

use App\Models\Disbursement;
use App\Models\LoanCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanCollectionService
{
    public function repay(
        string $clientId,
        int $installments,
        string $officer,
        bool $isWeekly = false,
        ?string $date = null,
        string $notes = ''
    ): array {
        if ($installments < 1 || $installments > 2) {
            throw ValidationException::withMessages([
                'installment' => 'Select one or two installments.',
            ]);
        }

        $paymentDate = $date ? date('Y-m-d', strtotime($date)) : now()->toDateString();
        $defaultInstallments = $isWeekly ? 24 : 23;

        return DB::transaction(function () use ($clientId, $installments, $officer, $paymentDate, $notes, $defaultInstallments) {
            $duplicate = LoanCollection::query()
                ->where('client_id', $clientId)
                ->whereDate('date', $paymentDate)
                ->lockForUpdate()
                ->first();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'date' => 'Loan collection has already been made for this client today.',
                ]);
            }

            $loan = Disbursement::query()
                ->where('client_id', $clientId)
                ->where('remaining_balance', '>', 0.01)
                ->where('status', '<>', 'completed')
                ->orderByDesc('date')
                ->lockForUpdate()
                ->first();

            if (!$loan) {
                throw ValidationException::withMessages([
                    'client_id' => 'No active loan found.',
                ]);
            }

            $total = (float) $loan->total_payable;
            $remaining = (float) $loan->remaining_balance;
            $numberOfInstallments = (int) ($loan->num_installments ?: $defaultInstallments);
            $installmentAmount = $numberOfInstallments > 0
                ? $total / $numberOfInstallments
                : 0;

            $amount = min($remaining, $installmentAmount * $installments);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'installment' => 'Loan has no remaining balance.',
                ]);
            }

            $newBalance = max(0, $remaining - $amount);
            $transactionId = 'LP' . now()->format('YmdHis') . random_int(1000, 9999);

            LoanCollection::create([
                'transaction_id' => $transactionId,
                'client_id' => $clientId,
                'disbursement_id' => $loan->id,
                'amount_collected' => $amount,
                'date' => $paymentDate . ' ' . now()->format('H:i:s'),
                'officer' => $officer,
                'type' => 'repayment',
                'remaining_balance' => $newBalance,
                'notes' => $notes,
            ]);

            $loan->update([
                'remaining_balance' => $newBalance,
                'status' => $newBalance <= 0.01 ? 'completed' : 'active',
            ]);

            return [
                'transaction_id' => $transactionId,
                'amount_collected' => $amount,
                'remaining_balance' => $newBalance,
                'installments' => $installments,
                'disbursement_id' => $loan->id,
            ];
        });
    }
}
