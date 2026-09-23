<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Disbursement;
use App\Models\Saving;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'clients' => Client::whereNull('deleted_at')->count(),
            'active_loans' => Disbursement::where('status', 'active')->count(),
            'savings' => (float) Saving::where('status', '<>', 'closed')->sum('balance'),
        ];

        return view('dashboard', compact('stats'));
    }
}
