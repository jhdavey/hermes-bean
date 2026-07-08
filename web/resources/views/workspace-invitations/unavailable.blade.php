<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Household invite unavailable</title>
    @include('partials.utility-page-styles')
</head>
<body>
    <main class="card center">
        <h1>Invite could not be accepted</h1>
        <p>{{ $message }}</p>
        <a class="button" href="heybean://login">Open HeyBean</a>
    </main>
</body>
</html>
