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
    <strong>CUPAD</strong>
    <form method="POST" action="{{ route('logout') }}">@csrf<button>Logout</button></form>
</header>
<main class="container">
    <h1>Dashboard</h1>
    <p>Welcome, {{ auth()->user()->full_name ?? auth()->user()->username }}.</p>
    <div class="cards">
        <div class="card"><span>Clients</span><strong>{{ number_format($stats['clients']) }}</strong></div>
        <div class="card"><span>Active Loans</span><strong>{{ number_format($stats['active_loans']) }}</strong></div>
        <div class="card"><span>Savings</span><strong>₦{{ number_format($stats['savings'], 2) }}</strong></div>
    </div>
</main>
</body>
</html>
