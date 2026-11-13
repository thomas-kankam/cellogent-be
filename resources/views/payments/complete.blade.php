<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ $brand }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700|fraunces:600,700" rel="stylesheet">
    <style>
        :root {
            --bg: #f3f6f4;
            --ink: #14201a;
            --muted: #5b6b63;
            --panel: #ffffff;
            --line: #d7e0db;
            --accent: #0f6b4c;
            --accent-soft: #e4f3ec;
            --warn: #9a6700;
            --warn-soft: #fff6dd;
            --danger: #b42318;
            --danger-soft: #fef3f2;
            --neutral: #344054;
            --neutral-soft: #f2f4f7;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "DM Sans", system-ui, sans-serif;
            color: var(--ink);
            background:
                radial-gradient(1200px 500px at 10% -10%, #d9efe4 0%, transparent 55%),
                radial-gradient(900px 420px at 100% 0%, #e8eef5 0%, transparent 50%),
                var(--bg);
            display: grid;
            place-items: center;
            padding: 1.5rem;
        }

        .shell {
            width: min(100%, 440px);
            animation: rise .45s ease both;
        }

        @keyframes rise {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .brand {
            font-family: Fraunces, Georgia, serif;
            font-size: 1.15rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin-bottom: 1rem;
            color: var(--accent);
        }

        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 1.75rem 1.5rem 1.5rem;
            box-shadow: 0 18px 40px rgba(20, 32, 26, 0.06);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            font-size: .78rem;
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
            padding: .35rem .7rem;
            border-radius: 999px;
            margin-bottom: 1rem;
        }

        .tone-success { background: var(--accent-soft); color: var(--accent); }
        .tone-pending { background: var(--warn-soft); color: var(--warn); }
        .tone-failed { background: var(--danger-soft); color: var(--danger); }
        .tone-unknown { background: var(--neutral-soft); color: var(--neutral); }

        .dot {
            width: .55rem;
            height: .55rem;
            border-radius: 50%;
            background: currentColor;
            animation: pulse 1.6s ease infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: .45; transform: scale(.85); }
        }

        h1 {
            margin: 0 0 .55rem;
            font-family: Fraunces, Georgia, serif;
            font-size: 1.7rem;
            line-height: 1.15;
            letter-spacing: -0.03em;
        }

        .body {
            margin: 0;
            color: var(--muted);
            font-size: .98rem;
            line-height: 1.55;
        }

        .meta {
            margin-top: 1.35rem;
            border-top: 1px solid var(--line);
            padding-top: 1rem;
            display: grid;
            gap: .65rem;
        }

        .row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            font-size: .9rem;
        }

        .row span { color: var(--muted); }
        .row strong {
            font-weight: 600;
            text-align: right;
            word-break: break-all;
        }

        .actions {
            margin-top: 1.4rem;
            display: grid;
            gap: .65rem;
        }

        .btn {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            min-height: 2.75rem;
            padding: .7rem 1rem;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            font-size: .95rem;
            border: 1px solid transparent;
            transition: transform .15s ease, background .15s ease, border-color .15s ease;
        }

        .btn:active { transform: translateY(1px); }
        .btn-primary { background: var(--accent); color: #fff; }
        .btn-primary:hover { background: #0c573e; }
        .btn-ghost {
            background: transparent;
            color: var(--ink);
            border-color: var(--line);
        }
        .btn-ghost:hover { border-color: #b7c5bd; background: #f8fbf9; }

        .foot {
            margin-top: 1rem;
            text-align: center;
            color: var(--muted);
            font-size: .8rem;
        }
    </style>
</head>
<body>
    <main class="shell">
        <div class="brand">{{ $brand }}</div>

        <section class="card" role="status" aria-live="polite">
            <div class="badge tone-{{ $tone }}">
                <span class="dot" aria-hidden="true"></span>
                {{ strtoupper($status) }}
            </div>

            <h1>{{ $title }}</h1>
            <p class="body">{{ $body }}</p>

            @if ($reference || $amount !== null)
                <div class="meta">
                    @if ($amount !== null)
                        <div class="row">
                            <span>Amount</span>
                            <strong>{{ $currency }} {{ number_format($amount, 2) }}</strong>
                        </div>
                    @endif
                    @if ($purpose)
                        <div class="row">
                            <span>Purpose</span>
                            <strong>{{ str_replace('_', ' ', $purpose) }}</strong>
                        </div>
                    @endif
                    @if ($reference)
                        <div class="row">
                            <span>Reference</span>
                            <strong>{{ $reference }}</strong>
                        </div>
                    @endif
                </div>
            @endif

            <div class="actions">
                <a class="btn btn-primary" href="{{ $appUrl }}">Back to {{ $brand }}</a>
                @if ($reference)
                    <a class="btn btn-ghost" href="{{ $appUrl }}/payments?reference={{ urlencode($reference) }}">View payment in app</a>
                @endif
            </div>
        </section>

        <p class="foot">You can safely close this window after returning to the app.</p>
    </main>
</body>
</html>
