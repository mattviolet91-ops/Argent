<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>@yield('title')</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <main class="error-page">
        <div class="card error-card">
            <p class="error-code">@yield('code')</p>
            <h1>@yield('title')</h1>
            <p>@yield('message')</p>
            <div class="action-bar">
                @yield('actions')
                <a class="btn" href="{{ url('/') }}">Retour à l'accueil</a>
            </div>
        </div>
    </main>
</body>
</html>
