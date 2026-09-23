<?php

namespace App\Http\Controllers\Bm;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private function branch(Request $request): int
    {
        abort_unless(strtolower((string) $request->user()->role) === 'bm', 403);
        $branch = (int) $request->user()->branch_id;
        abort_unless($branch > 0, 403, 'No branch assigned.');
        return $branch;
    }

    public function index(Request $request)
    {
        $branchId = $this->branch($request);
        $user = $request->user();

        $profile = DB::table('users as u')
            ->leftJoin('branches as b', 'u.branch_id', '=', 'b.id')
            ->leftJoin('areas as a', 'u.area_id', '=', 'a.id')
            ->leftJoin('zones as z', 'u.zone_id', '=', 'z.id')
            ->where('u.id', $user->id)
            ->first(['u.full_name','u.profile_pic','b.name as branch_name','a.name as area_name','z.name as zone_name']);

        $clientIds = DB::table('clients')->where('branch_id', $branchId)->where('status', 'active')->pluck('id');

        $monthlySavings = (float) DB::table('saving_collections as s')
            ->join('clients as c', 's.client_id', '=', 'c.id')
            ->where('c.branch_id', $branchId)->whereYear('s.date', now()->year)->whereMonth('s.date', now()->month)
            ->selectRaw("COALESCE(SUM(CASE WHEN s.amount < 0 OR LOWER(COALESCE(s.type,'')) IN ('withdrawal','return','adjust') THEN -ABS(s.amount) ELSE s.amount END),0) v")
            ->value('v');

        $monthlyDisbursed = (float) DB::table('disbursements as d')
            ->join('clients as c', 'd.client_id', '=', 'c.id')
            ->where('c.branch_id', $branchId)->whereYear('d.date', now()->year)->whereMonth('d.date', now()->month)
            ->sum('d.principal');

        $activeLoan = DB::table('disbursements')->whereIn('client_id', $clientIds)->where('remaining_balance','>',0)
            ->selectRaw('COUNT(*) loan_count, COALESCE(SUM(remaining_balance),0) outstanding')->first();

        $stats = [
            'clients' => $clientIds->count(),
            'active_loans' => (int) ($activeLoan->loan_count ?? 0),
            'outstanding' => (float) ($activeLoan->outstanding ?? 0),
            'monthly_savings' => $monthlySavings,
            'monthly_disbursed' => $monthlyDisbursed,
            'today_collections' => (float) DB::table('loan_collections')->whereIn('client_id',$clientIds)->whereDate('date',today())->sum('amount_collected'),
            'today_savings' => (float) DB::table('saving_collections')->whereIn('client_id',$clientIds)->whereDate('date',today())->where('amount','>',0)->sum('amount'),
        ];

        $coPerformances = DB::table('users')->where('branch_id',$branchId)->where('role','co')->where('status','active')
            ->select('username','full_name')->get()->map(function($co) use ($branchId) {
                $co->active_clients = DB::table('clients')->where('branch_id',$branchId)->where('officer_username',$co->username)->where('status','active')->count();
                $co->monthly_net_savings = (float) DB::table('saving_collections as s')->join('clients as c','s.client_id','=','c.id')
                    ->where('c.branch_id',$branchId)->where('s.officer',$co->username)->whereYear('s.date',now()->year)->whereMonth('s.date',now()->month)
                    ->selectRaw("COALESCE(SUM(CASE WHEN s.amount < 0 OR LOWER(COALESCE(s.type,'')) IN ('withdrawal','return','adjust') THEN -ABS(s.amount) ELSE s.amount END),0) v")->value('v');
                $co->monthly_collections = (float) DB::table('loan_collections as l')->join('clients as c','l.client_id','=','c.id')
                    ->where('c.branch_id',$branchId)->where('l.officer',$co->username)->whereYear('l.date',now()->year)->whereMonth('l.date',now()->month)->sum('l.amount_collected');
                $co->outstanding_portfolio = (float) DB::table('disbursements as d')->join('clients as c','d.client_id','=','c.id')
                    ->where('c.branch_id',$branchId)->where('d.officer',$co->username)->where('d.remaining_balance','>',0)->sum('d.remaining_balance');
                return $co;
            })->sortByDesc('monthly_collections')->values();

        $activities = $this->activities($branchId, 'all', 1, 10)['items'];
        $notifications = DB::table('notifications')->where('user',$user->username)->where('is_read',0)->latest('created_at')->limit(10)->get();

        return view('bm.dashboard', compact('profile','stats','coPerformances','activities','notifications'));
    }

    public function activities(Request $request)
    {
        $branchId = $this->branch($request);
        $items = $this->activities($branchId, $request->input('filter','all'), max(1,(int)$request->input('page',1)), min(50,max(1,(int)$request->input('limit',10))), trim((string)$request->input('q','')));
        return response()->json(['success'=>true] + $items);
    }

    public function notifications(Request $request)
    {
        $this->branch($request);
        $rows = DB::table('notifications')->where('user',$request->user()->username)
            ->when($request->boolean('show_read'),fn($q)=>$q,fn($q)=>$q->where('is_read',0))
            ->latest('created_at')->limit(20)->get(['id','message','is_read','created_at']);
        return response()->json(['success'=>true,'notifications'=>$rows]);
    }

    public function markNotificationsRead(Request $request)
    {
        $this->branch($request);
        DB::table('notifications')->where('user',$request->user()->username)->where('is_read',0)->update(['is_read'=>1]);
        return response()->json(['success'=>true]);
    }

    private function activities(int $branchId, string $filter, int $page, int $limit, string $search = ''): array
    {
        $co = DB::table('users')->where('branch_id',$branchId)->where('role','co')->where('status','active')->pluck('username');
        if ($co->isEmpty()) return ['items'=>[],'has_more'=>false,'current_page'=>$page];

        $saving = DB::table('saving_collections as s')->join('clients as c','s.client_id','=','c.id')
            ->where('c.branch_id',$branchId)->whereIn('s.officer',$co)
            ->selectRaw("CASE WHEN s.amount < 0 OR LOWER(COALESCE(s.type,'')) IN ('withdrawal','return') THEN 'Withdrawal' ELSE 'Saving' END type,c.name client_name,ABS(s.amount) amount,s.date,s.officer,s.transaction_id activity_id");
        $payment = DB::table('loan_collections as p')->join('clients as c','p.client_id','=','c.id')
            ->where('c.branch_id',$branchId)->whereIn('p.officer',$co)
            ->selectRaw("'Payment' type,c.name client_name,p.amount_collected amount,p.date,p.officer,p.transaction_id activity_id");
        $disb = DB::table('disbursements as d')->join('clients as c','d.client_id','=','c.id')
            ->where('c.branch_id',$branchId)->whereIn('d.officer',$co)
            ->selectRaw("'Disbursement' type,c.name client_name,d.principal amount,COALESCE(d.created_at,d.date) date,d.officer,d.id activity_id");
        $reg = DB::table('registrations as r')->join('clients as c','r.client_id','=','c.id')
            ->where('c.branch_id',$branchId)->whereIn('r.officer',$co)
            ->selectRaw("'Registration' type,COALESCE(r.client_name,c.name) client_name,r.amount,r.date,r.officer,r.id activity_id");

        $union = $saving->unionAll($payment)->unionAll($disb)->unionAll($reg);
        $q = DB::query()->fromSub($union,'activities');
        $map=['saving'=>'Saving','withdrawal'=>'Withdrawal','payment'=>'Payment','disbursement'=>'Disbursement','registration'=>'Registration'];
        if (isset($map[$filter])) $q->where('type',$map[$filter]);
        if ($search !== '') $q->where(fn($x)=>$x->where('client_name','like',"%$search%")->orWhere('type','like',"%$search%")->orWhere('officer','like',"%$search%"));
        $rows=$q->orderByDesc('date')->orderByDesc('activity_id')->forPage($page,$limit)->get();
        $items=$rows->map(fn($x)=>['type'=>$x->type,'client_name'=>$x->client_name,'amount'=>(float)$x->amount,'date'=>$x->date,'officer'=>$x->officer])->values()->all();
        return ['items'=>$items,'has_more'=>count($items)>=$limit,'current_page'=>$page];
    }
}
