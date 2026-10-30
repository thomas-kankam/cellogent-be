<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Contact upload finished</title>
</head>
<body style="margin:0;padding:0;background:#e6e6e6;font-family:Arial,sans-serif;">
<table width="100%" cellspacing="0" cellpadding="0" bgcolor="#e6e6e6">
    <tr>
        <td align="center" style="padding:40px 10px;">
            <table width="100%" cellspacing="0" cellpadding="0" bgcolor="#ffffff" style="max-width:600px;border-radius:12px;">
                <tr>
                    <td align="center" bgcolor="#0F766E" style="padding:30px 20px;">
                        <p style="margin:0;font-size:22px;font-weight:700;color:#ffffff;">{{ $brand }}</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:30px 25px;">
                        <p style="font-size:16px;color:#333;">Hello <strong>{{ $displayName ?: $email }}</strong>,</p>
                        <p style="font-size:15px;color:#555;">Your contact upload for <strong>{{ $stats['group_name'] }}</strong> has finished.</p>
                        <table width="100%" cellpadding="8" cellspacing="0" style="margin-top:16px;border:1px solid #e5e7eb;">
                            <tr><td>Rows read</td><td>{{ $stats['total'] }}</td></tr>
                            <tr><td>Added</td><td>{{ $stats['inserted'] }}</td></tr>
                            <tr><td>Skipped (already in this group)</td><td>{{ $stats['skipped'] }}</td></tr>
                            <tr><td>Failed (missing or invalid phone number)</td><td>{{ $stats['failed'] }}</td></tr>
                            <tr><td>Contacts in group now</td><td>{{ $stats['count_of_contacts'] }}</td></tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
