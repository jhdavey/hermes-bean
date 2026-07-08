<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Household invite accepted</title>
    <script>
        window.setTimeout(function () {
            window.location.href = 'heybean://login';
        }, 800);
    </script>
    @include('partials.utility-page-styles')
</head>
<body>
    <main class="card center">
        <h1>You're in {{ $workspace?->name ?? 'the household' }}</h1>
        <p>The household has been added to your HeyBean workspace settings.</p>
        <a class="button" href="heybean://login">Open HeyBean</a>
        <a class="button secondary" href="/">Open HeyBean website</a>
    </main>
</body>
</html>
