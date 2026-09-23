<?php

namespace App\Http\Controllers\Co;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegistrationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless(strtolower((string) $user->role) === 'co', 403);

        $branch = DB::table('branches')->where('id', $user->branch_id)->first();
        $unions = collect()
            ->merge(DB::table('assignments')->where('co', $user->username)->where('status', 'active')->pluck('union'))
            ->merge(Client::where('officer_username', $user->username)->whereNotNull('union')->pluck('union'))
            ->filter()->unique()->sort()->values();

        $plans = DB::table('loan_plans')->orderBy('unit')->orderBy('duration')->get();
        $settings = DB::table('app_settings')->first();

        return view('co.registration', [
            'user' => $user,
            'branch' => $branch,
            'unions' => $unions,
            'plans' => $plans,
            'feeDaily' => (float) ($settings->client_registration_fee ?? 1000),
        ]);
    }

    public function stats(Request $request)
    {
        $user = $request->user();
        abort_unless(strtolower((string) $user->role) === 'co', 403);

        return response()->json([
            'success' => true,
            'stats' => [
                'total_clients' => Client::where('officer_username', $user->username)->where('status', 'active')->count(),
                'total_registration_fees' => (float) DB::table('registrations')->where('officer', $user->username)->sum('amount'),
            ],
        ]);
    }

    public function checkDuplicate(Request $request)
    {
        $user = $request->user();
        abort_unless(strtolower((string) $user->role) === 'co', 403);

        $name = trim((string) $request->input('name'));
        $union = trim((string) $request->input('union'));

        if ($name === '') {
            return response()->json(['success' => true, 'exists' => false, 'has_funds' => false]);
        }

        $client = Client::where('name', $name)
            ->where('officer_username', $user->username)
            ->where('status', 'active')
            ->first();

        if (!$client || ($union !== '' && $client->union !== $union)) {
            return response()->json(['success' => true, 'exists' => false, 'has_funds' => false]);
        }

        $savings = (float) DB::table('saving_balances')->where('client_id', $client->id)->value('balance');
        $loan = (float) DB::table('disbursements')->where('client_id', $client->id)
            ->where('status', '<>', 'completed')->where('remaining_balance', '>', 0)->sum('remaining_balance');

        return response()->json([
            'success' => true,
            'exists' => true,
            'has_funds' => $savings > 0 || $loan > 0,
            'details' => ['union' => $client->union, 'savings' => $savings, 'loan' => $loan],
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless(strtolower((string) $user->role) === 'co', 403);

        $data = $request->validate([
            'name' => ['required','string','max:255'],
            'phone' => ['nullable','string','max:50'],
            'address' => ['nullable','string','max:500'],
            'union' => ['required','string','max:255'],
            'plan_id' => ['required'],
            'guarantor_name' => ['nullable','string','max:255'],
            'guarantor_phone' => ['nullable','string','max:50'],
        ]);

        $allowedUnion = DB::table('assignments')->where('co', $user->username)->where('status', 'active')->where('union', $data['union'])->exists()
            || Client::where('officer_username', $user->username)->where('union', $data['union'])->exists();

        abort_unless($allowedUnion, 422, 'Invalid Union');

        $plan = DB::table('loan_plans')->where('id', $data['plan_id'])->first();
        abort_unless($plan, 422, 'Plan not found');

        $branchId = $user->branch_id;
        abort_unless($branchId, 422, 'A branch is required');

        $name = ucwords(strtolower(trim($data['name'])));
        $phone = preg_replace('/[^0-9]/', '', (string) ($data['phone'] ?? ''));
        if ($phone && strlen($phone) === 10 && $phone[0] !== '0') $phone = '0'.$phone;

        try {
            $client = DB::transaction(function () use ($data, $user, $plan, $branchId, $name, $phone) {
                $existing = Client::where('name', $name)
                    ->where('officer_username', $user->username)
                    ->where('union', $data['union'])
                    ->where('status', 'active')
                    ->lockForUpdate()->first();

                if ($existing) {
                    $savings = (float) DB::table('saving_balances')->where('client_id', $existing->id)->value('balance');
                    $loan = (float) DB::table('disbursements')->where('client_id', $existing->id)
                        ->where('status', '<>', 'completed')->where('remaining_balance', '>', 0)->sum('remaining_balance');

                    if ($savings > 0 || $loan > 0) {
                        throw new \RuntimeException('Blocked: Client has unsettled balances (Savings: ₦'.number_format($savings,2).', Loan: ₦'.number_format($loan,2).').');
                    }

                    $existing->status = 'inactive';
                    $existing->save();
                }

                $id = 'C'.now()->format('ymd').strtoupper(Str::random(8));
                $type = $plan->duration.' '.ucfirst($plan->unit);
                $client = Client::create([
                    'id' => $id,
                    'name' => $name,
                    'phone' => $phone,
                    'address' => $data['address'] ?? null,
                    'branch_id' => $branchId,
                    'union' => $data['union'],
                    'client_type' => $type,
                    'plan_id' => $data['plan_id'],
                    'officer_username' => $user->username,
                    'guarantor_name' => $data['guarantor_name'] ?? null,
                    'guarantor_phone' => $data['guarantor_phone'] ?? null,
                    'date_registered' => now()->toDateString(),
                    'status' => 'active',
                ]);

                DB::table('saving_balances')->updateOrInsert(
                    ['client_id' => $id],
                    ['balance' => 0, 'last_updated' => now()]
                );

                DB::table('registrations')->insert([
                    'client_id' => $id,
                    'client_name' => $name,
                    'amount' => (float) $plan->registration_amount,
                    'union' => $data['union'],
                    'officer' => $user->username,
                    'date' => now(),
                ]);

                return $client;
            });

            return response()->json(['success' => true, 'client_id' => $client->id, 'message' => 'Client Registered Successfully!'], 201);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
