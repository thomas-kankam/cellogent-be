<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Welcome to {{ $brand }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
</head>

<body style="margin: 0; padding: 0; background-color: #e6e6e6; font-family: 'Poppins', Arial, sans-serif;">
    <table width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#e6e6e6">
        <tr>
            <td align="center" style="padding: 40px 10px;">
                <table width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#ffffff"
                    style="max-width: 600px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);">
                    <tr>
                        <td align="center" bgcolor="#0F766E" style="padding: 30px 20px;">
                            <p style="margin: 0; font-size: 22px; font-weight: 700; color: #ffffff;">{{ $brand }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 30px 25px;">
                            <p style="font-size: 16px; color: #333;">
                                Hello
                                <strong style="color: #0F766E;">{{ $displayName ?: $email }}</strong>,
                            </p>
                            <p style="font-size: 15px; color: #555; line-height: 1.6;">
                                @if ($purpose === 'admin_onboarded')
                                    An administrator created a {{ $brand }} admin account for you. Use the OTP sent in a separate email to sign in.
                                @else
                                    Your {{ $brand }} client account is ready. Use the OTP sent in a separate email to verify and sign in.
                                @endif
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>
