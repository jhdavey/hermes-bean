<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reminder from Bean</title>
</head>
<body style="margin:0;background:#f6f7f4;color:#102016;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f7f4;margin:0;padding:28px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border:1px solid #e0e7df;border-radius:18px;padding:0;overflow:hidden;">
                    <tr>
                        <td style="padding:28px 30px 18px;">
                            <table role="presentation" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td style="vertical-align:middle;padding-right:12px;">
                                        <img src="{{ $logoUrl }}" width="38" height="38" alt="Bean" style="display:block;width:38px;height:38px;">
                                    </td>
                                    <td style="vertical-align:middle;">
                                        <h1 style="margin:0;color:#102016;font-size:24px;line-height:1.15;font-weight:800;">Reminder from Bean</h1>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 30px 30px;">
                            <p style="margin:0 0 18px;color:#102016;font-size:18px;line-height:1.45;font-weight:700;">{{ $reminder->title }}</p>
                            @if ($time)
                                <p style="margin:0 0 14px;color:#4b5b50;font-size:15px;line-height:1.5;">Scheduled for {{ $time }}.</p>
                            @endif
                            @if ($reminder->notes)
                                <p style="margin:0 0 18px;color:#4b5b50;font-size:15px;line-height:1.5;">{{ $reminder->notes }}</p>
                            @endif
                            <p style="margin:0;color:#4b5b50;font-size:15px;line-height:1.5;">You can dismiss or complete this reminder in HeyBean.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
