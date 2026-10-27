<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>
        @if ($purpose === 'registration')
            Verify Your Account
        @else
            Login Verification
        @endif
    </title>
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
                            <p style="margin: 0; font-size: 22px; font-weight: 700; color: #ffffff; letter-spacing: 1px;">
                                {{ $brand }}
                            </p>
                            <p style="margin: 8px 0 0; font-size: 12px; letter-spacing: 2px; color: #ccfbf1; text-transform: uppercase;">
                                Passwordless access
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 30px 25px; background-color: #fdfdfd;">
                            <table width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#ffffff"
                                style="border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); margin-bottom: 25px;">
                                <tr>
                                    <td style="padding: 20px 25px;">
                                        <p style="font-size: 16px; color: #333333; margin-bottom: 12px;">
                                            Hello
                                            <span
                                                style="display: inline-block; background-color: #f0fdfa; padding: 6px 10px; border-radius: 6px; color: #0F766E; font-weight: 600; font-size: 16px; border: 1px solid #99f6e4;">
                                                {{ $displayName ?: $email }}
                                            </span>,
                                        </p>
                                        <p style="font-size: 15px; color: #555555; line-height: 1.6; margin: 0;">
                                            @if ($purpose === 'registration')
                                                Welcome to {{ $brand }}. Use the verification code below to complete your account setup.
                                            @else
                                                Use the verification code below to securely sign in to your {{ $brand }} account.
                                            @endif
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <table width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#f0fdfa"
                                style="border: 1px dashed #0F766E; border-radius: 10px; margin-bottom: 20px;">
                                <tr>
                                    <td align="center" style="padding: 24px 20px;">
                                        <p style="font-size: 14px; color: #0F766E; margin: 0 0 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px;">
                                            Your Verification Code
                                        </p>
                                        <p style="font-size: 34px; color: #0F766E; margin: 0; font-weight: 700; letter-spacing: 8px;">
                                            {{ $otp }}
                                        </p>
                                        <p style="font-size: 13px; color: #0f766e; margin: 12px 0 0;">
                                            This code expires in {{ $expires }} minutes.
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            @if (!empty($loginUrl))
                                <table width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#ffffff"
                                    style="border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); margin-bottom: 20px;">
                                    <tr>
                                        <td align="center" style="padding: 20px;">
                                            <a href="{{ $loginUrl }}"
                                                style="display: inline-block; background-color: #0F766E; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 8px; font-size: 14px; font-weight: 600;">
                                                Continue to Login
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            <table width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td align="center" style="padding-bottom: 20px;">
                                        <p style="font-size: 13px; color: #d97706; text-align: center; font-weight: 500; margin: 0;">
                                            Do not share this code with anyone. {{ $brand }} will never ask for it by phone or email.
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <table width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td align="center">
                                        <p style="font-size: 14px; color: #888; text-align: center; margin: 0;">
                                            If you did not request this verification, please ignore this email or contact support.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>
