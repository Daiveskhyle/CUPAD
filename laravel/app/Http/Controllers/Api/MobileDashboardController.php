<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobileDashboardController extends Controller
{
    public function stats(Request $request)
    {
        $user = $request->user();
        $username = $user->username;
        $month = now()->format('Y-m');

        $clients = DB::table('clients')
            ->where('officer_username', $username)
            ->where('status', 'active')
            ->count();

        $netSavingsMonth = (float) DB::table('saving_collections')
            ->where('officer', $username)
            ->whereRaw("DATE_FORMAT(date,'%Y-%m') = ?", [$month])
            ->selectRaw("COALESCE(SUM(CASE WHEN amount < 0 OR LOWER(type) IN ('withdrawal','return','adjust') THEN -ABS(amount) ELSE amount END),0) total")
            ->value('total');

        $monthlyDisbursed = (float) DB::table('disbursements')
            ->where('officer', $username)
            ->whereRaw("DATE_FORMAT(date,'%Y-%m') = ?", [$month])
            ->sum('principal');

        $activeLoans = DB::table('disbursements')
            ->where('officer', $username)
            ->where('status', '!=', 'completed')
            ->where('remaining_balance', '>', 0)
            ->distinct('client_id')
            ->count('client_id');

        $grandSavings = (float) DB::table('savings')
            ->join('clients', 'clients.id', '=', 'savings.client_id')
            ->where('clients.officer_username', $username)
            ->where('savings.status', '<>', 'closed')
            ->sum('savings.balance');

        $grandLoans = (float) DB::table('disbursements')
            ->join('clients', 'clients.id', '=', 'disbursements.client_id')
            ->where('clients.officer_username', $username)
            ->where('disbursements.status', '!=', 'completed')
            ->where('disbursements.remaining_balance', '>', 0)
            ->sum('disbursements.remaining_balance');

        $collectedToday = (float) DB::table('loan_collections')
            ->where('officer', $username)
            ->whereDate('date', today())
            ->sum('amount_collected');

        $collectedMonth = (float) DB::table('loan_collections')
            ->where('officer', $username)
            ->whereRaw("DATE_FORMAT(date,'%Y-%m') = ?", [$month])
            ->sum('amount_collected');

        $savingsToday = (float) DB::table('saving_collections')
            ->where('officer', $username)
            ->where('type', 'deposit')
            ->whereDate('date', today())
            ->sum('amount');

        return response()->json([
            'success' => true,
            'data' => [
                'monthly_net_savings' => $netSavingsMonth,
                'monthly_disbursed' => $monthlyDisbursed,
                'active_loans' => $activeLoans,
                'total_savings' => $grandSavings,
                'total_loans_outstanding' => $grandLoans,
                'portfolio_net' => $grandSavings - $grandLoans,
                'clients' => $clients,
                'savings_today' => $savingsToday,
                'collected_today' => $collectedToday,
                'collected_month' => $collectedMonth,
                'net_savings_month' => $netSavingsMonth,
                'outstanding' => $grandLoans,
            ],
        ]);
    }

    public function activities(Request $request)
    {
        $user = $request->user();
        $limit = min(100, max(1, (int) $request->integer('limit', 7)));

        $rows = DB::query()->fromSub(function ($q) use ($user) {
            $q->from('saving_collections as s')
                ->join('clients as c', 's.client_id', '=', 'c.id')
                ->where('s.officer', $user->username)
                ->selectRaw("'Saving' type, c.name client_name, s.amount, s.date")
                ->unionAll(
                    DB::table('saving_collections as s')
                        ->join('clients as c', 's.client_id', '=', 'c.id')
                        ->where('s.officer', $user->username)
                        ->where('s.type', 'withdrawal')
                        ->selectRaw("'Withdrawal' type, c.name client_name, s.amount, s.date")
                )
                ->unionAll(
                    DB::table('loan_collections as p')
                        ->join('clients as c', 'p.client_id', '=', 'c.id')
                        ->where('p.officer', $user->username)
                        ->selectRaw("'Payment' type, c.name client_name, p.amount_collected amount, p.date")
                );
        }, 'activities')->orderByDesc('date')->limit($limit)->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }
}
