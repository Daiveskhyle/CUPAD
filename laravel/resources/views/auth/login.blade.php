<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>CUPAD Login</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
</head>
<body class="login-page">
    <main class="login-card">
        <h1>CUPAD</h1>
        <p>Sign in to continue</p>

        @if(session('error'))
            <div class="alert">{{ session('error') }}</div>
        @endif

        <form method="POST" action="{{ route('login.store') }}">
            @csrf
            <label>Username</label>
            <input name="username" value="{{ old('username') }}" required autocomplete="username">

            <label>Password</label>
            <input type="password" name="password" required autocomplete="current-password">

            <button type="submit">Sign In</button>
        </form>
    </main>
</body>
</html>
