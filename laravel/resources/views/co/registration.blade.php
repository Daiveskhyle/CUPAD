@extends('layouts.app')
@section('title','CO Registration')
@section('content')
<div class="co-reg">
    <div class="page-head"><div><span>CO MODULE</span><h1>Client Registration</h1><p>Register clients under your assigned union and loan plan.</p></div><a href="{{ route('co.combined') }}">Combined Collection</a></div>
    <div class="stats">
        <div><small>Total Clients</small><strong id="clients">—</strong></div>
        <div><small>Registration Fees</small><strong id="fees">—</strong></div>
        <div><small>Standard Fee</small><strong>₦{{ number_format($feeDaily) }}</strong></div>
    </div>
    <div id="msg" class="msg hidden"></div>
    <form id="form" class="card">
        @csrf
        <div class="form-head"><h2>New Client</h2><span id="fee">Select a plan</span></div>
        <div class="grid">
            <label>Full Name<input name="name" id="name" required></label>
            <label>Phone Number<input name="phone" id="phone"></label>
            <label>Branch<input value="{{ $branch->name ?? 'Unassigned' }}" readonly></label>
            <label>Union<select name="union" id="union" required><option value="">Select Union</option>@foreach($unions as $u)<option value="{{ $u }}">{{ $u }}</option>@endforeach</select></label>
            <label class="wide">Loan Plan<select name="plan_id" id="plan" required><option value="">Select Plan</option>@foreach($plans as $p)<option value="{{ $p->id }}" data-fee="{{ $p->registration_amount }}">{{ $p->duration }} {{ ucfirst($p->unit) }} · {{ ((float)$p->rate*100) }}% · ₦{{ number_format($p->registration_amount) }}</option>@endforeach</select></label>
            <label class="wide">Address<input name="address"></label>
            <label>Guarantor Name<input name="guarantor_name"></label>
            <label>Guarantor Phone<input name="guarantor_phone"></label>
        </div>
        <button id="submit" type="submit">Register Client</button>
    </form>
</div>
<style>
.co-reg{max-width:1180px;margin:auto;padding:24px}.page-head{display:flex;justify-content:space-between;align-items:end;margin-bottom:20px}.page-head span{font-size:11px;font-weight:800;letter-spacing:.12em;color:#2563eb}.page-head h1{margin:4px 0;font-size:28px}.page-head p{margin:0;color:#64748b}.page-head a{padding:10px 14px;border-radius:10px;background:#eff6ff;color:#1d4ed8;text-decoration:none;font-weight:700}.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px}.stats>div,.card{background:var(--card,#fff);border:1px solid #e2e8f0;border-radius:16px}.stats>div{padding:18px}.stats small{display:block;color:#64748b;font-weight:700}.stats strong{display:block;font-size:22px;margin-top:7px}.card{overflow:hidden}.form-head{padding:18px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between}.form-head h2{margin:0}.form-head span{color:#2563eb;font-weight:800}.grid{padding:20px;display:grid;grid-template-columns:1fr 1fr;gap:18px}label{display:grid;gap:7px;font-size:12px;font-weight:800;color:#64748b}input,select{padding:12px;border:1px solid #cbd5e1;border-radius:10px;background:transparent;color:inherit;font-size:14px}.wide{grid-column:1/-1}#submit{margin:0 20px 20px;width:calc(100% - 40px);padding:13px;border:0;border-radius:11px;background:#2563eb;color:#fff;font-weight:800;cursor:pointer}.msg{padding:12px;margin-bottom:14px;border-radius:10px;background:#fee2e2;color:#991b1b}.hidden{display:none}@media(max-width:700px){.co-reg{padding:14px}.page-head{align-items:flex-start;gap:12px;flex-direction:column}.stats{grid-template-columns:1fr}.grid{grid-template-columns:1fr}.wide{grid-column:auto}}
</style>
<script>
const fmt=n=>new Intl.NumberFormat('en-NG',{style:'currency',currency:'NGN'}).format(Number(n)||0);
const msg=(t)=>{const e=document.getElementById('msg');e.textContent=t;e.classList.remove('hidden');};
async function stats(){const r=await fetch('{{ route('co.registration.stats') }}',{headers:{Accept:'application/json'}});const j=await r.json();if(j.success){clients.textContent=j.stats.total_clients;fees.textContent=fmt(j.stats.total_registration_fees)}}
plan.onchange=()=>fee.textContent=plan.selectedOptions[0]?.dataset.fee?fmt(plan.selectedOptions[0].dataset.fee):'Select a plan';
name.onblur=async()=>{if(!name.value)return;const u=new URL('{{ route('co.registration.duplicate') }}',location.origin);u.searchParams.set('name',name.value);u.searchParams.set('union',union.value);const j=await (await fetch(u,{headers:{Accept:'application/json'}})).json();if(j.exists&&j.has_funds)msg('This active client has unsettled balances. Registration is blocked.');};
form.onsubmit=async e=>{e.preventDefault();submit.disabled=true;submit.textContent='Registering...';const r=await fetch('{{ route('co.registration.store') }}',{method:'POST',headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}',Accept:'application/json'},body:new FormData(form)});const j=await r.json();if(!j.success){msg(j.error||'Registration failed');}else{alert('Client registered: '+j.client_id);form.reset();fee.textContent='Select a plan';stats()}submit.disabled=false;submit.textContent='Register Client';};
stats();
</script>
@endsection