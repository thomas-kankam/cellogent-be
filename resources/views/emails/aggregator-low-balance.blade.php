<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>SMS aggregator balance is low</title>
</head>
<body style="margin:0;padding:0;background:#e6e6e6;font-family:Arial,sans-serif;">
<table width="100%" cellspacing="0" cellpadding="0" bgcolor="#e6e6e6">
    <tr>
        <td align="center" style="padding:40px 10px;">
            <table width="100%" cellspacing="0" cellpadding="0" bgcolor="#ffffff" style="max-width:600px;border-radius:12px;">
                <tr>
                    <td align="center" bgcolor="#B91C1C" style="padding:30px 20px;">
                        <p style="margin:0;font-size:22px;font-weight:700;color:#ffffff;">{{ $brand }}</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:30px 25px;">
                        <p style="font-size:16px;color:#333;">The SMS aggregator account (<strong>{{ $balance['name'] }}</strong>) needs a top-up.</p>
                        <table width="100%" cellpadding="8" cellspacing="0" style="margin-top:16px;border:1px solid #e5e7eb;">
                            <tr><td>Aggregator credit</td><td>{{ number_format($balance['credit']) }}</td></tr>
                            <tr><td>Units held by clients</td><td>{{ number_format($balance['client_units_outstanding']) }}</td></tr>
                            <tr><td>Headroom (credit − client units)</td><td>{{ number_format($balance['headroom']) }}</td></tr>
                            <tr><td>Top up at least</td><td>{{ number_format($balance['top_up_needed']) }}</td></tr>
                            <tr><td>Alert threshold</td><td>{{ number_format($balance['low_balance_threshold']) }}</td></tr>
                            <tr><td>Checked at</td><td>{{ $balance['checked_at'] }}</td></tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
