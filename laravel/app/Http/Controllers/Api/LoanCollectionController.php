<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\LoanCollectionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LoanCollectionController extends Controller
{
    public function __construct(private LoanCollectionService $loans) {}

    public function collect(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'exists:clients,id'],
            'installment' => ['required', 'integer', 'between:1,2'],
            'date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $client = Client::query()->whereKey($data['client_id'])->whereNull('deleted_at')->firstOrFail();
        $user = $request->user();

        $role = strtolower((string) $user->role);
        $allowed = match ($role) {
            'admin' => true,
            'co' => $client->officer_username === $user->username,
            'bm' => (string) $client->branch_id === (string) $user->branch_id,
            default => false,
        };

        abort_unless($allowed, 403, 'Client not found or access denied.');

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
}
