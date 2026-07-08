<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Password reset complete</title>
    <script>
        window.setTimeout(function () {
            window.location.href = 'heybean://login';
        }, 800);
    </script>
    @include('partials.utility-page-styles')
</head>
<body>
    <main class="card center">
        <h1>Your password has been reset</h1>
        <p>You can now sign in to HeyBean with your new password.</p>
        <a class="button" href="heybean://login">Back to app login</a>
        <a class="button secondary" href="/">Open HeyBean website</a>
    </main>
</body>
</html>
