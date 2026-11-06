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
                            <p style="margin: 8px 0 0; font-size: 12px; letter-spacing: 2px; color: #ccfbf1; text-transform: uppercase;">
                                Welcome aboard
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 30px 25px;">
                            <p style="font-size: 16px; color: #333; margin-top: 0;">
                                Hello
                                <strong style="color: #0F766E;">{{ $displayName ?: $email }}</strong>,
                            </p>

                            @if ($purpose === 'client_onboarded')
                                <p style="font-size: 15px; color: #555; line-height: 1.7;">
                                    An administrator has created your {{ $brand }} client account
                                    @if ($accountType === 'business' && $businessName)
                                        for <strong>{{ $businessName }}</strong>
                                    @elseif ($accountType)
                                        ({{ $accountType }})
                                    @endif
                                    using this email address:
                                    <strong style="color: #0F766E;">{{ $email }}</strong>.
                                </p>

                                <p style="font-size: 15px; color: #555; line-height: 1.7;">
                                    You can send SMS, manage contacts, top up your wallet, and buy SMS plans from your dashboard.
                                    Sign-in is passwordless — when you are ready, open the login page and request a one-time code to your email.
                                </p>

                                <table width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 28px 0;">
                                    <tr>
                                        <td align="center">
                                            <a href="{{ $loginUrl }}"
                                                style="display: inline-block; background-color: #0F766E; color: #ffffff; text-decoration: none; font-weight: 600; font-size: 15px; padding: 14px 28px; border-radius: 8px;">
                                                Go to login
                                            </a>
                                        </td>
                                    </tr>
                                </table>

                                <p style="font-size: 13px; color: #777; line-height: 1.6; margin-bottom: 0;">
                                    First time signing in? Request a <strong>registration</strong> OTP on the login page.
                                    After that you will get a short trial pack and full access. We will not email a code until you ask for one.
                                </p>
                            @elseif ($purpose === 'admin_onboarded')
                                <p style="font-size: 15px; color: #555; line-height: 1.7;">
                                    An administrator created a {{ $brand }} admin account for you
                                    (<strong style="color: #0F766E;">{{ $email }}</strong>).
                                    Sign-in is passwordless — when you are ready, open the admin login page and request a one-time code to your email.
                                </p>

                                <table width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 28px 0;">
                                    <tr>
                                        <td align="center">
                                            <a href="{{ $loginUrl }}"
                                                style="display: inline-block; background-color: #0F766E; color: #ffffff; text-decoration: none; font-weight: 600; font-size: 15px; padding: 14px 28px; border-radius: 8px;">
                                                Go to admin login
                                            </a>
                                        </td>
                                    </tr>
                                </table>

                                <p style="font-size: 13px; color: #777; line-height: 1.6; margin-bottom: 0;">
                                    We will not email a login code until you request one on the admin login page.
                                </p>
                            @else
                                <p style="font-size: 15px; color: #555; line-height: 1.6;">
                                    Welcome to {{ $brand }}. Your account is ready.
                                </p>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td bgcolor="#f8fafc" style="padding: 18px 25px; border-top: 1px solid #eef2f7;">
                            <p style="margin: 0; font-size: 12px; color: #94a3b8; text-align: center; line-height: 1.5;">
                                If the button does not work, copy this link into your browser:<br />
                                <a href="{{ $loginUrl }}" style="color: #0F766E; word-break: break-all;">{{ $loginUrl }}</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>
