<?php

namespace App\Http\Controllers\Co;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HistoryController extends Controller
{
    public function index()
    {
        abort_unless(strtolower((string) auth()->user()->role) === 'co', 403);
        return view('co.history');
    }

    public function data(Request $request)
    {
        abort_unless(strtolower((string) auth()->user()->role) === 'co', 403);

        $user = $request->user();
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('perPage', 20)));
        $type = strtolower((string) $request->input('type', 'all'));
        $search = trim((string) $request->input('search', ''));
        $start = $request->input('startDate');
        $end = $request->input('endDate');

        $clientIds = Client::query()->where('officer_username', $user->username)->pluck('id');

        $saving = DB::table('saving_collections')
            ->select('transaction_id', 'date', 'client_id', 'amount', 'type')
            ->whereIn('client_id', $clientIds)
            ->get()
            ->map(fn ($r) => $this->normalize($r, 'saving_tbl'));

        $loan = DB::table('loan_collections')
            ->select('transaction_id', 'date', 'client_id', 'amount_collected as amount')
            ->whereIn('client_id', $clientIds)
            ->get()
            ->map(fn ($r) => $this->normalize($r, 'collection_tbl', 'repayment'));

        $disb = DB::table('disbursements')
            ->select('id as transaction_id', 'date', 'client_id', 'principal as amount')
            ->whereIn('client_id', $clientIds)
            ->get()
            ->map(fn ($r) => $this->normalize($r, 'disbursement_tbl', 'disbursement'));

        $rows = $saving->concat($loan)->concat($disb);

        $rows = $rows->filter(function ($r) use ($type, $search, $start, $end) {
            $date = substr((string) $r->date, 0, 10);
            if ($start && $date < $start) return false;
            if ($end && $date > $end) return false;

            if ($type === 'today' && $date !== now()->toDateString()) return false;
            if ($type === 'saving' && !($r->source_cat === 'saving_tbl' && $this->isSavingDeposit($r))) return false;
            if ($type === 'withdrawal' && !($r->source_cat === 'saving_tbl' && !$this->isSavingDeposit($r))) return false;
            if ($type === 'repayment' && $r->source_cat !== 'collection_tbl') return false;
            if ($type === 'disbursement' && $r->source_cat !== 'disbursement_tbl') return false;

            if ($search !== '') {
                $needle = strtolower($search);
                return str_contains(strtolower((string) $r->client_name), $needle)
                    || str_contains(strtolower((string) $r->transaction_id), $needle)
                    || str_contains((string) $r->amount, $needle);
            }
            return true;
        })->sortByDesc('date')->values();

        $totalCount = $rows->count();
        $totalVolume = $rows->sum(fn ($r) => abs((float) $r->amount));
        $data = $rows->forPage($page, $perPage)->values()->map(fn ($r) => $this->present($r))->all();

        return response()->json([
            'success' => true,
            'data' => $data,
            'totalCount' => $totalCount,
            'totalVolume' => $totalVolume,
            'hasMore' => ($page * $perPage) < $totalCount,
        ]);
    }

    private function normalize($row, string $source, ?string $type = null)
    {
        $row->source_cat = $source;
        $row->type = $type ?? ($row->type ?? null);
        $row->client_name = Client::whereKey($row->client_id)->value('name') ?? 'Unknown Client';
        return $row;
    }

    private function isSavingDeposit($row): bool
    {
        $amount = (float) $row->amount;
        $type = strtolower((string) ($row->type ?? ''));
        return $amount >= 0 && !in_array($type, ['withdrawal','return_cash','return cash','return','adjust','cash','debit','charge','fee'], true);
    }

    private function present($row): array
    {
        $rawAmount = (float) $row->amount;
        $rawType = strtolower((string) ($row->type ?? ''));
        $source = $row->source_cat;

        if ($source === 'saving_tbl') {
            $withdrawal = !$this->isSavingDeposit($row);
            if ($withdrawal) {
                $display = str_contains($rawType, 'return') ? 'Return Cash' : (in_array($rawType, ['charge','fee','debit'], true) ? 'Fee/Charges' : 'Withdrawal');
                return ['id'=>$row->transaction_id,'date'=>$row->date,'client'=>$row->client_name,'type'=>$display,'amount'=>abs($rawAmount),'cat'=>'withdrawal','icon'=>'fa-wallet'];
            }
            return ['id'=>$row->transaction_id,'date'=>$row->date,'client'=>$row->client_name,'type'=>$rawType === 'transfer' ? 'Transfer' : 'Saving Deposit','amount'=>abs($rawAmount),'cat'=>'saving','icon'=>'fa-piggy-bank'];
        }
        if ($source === 'collection_tbl') return ['id'=>$row->transaction_id,'date'=>$row->date,'client'=>$row->client_name,'type'=>'Loan Repayment','amount'=>abs($rawAmount),'cat'=>'repayment','icon'=>'fa-money-bill-wave'];
        return ['id'=>$row->transaction_id,'date'=>$row->date,'client'=>$row->client_name,'type'=>'Loan Disbursement','amount'=>abs($rawAmount),'cat'=>'disbursement','icon'=>'fa-hand-holding-usd'];
    }
}
