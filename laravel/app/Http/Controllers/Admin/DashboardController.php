<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Disbursement;
use App\Models\LoanCollection;
use App\Models\SavingCollection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $today = now()->toDateString();
        $month = now()->format('Y-m');

        $stats = [
            'total_users' => User::count(),
            'total_clients' => Client::count(),
            'total_branches' => DB::table('branches')->where('status', 'active')->count(),
            'active_sessions' => User::where('last_activity', '>=', now()->subMinutes(5))->count(),
            'daily_transactions' => LoanCollection::whereDate('date',$today)->count() + SavingCollection::whereDate('date',$today)->count(),
            'revenue_today' => (float) LoanCollection::whereDate('date',$today)->sum('amount_collected') + (float) SavingCollection::whereDate('date',$today)->where('type','deposit')->sum('amount'),
            'active_loans' => Disbursement::where('status','active')->where('remaining_balance','>',0)->count(),
            'monthly_collections' => (float) LoanCollection::where('date','like',$month.'%')->sum('amount_collected'),
            'total_savings' => (float) DB::table('savings')->where('status','<>','closed')->sum('balance'),
        ];

        $chart=[];
        for($m=1;$m<=12;$m++){
            $chart[$m]=(float)LoanCollection::whereYear('date',now()->year)->whereMonth('date',$m)->sum('amount_collected')
                +(float)SavingCollection::whereYear('date',now()->year)->whereMonth('date',$m)->where('type','deposit')->sum('amount');
        }

        return view('admin.dashboard',compact('stats','chart'));
    }
}
