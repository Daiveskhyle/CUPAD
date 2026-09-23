<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>CUPAD Dashboard</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
</head>
<body>
<header class="topbar">
    <div>
        <strong>CUPAD</strong>
        <small>{{ $stats['role'] }} Dashboard</small>
    </div>
    <form method="POST" action="{{ route('logout') }}">@csrf<button>Logout</button></form>
</header>
<main class="container">
    <h1>{{ $stats['role'] }} Dashboard</h1>
    <p>Welcome, {{ auth()->user()->full_name ?? auth()->user()->username }}.</p>

    <div class="cards">
        <div class="card"><span>Clients</span><strong>{{ number_format($stats['clients']) }}</strong></div>
        <div class="card"><span>Active Loans</span><strong>{{ number_format($stats['active_loans']) }}</strong></div>
        <div class="card"><span>Savings</span><strong>₦{{ number_format($stats['savings'], 2) }}</strong></div>
        <div class="card"><span>Outstanding</span><strong>₦{{ number_format($stats['outstanding'], 2) }}</strong></div>
        <div class="card"><span>Today's Loan Collections</span><strong>₦{{ number_format($stats['today_collections'], 2) }}</strong></div>
        <div class="card"><span>Today's Savings</span><strong>₦{{ number_format($stats['today_savings'], 2) }}</strong></div>
    </div>

    @if(isset($stats['users']))
        <section class="card admin-summary">
            <h2>System Overview</h2>
            <p>Active Users: <strong>{{ number_format($stats['users']) }}</strong></p>
            <p>Active Branches: <strong>{{ number_format($stats['branches']) }}</strong></p>
            <p>Online Sessions: <strong>{{ number_format($stats['active_sessions']) }}</strong></p>
        </section>
    @endif
</main>
</body>
</html>
