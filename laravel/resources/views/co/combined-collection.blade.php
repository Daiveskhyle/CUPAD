@extends('layouts.app')

@section('content')
<div class="co-page">
    <div class="co-header">
        <div>
            <div class="eyebrow">CO COLLECTION</div>
            <h1>Combined Collection</h1>
            <p>Record loan repayment, savings and withdrawal activity from one screen.</p>
        </div>
        <div class="co-meta">
            <span>{{ $user->name ?: $user->username }}</span>
            <span class="role-pill">CO</span>
        </div>
    </div>

    <div class="toolbar card">
        <label>
            <span>Union / Group</span>
            <select id="union">
                <option value="">All my clients</option>
                @foreach($unions as $union)
                    <option value="{{ $union }}">{{ $union }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span>Collection date</span>
            <input id="date" type="date" value="{{ now()->toDateString() }}" @if($settings['date_readonly']) readonly @endif>
        </label>
        <button id="reload" class="primary">Load clients</button>
    </div>

    <div id="message" class="message hidden"></div>

    <div class="summary-grid">
        <div class="summary card"><small>Loan repayment</small><strong id="totalLoan">₦0.00</strong></div>
        <div class="summary card"><small>Savings</small><strong id="totalSavings">₦0.00</strong></div>
        <div class="summary card"><small>Withdrawal</small><strong id="totalWithdrawal">₦0.00</strong></div>
        <div class="summary card highlight"><small>Net collection</small><strong id="netTotal">₦0.00</strong></div>
    </div>

    <div class="card table-card">
        <div class="table-top">
            <input id="search" type="search" placeholder="Search client name...">
            <span id="count">0 clients</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Client</th><th>Loan</th><th>Savings</th><th>Withdrawal</th><th>Balance / Outstanding</th><th>Status</th></tr></thead>
                <tbody id="rows"><tr><td colspan="6" class="empty">Select a union or load clients.</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<style>
.co-page{max-width:1500px;margin:auto;padding:24px}
.co-header{display:flex;justify-content:space-between;gap:20px;align-items:flex-end;margin-bottom:20px}
.eyebrow{font-size:.72rem;font-weight:800;letter-spacing:.12em;color:#2563eb}
.co-header h1{margin:5px 0;font-size:1.8rem}.co-header p{margin:0;color:#64748b}
.co-meta{display:flex;gap:8px;align-items:center;font-weight:700}.role-pill{padding:5px 10px;border-radius:999px;background:#dbeafe;color:#1d4ed8;font-size:.72rem}
.card{background:var(--card,#fff);border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 8px 24px rgba(15,23,42,.05)}
.toolbar{display:flex;gap:14px;align-items:end;padding:16px;margin-bottom:16px}.toolbar label{display:grid;gap:6px;min-width:220px}.toolbar span,.summary small{font-size:.75rem;font-weight:700;color:#64748b}
select,input{border:1px solid #cbd5e1;border-radius:10px;padding:10px 12px;background:transparent;color:inherit}button{border:0;border-radius:10px;padding:11px 16px;font-weight:800;cursor:pointer}.primary{background:#2563eb;color:white}
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}.summary{padding:16px}.summary strong{display:block;margin-top:7px;font-size:1.15rem}.highlight{border-color:#93c5fd}
.table-card{overflow:hidden}.table-top{padding:14px;display:flex;justify-content:space-between;gap:12px}.table-top input{max-width:320px;width:100%}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}th,td{padding:12px 14px;text-align:left;border-top:1px solid #e2e8f0;white-space:nowrap}th{font-size:.72rem;text-transform:uppercase;color:#64748b;background:#f8fafc}.empty{text-align:center;color:#64748b;padding:40px}.row-input{width:105px}.row-select{width:130px}.client-name{font-weight:800}.sub{display:block;font-size:.7rem;color:#64748b;margin-top:3px}.save-btn{background:#0f172a;color:white}.save-btn:disabled{opacity:.5;cursor:wait}.status{font-size:.75rem;font-weight:800}
.message{padding:12px 14px;border-radius:10px;margin-bottom:16px;background:#fee2e2;color:#991b1b}.hidden{display:none}
@media(max-width:900px){.summary-grid{grid-template-columns:repeat(2,1fr)}.co-header,.toolbar{align-items:stretch;flex-direction:column}.toolbar label{min-width:0}.co-page{padding:14px}}
@media(prefers-color-scheme:dark){.card{--card:#0f172a;border-color:#1e293b}th{background:#111827}th,td{border-color:#1e293b}select,input{border-color:#334155}}
</style>

<script>
const settings=@json($settings);
const fmt=n=>new Intl.NumberFormat('en-NG',{style:'currency',currency:'NGN'}).format(Number(n)||0);
let allRows=[];
const $=id=>document.getElementById(id);

async function loadRows(){
    const url=new URL('{{ route('co.combined.data') }}',location.origin);
    url.searchParams.set('union',$('union').value);
    url.searchParams.set('date',$('date').value);
    $('rows').innerHTML='<tr><td colspan="6" class="empty">Loading...</td></tr>';
    try{
        const r=await fetch(url,{headers:{Accept:'application/json'}});
        const j=await r.json();
        if(!j.success) throw new Error(j.message||'Unable to load clients');
        allRows=j.data||[]; render();
    }catch(e){showMessage(e.message)}
}
function render(){
    const q=$('search').value.toLowerCase().trim();
    const rows=allRows.filter(x=>(x.name||'').toLowerCase().includes(q));
    $('count').textContent=rows.length+' client'+(rows.length===1?'':'s');
    if(!rows.length){$('rows').innerHTML='<tr><td colspan="6" class="empty">No clients found.</td></tr>';calc();return;}
    $('rows').innerHTML=rows.map(x=>{
        const loan=x.loan, ex=x.existing||{};
        const rem=loan?Number(loan.remaining_balance):0;
        const inst=loan?Number(loan.inst_amt):0;
        const loanMax=loan?Number(settings.collections.max_installments_per_payment):0;
        const paid=loan?Number(loan.installments_paid):0;
        const opts=Array.from({length:loanMax+1},(_,i)=>'<option value="'+i+'" '+(Number(ex.loan_amt)>0&&i===Math.max(1,Math.round(Number(ex.loan_amt)/inst||1))?'selected':'')+'>'+i+'</option>').join('');
        const wopts='<option value="">None</option><option value="cash">Cash</option><option value="withdrawal">Deduct</option><option value="return">Return</option>';
        return '<tr data-id="'+x.id+'">'+
          '<td><span class="client-name">'+escapeHtml(x.name)+'</span><span class="sub">'+escapeHtml(x.id)+'</span></td>'+
          '<td>'+(loan?'<select class="row-select loan" data-inst="'+inst+'">'+opts+'</select><span class="sub">Paid '+paid+'</span>':'No active loan')+'</td>'+
          '<td><input class="row-input saving" type="number" min="0" step="1" value="'+(Number(ex.sav_amt)||0)+'"><span class="sub">Bal '+fmt(x.savings_balance)+'</span></td>'+
          '<td><select class="row-select withdrawal">'+wopts+'</select><input class="row-input wamt" type="number" min="0" step="1" value="'+(Number(ex.wth_amt)||0)+'" placeholder="Amount"></td>'+
          '<td><span class="balance">'+fmt(x.savings_balance)+'</span><span class="sub outstanding">'+fmt(rem)+'</span></td>'+
          '<td><button class="save-btn" onclick="saveRow(\''+x.id+'\',this)">Save</button> <span class="status"></span></td>'+
        '</tr>';
    }).join('');
    document.querySelectorAll('tr[data-id]').forEach(tr=>tr.querySelectorAll('input,select').forEach(el=>el.addEventListener('change',()=>calc())));
    calc();
}
function calc(){
    let l=0,s=0,w=0;
    document.querySelectorAll('tr[data-id]').forEach(tr=>{
      const loan=tr.querySelector('.loan'), sav=Number(tr.querySelector('.saving')?.value)||0, wa=Number(tr.querySelector('.wamt')?.value)||0;
      const la=loan?Number(loan.dataset.inst)*(Number(loan.value)||0):0;
      l+=la;s+=sav;w+=wa;
      const bal=allRows.find(x=>x.id===tr.dataset.id)?.savings_balance||0;
      const rem=allRows.find(x=>x.id===tr.dataset.id)?.loan?.remaining_balance||0;
      tr.querySelector('.balance').textContent=fmt(Number(bal)+s);
      tr.querySelector('.outstanding').textContent=fmt(Math.max(0,Number(rem)-la-((tr.querySelector('.withdrawal')?.value==='withdrawal'||tr.querySelector('.withdrawal')?.value==='return')?wa:0)));
    });
    $('totalLoan').textContent=fmt(l);$('totalSavings').textContent=fmt(s);$('totalWithdrawal').textContent=fmt(w);$('netTotal').textContent=fmt(l+s-w);
}
async function saveRow(id,btn){
    const tr=btn.closest('tr');btn.disabled=true;tr.querySelector('.status').textContent='Saving...';
    const fd=new FormData();
    fd.append('client_id',id);fd.append('date',$('date').value+' 00:00:00');
    fd.append('installment',tr.querySelector('.loan')?.value||0);fd.append('savings_amount',tr.querySelector('.saving')?.value||0);
    fd.append('withdrawal_type',tr.querySelector('.withdrawal')?.value||'');fd.append('withdrawal_amount',tr.querySelector('.wamt')?.value||0);
    try{
      const r=await fetch('{{ route('co.combined.save') }}',{method:'POST',headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}',Accept:'application/json'},body:fd});
      const j=await r.json();if(!j.success)throw new Error(j.message||'Save failed');
      tr.querySelector('.status').textContent='Saved';setTimeout(()=>tr.querySelector('.status').textContent='',2500);
      await loadRows();
    }catch(e){tr.querySelector('.status').textContent='Error';showMessage(e.message)}
    finally{btn.disabled=false}
}
function showMessage(msg){$('message').textContent=msg;$('message').classList.remove('hidden');setTimeout(()=>$('message').classList.add('hidden'),6000)}
function escapeHtml(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]))}
$('reload').onclick=loadRows;$('union').onchange=loadRows;$('date').onchange=loadRows;$('search').oninput=render;
loadRows();
</script>
@endsection
