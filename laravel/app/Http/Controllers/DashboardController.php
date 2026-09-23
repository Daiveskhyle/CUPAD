<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $user = request()->user();
        $role = strtolower((string) $user->role);

        $clientQuery = DB::table('clients')->whereNull('deleted_at')->where('status', 'active');

        match ($role) {
            'co' => $clientQuery->where('officer_username', $user->username),
            'bm' => $clientQuery->where('branch_id', $user->branch_id),
            'am' => $clientQuery->whereIn('branch_id', DB::table('branches')->where('area_id', $user->area_id)->pluck('id')),
            'zm', 'dzm', 'tm' => $clientQuery->whereIn('branch_id', DB::table('branches')->where(function ($q) use ($user) {
                $q->where('zone_id', $user->zone_id)
                  ->orWhere('area_id', $user->area_id);
            })->pluck('id')),
            default => null,
        };

        $clientIds = (clone $clientQuery)->pluck('id');

        $stats = [
            'role' => strtoupper($role),
            'clients' => $clientIds->count(),
            'active_loans' => DB::table('disbursements')->whereIn('client_id', $clientIds)->where('status', 'active')->where('remaining_balance', '>', 0)->count(),
            'savings' => (float) DB::table('savings')->whereIn('client_id', $clientIds)->where('status', '<>', 'closed')->sum('balance'),
            'outstanding' => (float) DB::table('disbursements')->whereIn('client_id', $clientIds)->where('status', '!=', 'completed')->where('remaining_balance', '>', 0)->sum('remaining_balance'),
            'today_collections' => (float) DB::table('loan_collections')->whereIn('client_id', $clientIds)->whereDate('date', today())->sum('amount_collected'),
            'today_savings' => (float) DB::table('saving_collections')->whereIn('client_id', $clientIds)->where('type', 'deposit')->whereDate('date', today())->sum('amount'),
        ];

        if ($role === 'admin') {
            $stats['users'] = DB::table('users')->where('status', 'active')->count();
            $stats['branches'] = DB::table('branches')->where('status', 'active')->count();
            $stats['active_sessions'] = DB::table('users')->where('is_online', 1)->count();
            $stats['clients'] = DB::table('clients')->whereNull('deleted_at')->where('status', 'active')->count();
            $stats['savings'] = (float) DB::table('savings')->where('status', '<>', 'closed')->sum('balance');
            $stats['outstanding'] = (float) DB::table('disbursements')->where('status', '!=', 'completed')->where('remaining_balance', '>', 0)->sum('remaining_balance');
            $stats['active_loans'] = DB::table('disbursements')->where('status', 'active')->where('remaining_balance', '>', 0)->count();
        }

        return view('dashboard', compact('stats'));
    }
}
