<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Disbursement;
use App\Models\Saving;
use App\Models\SavingCollection;
use App\Models\LoanCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class CombinedCollectionService
{
    public function saveClient(array $data, $user, ?UploadedFile $picture = null): array
    {
        $clientId = (string) $data['client_id'];
        $paymentDateTime = $data['date'] ?? now()->format('Y-m-d H:i:s');
        $paymentDate = date('Y-m-d', strtotime($paymentDateTime));
        $loanInstallments = (int) ($data['installment'] ?? 0);
        $savingAmount = max(0, (float) ($data['savings_amount'] ?? 0));
        $withdrawalType = (string) ($data['withdrawal_type'] ?? '');
        $withdrawalAmount = max(0, (float) ($data['withdrawal_amount'] ?? 0));
        $requestNotes = trim((string) ($data['notes'] ?? ''));
        $settings = $this->settings();
        $weekly = (bool) ($user->is_weekly ?? false);
        $newPicturePath = null;

        if (!$clientId) {
            throw new RuntimeException('Client ID missing.');
        }

        $client = Client::query()->whereKey($clientId)->where('status', 'active')->first();
        if (!$client) {
            throw new RuntimeException('Client not found or inactive.');
        }

        if ($settings['date_readonly'] && $paymentDate !== now()->format('Y-m-d')) {
            throw new RuntimeException('Date modification is not allowed. Please use current date.');
        }

        if ($loanInstallments > 0 && in_array($withdrawalType, ['withdrawal', 'return'], true)) {
            throw new RuntimeException('Repayment and non-cash withdrawal (Deduct/Return) cannot be processed at the same time.');
        }

        if ($loanInstallments < 0 || $savingAmount < 0 || $withdrawalAmount < 0) {
            throw new RuntimeException('Negative amounts are not allowed.');
        }

        $day = (int) date('N', strtotime($paymentDate));
        if ($day >= 6) {
            if (($savingAmount > 0 || $loanInstallments > 0) && !$settings['savings']['allow_weekend_collection']) {
                throw new RuntimeException('Weekend collections are disabled.');
            }
            if ($withdrawalAmount > 0 && !$settings['withdrawal']['allow_weekend_withdrawals']) {
                throw new RuntimeException('Weekend withdrawals are disabled.');
            }
        }

        if ($savingAmount > 0 && $savingAmount > $settings['savings']['max_savings_amount']) {
            throw new RuntimeException('Savings amount exceeds maximum allowed limit.');
        }

        if ($withdrawalType === 'cash' && $withdrawalAmount > $settings['withdrawal']['max_cash_withdrawal']) {
            throw new RuntimeException('Cash withdrawal exceeds maximum allowed limit.');
        }

        if ($loanInstallments > $settings['collections']['max_installments_per_payment']) {
            throw new RuntimeException('Installments exceed the maximum allowed per payment.');
        }

        if ($loanInstallments > 0 && $loanInstallments < $settings['collections']['min_installments_per_payment']) {
            throw new RuntimeException('Installment value is below the minimum allowed.');
        }

        if ($withdrawalType === 'cash') {
            $existingCash = SavingCollection::query()
                ->where('client_id', $clientId)
                ->whereDate('date', $paymentDate)
                ->where('type', 'cash')
                ->first();

            if (!$picture && !$existingCash && $settings['withdrawal']['require_image_for_cash']) {
                throw new RuntimeException('Proof image is required for cash withdrawals.');
            }

            if ($picture) {
                $mime = $picture->getMimeType();
                if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
                    throw new RuntimeException('Invalid image file format. Only JPG/PNG are allowed.');
                }
                $newPicturePath = $picture->store('withdrawals', 'public');
                if (!$newPicturePath) {
                    throw new RuntimeException('Unable to store withdrawal proof image.');
                }
            }
        }

        try {
            return DB::transaction(function () use (
                $client, $user, $clientId, $paymentDateTime, $paymentDate, $loanInstallments,
                $savingAmount, $withdrawalType, $withdrawalAmount, $requestNotes, $settings, $weekly,
                $newPicturePath
            ) {
                $updates = [];
                $previousWithdrawalNotes = null;

                $saving = Saving::query()
                    ->where('client_id', $clientId)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

                if (!$saving && ($savingAmount > 0 || $withdrawalAmount > 0 || $withdrawalType !== '')) {
                    $saving = new Saving();
                    $saving->id = 'SVG-' . strtoupper((string) Str::uuid());
                    $saving->client_id = $clientId;
                    $saving->officer = $user->username;
                    $saving->balance = 0;
                    $saving->status = 'active';
                    $saving->created_at = now();
                    $saving->save();
                }

                $currentBalance = $saving ? (float) $saving->balance : 0.0;
                if ($saving) {
                    $currentBalance = (float) DB::table('savings')
                        ->where('id', $saving->id)
                        ->lockForUpdate()
                        ->value('balance');
                }

                // Keep the compatibility table synchronized, but never use it as the source of truth.
                $this->syncLegacyBalance($clientId, $currentBalance);

                $existingLoan = LoanCollection::query()
                    ->where('client_id', $clientId)
                    ->whereDate('date', $paymentDate)
                    ->lockForUpdate()
                    ->first();

                $existingSaving = SavingCollection::query()
                    ->where('client_id', $clientId)
                    ->whereDate('date', $paymentDate)
                    ->where('type', 'deposit')
                    ->lockForUpdate()
                    ->first();

                $existingWithdrawal = SavingCollection::query()
                    ->where('client_id', $clientId)
                    ->whereDate('date', $paymentDate)
                    ->whereIn('type', ['cash', 'withdrawal', 'return'])
                    ->lockForUpdate()
                    ->first();

                $loan = Disbursement::query()
                    ->where('client_id', $clientId)
                    ->where(function ($q) use ($paymentDate) {
                        $q->where('status', '!=', 'completed')
                          ->orWhereDate('payoff_date', '>=', $paymentDate);
                    })
                    ->orderByDesc('date')
                    ->lockForUpdate()
                    ->first();

                if (!$loan && $existingLoan?->disbursement_id) {
                    $loan = Disbursement::query()->whereKey($existingLoan->disbursement_id)->lockForUpdate()->first();
                }

                // Revert the previous withdrawal before recalculating today's state.
                if ($existingWithdrawal) {
                    $previousWithdrawalNotes = $existingWithdrawal->notes;
                    $oldDeduct = abs((float) $existingWithdrawal->amount);
                    $currentBalance += $oldDeduct;

                    if ($saving) {
                        $saving->balance = $currentBalance;
                        $saving->updated_at = now();
                        $saving->save();
                        $this->syncLegacyBalance($clientId, $currentBalance);
                    }

                    if (in_array($existingWithdrawal->type, ['withdrawal', 'return'], true) && $loan) {
                        $this->adjustDisbursement($loan, $oldDeduct, true, $paymentDate);
                    }

                    $existingWithdrawal->delete();
                    if ($withdrawalType === '') {
                        $updates[] = 'Withdrawal Removed';
                    }
                }

                // Loan repayment.
                $payAmount = 0.0;
                if ($loan && $loanInstallments > 0) {
                    $numInstallments = (int) ($loan->num_installments ?: ($weekly ? 24 : 23));
                    $installmentAmount = $numInstallments > 0 ? ((float) $loan->total_payable / $numInstallments) : 0;
                    $payAmount = $installmentAmount * $loanInstallments;
                }

                if ($existingLoan && $existingLoan->disbursement_id) {
                    $oldAmount = (float) $existingLoan->amount_collected;
                    $newDisbId = $payAmount > 0 && $loan ? $loan->id : null;
                    if ($payAmount < $oldAmount && $existingLoan->disbursement_id === $newDisbId) {
                        $oldDisb = Disbursement::query()->find($existingLoan->disbursement_id);
                        if ($oldDisb && (float) $oldDisb->remaining_balance <= 0.01) {
                            $newer = Disbursement::query()
                                ->where('client_id', $clientId)
                                ->where('id', '!=', $existingLoan->disbursement_id)
                                ->whereDate('date', '>=', $paymentDate)
                                ->exists();
                            if ($newer) {
                                throw new RuntimeException('Cannot reduce/clear this repayment. It paid off a previous loan, and a new loan has already been disbursed.');
                            }
                        }
                    }
                }

                if ($payAmount > 0 && $loan) {
                    $graceDate = date('Y-m-d', strtotime($loan->date . ' +' . (int) $settings['collections']['grace_period_days'] . ' days'));
                    if ($paymentDate < $graceDate) {
                        throw new RuntimeException('Collections allowed after ' . date('d M Y', strtotime($graceDate)) . ' (' . (int) $settings['collections']['grace_period_days'] . ' day grace period).');
                    }

                    if ($existingLoan && $existingLoan->disbursement_id === $loan->id) {
                        $diff = $payAmount - (float) $existingLoan->amount_collected;
                        $newRemaining = max(0, (float) $loan->remaining_balance - $diff);
                        $this->setDisbursement($loan, $newRemaining, $paymentDate);
                        $existingLoan->amount_collected = $payAmount;
                        $existingLoan->remaining_balance = $newRemaining;
                        $existingLoan->save();
                        $updates[] = 'Loan Updated';
                    } else {
                        if ($existingLoan?->disbursement_id) {
                            $oldDisb = Disbursement::query()->find($existingLoan->disbursement_id);
                            if ($oldDisb) {
                                $this->adjustDisbursement($oldDisb, (float) $existingLoan->amount_collected, true, $paymentDate);
                            }
                            $existingLoan->delete();
                        }

                        $newRemaining = max(0, (float) $loan->remaining_balance - $payAmount);
                        $this->setDisbursement($loan, $newRemaining, $paymentDate);
                        $this->createLoanCollection($clientId, $loan->id, $payAmount, $newRemaining, $paymentDateTime, $user->username);
                        $updates[] = $existingLoan ? 'Loan Switched & Paid' : 'Loan Paid';
                    }
                } elseif ($existingLoan && $payAmount <= 0) {
                    if ($existingLoan->disbursement_id) {
                        $oldDisb = Disbursement::query()->find($existingLoan->disbursement_id);
                        if ($oldDisb) {
                            $this->adjustDisbursement($oldDisb, (float) $existingLoan->amount_collected, true, $paymentDate);
                        }
                    }
                    $existingLoan->delete();
                    $updates[] = 'Loan Removed';
                }

                // Savings deposit, preserving edit semantics.
                if ($savingAmount > 0 || $existingSaving) {
                    if ($existingSaving && $savingAmount < (float) $existingSaving->amount && $loan) {
                        $required = (float) $loan->principal * (float) $settings['savings_requirement_percentage'];
                        $testBalance = $currentBalance - (float) $existingSaving->amount + $savingAmount;
                        if ($testBalance < $required) {
                            throw new RuntimeException(
                                'Cannot clear or reduce savings deposit. Must maintain ' .
                                ((float) $settings['savings_requirement_percentage'] * 100) .
                                '% of loan principal (₦' . number_format($required, 2) . ') as collateral.'
                            );
                        }
                    }

                    if ($existingSaving) {
                        $oldAmount = (float) $existingSaving->amount;
                        $currentBalance += $savingAmount - $oldAmount;

                        if ($savingAmount > 0) {
                            $this->setSavingBalance($saving, $clientId, $currentBalance);
                            $existingSaving->amount = $savingAmount;
                            $existingSaving->balance_after = $currentBalance;
                            $existingSaving->save();
                            $updates[] = 'Savings Updated';
                        } else {
                            $this->setSavingBalance($saving, $clientId, $currentBalance);
                            $existingSaving->delete();
                            $updates[] = 'Savings Removed';
                        }
                    } elseif ($savingAmount > 0) {
                        $currentBalance += $savingAmount;
                        $this->setSavingBalance($saving, $clientId, $currentBalance);
                        $this->createSavingCollection($clientId, $saving->id, $savingAmount, 'deposit', $currentBalance, $paymentDateTime, $user->username);
                        $updates[] = 'Saved';
                    }
                }

                // Withdrawal / return / cash.
                if ($withdrawalType !== '' && ($withdrawalAmount > 0 || $withdrawalType === 'return')) {
                    if ($loan) {
                        $loanTotal = (float) $loan->total_payable;
                        $loanRemaining = (float) $loan->remaining_balance;
                        $paid = $loanTotal - $loanRemaining;
                        $numInstallments = (int) ($loan->num_installments ?: ($weekly ? 24 : 23));
                        $instAmount = $numInstallments > 0 ? $loanTotal / $numInstallments : 0;
                        $installmentsPaid = $instAmount > 0 ? (int) floor($paid / $instAmount) : 0;

                        if (in_array($withdrawalType, ['cash', 'withdrawal'], true) && $installmentsPaid < 10) {
                            throw new RuntimeException('Withdrawal denied. Minimum 10 installments required (Paid: ' . $installmentsPaid . ').');
                        }

                        $buffer = (float) ($settings['withdrawal']['buffer_' . $withdrawalType] ?? 10) / 100;
                        $requiredSavings = (float) $loan->principal * $buffer;
                        if ($currentBalance < $requiredSavings) {
                            throw new RuntimeException('Denied. Savings below ' . ($buffer * 100) . '% of principal. Req: ₦' . number_format($requiredSavings, 2));
                        }
                    }

                    $dailyCount = SavingCollection::query()
                        ->where('client_id', $clientId)
                        ->whereDate('date', $paymentDate)
                        ->whereIn('type', ['cash', 'withdrawal', 'return'])
                        ->count();

                    if ($dailyCount >= (int) $settings['withdrawal']['max_withdrawals_per_day']) {
                        throw new RuntimeException('Daily withdrawal limit reached.');
                    }

                    $actualDeduct = $withdrawalAmount;
                    if ($withdrawalType === 'return' && $loan) {
                        $actualDeduct = min($currentBalance, (float) $loan->remaining_balance);
                    }

                    if ($actualDeduct <= 0) {
                        throw new RuntimeException('Calculated withdrawal amount is zero or insufficient savings.');
                    }

                    if ($actualDeduct > $currentBalance) {
                        throw new RuntimeException('Insufficient savings balance.');
                    }

                    $currentBalance -= $actualDeduct;
                    $this->setSavingBalance($saving, $clientId, $currentBalance);

                    if (in_array($withdrawalType, ['withdrawal', 'return'], true) && $loan) {
                        $this->setDisbursement($loan, max(0, (float) $loan->remaining_balance - $actualDeduct), $paymentDate);
                    }

                    $prefix = $withdrawalType === 'return' ? 'RTN-' : ($withdrawalType === 'cash' ? 'CSH-' : 'WTH-');
                    $notes = $newPicturePath ? 'Image: ' . basename($newPicturePath) : ($requestNotes !== '' ? $requestNotes : null);
                    if (!$notes && $withdrawalType === 'cash') {
                        $notes = $previousWithdrawalNotes;
                    }

                    $this->createSavingCollection(
                        $clientId, $saving->id, -$actualDeduct, $withdrawalType,
                        $currentBalance, $paymentDateTime, $user->username, $notes, $prefix
                    );
                    $updates[] = $existingWithdrawal ? 'Withdrawal Updated' : 'Withdrawn';
                }

                if ($saving) {
                    $this->setSavingBalance($saving, $clientId, $currentBalance);
                }

                return [
                    'success' => true,
                    'message' => $updates ? implode(', ', array_values(array_unique($updates))) : 'No edits processed',
                    'balance' => $currentBalance,
                ];
            });
        } catch (\Throwable $e) {
            if ($newPicturePath) {
                Storage::disk('public')->delete($newPicturePath);
            }
            throw $e;
        }
    }

    public function settings(): array
    {
        $collections = [
            'max_installments_per_payment' => 3,
            'min_installments_per_payment' => 1,
            'grace_period_days' => 2,
            'allow_partial_payments' => 0,
            'allow_overpayment' => 0,
        ];
        $savings = [
            'min_savings_amount' => 100,
            'max_savings_amount' => 500000,
            'allow_weekend_collection' => 0,
        ];
        $withdrawal = [
            'max_cash_withdrawal' => 50000,
            'require_image_for_cash' => 1,
            'allow_weekend_withdrawals' => 0,
            'max_withdrawals_per_day' => 1,
            'buffer_cash' => 10,
            'buffer_withdrawal' => 10,
            'buffer_return' => 10,
        ];

        if ($row = DB::table('loan_collection_settings')->first()) $collections = array_merge($collections, (array) $row);
        if ($row = DB::table('savings_settings')->first()) $savings = array_merge($savings, (array) $row);
        if ($row = DB::table('withdrawal_settings')->first()) $withdrawal = array_merge($withdrawal, (array) $row);

        $dateReadonly = (bool) (DB::table('date_control_settings')->value('date_readonly') ?? false);
        $savingsRequirement = (float) (DB::table('disbursement_settings')->value('savings_requirement_percentage') ?? 0.2);

        return [
            'collections' => $collections,
            'savings' => $savings,
            'withdrawal' => $withdrawal,
            'date_readonly' => $dateReadonly,
            'savings_requirement_percentage' => $savingsRequirement,
        ];
    }

    private function setSavingBalance(?Saving $saving, string $clientId, float $balance): void
    {
        if ($saving) {
            $saving->balance = max(0, $balance);
            $saving->updated_at = now();
            $saving->save();
        }
        $this->syncLegacyBalance($clientId, max(0, $balance));
    }

    private function syncLegacyBalance(string $clientId, float $balance): void
    {
        DB::table('saving_balances')->updateOrInsert(
            ['client_id' => $clientId],
            ['balance' => max(0, $balance), 'last_updated' => now()]
        );
    }

    private function setDisbursement(Disbursement $loan, float $remaining, string $paymentDate): void
    {
        $loan->remaining_balance = max(0, $remaining);
        $loan->status = $loan->remaining_balance <= 0.01 ? 'completed' : 'active';
        $loan->payoff_date = $loan->status === 'completed' ? $paymentDate : null;
        $loan->save();
    }

    private function adjustDisbursement(Disbursement $loan, float $amount, bool $restore, string $paymentDate): void
    {
        $remaining = (float) $loan->remaining_balance + ($restore ? $amount : -$amount);
        $this->setDisbursement($loan, $remaining, $paymentDate);
    }

    private function createLoanCollection(string $clientId, $disbursementId, float $amount, float $remaining, string $date, string $officer): void
    {
        LoanCollection::create([
            'transaction_id' => 'LCL-' . strtoupper((string) Str::uuid()),
            'client_id' => $clientId,
            'disbursement_id' => $disbursementId,
            'amount_collected' => $amount,
            'date' => $date,
            'officer' => $officer,
            'type' => 'repayment',
            'remaining_balance' => $remaining,
        ]);
    }

    private function createSavingCollection(
        string $clientId, $savingId, float $amount, string $type, float $balance,
        string $date, string $officer, ?string $notes = null, string $prefix = 'SAV-'
    ): void {
        SavingCollection::create([
            'transaction_id' => $prefix . strtoupper((string) Str::uuid()),
            'client_id' => $clientId,
            'savings_id' => $savingId,
            'amount' => $amount,
            'type' => $type,
            'date' => $date,
            'officer' => $officer,
            'balance_after' => $balance,
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }
}
