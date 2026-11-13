<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $catalog['title'] }} · {{ $brand }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700|ibm-plex-mono:400,500|fraunces:600,700" rel="stylesheet">
    <style>
        :root {
            --bg: #f4f7f5;
            --panel: #ffffff;
            --ink: #132019;
            --muted: #5d6f66;
            --line: #d5e0da;
            --accent: #0f6b4c;
            --get: #0b6e4f;
            --post: #1d4ed8;
            --put: #b45309;
            --delete: #b42318;
            --sidebar: 280px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "DM Sans", system-ui, sans-serif;
            color: var(--ink);
            background:
                radial-gradient(900px 420px at 0% 0%, #dcefe6 0%, transparent 55%),
                radial-gradient(700px 360px at 100% 0%, #e7eef7 0%, transparent 45%),
                var(--bg);
        }
        a { color: inherit; text-decoration: none; }
        .layout { display: grid; grid-template-columns: var(--sidebar) 1fr; min-height: 100vh; }
        .sidebar {
            position: sticky; top: 0; height: 100vh; overflow: auto;
            border-right: 1px solid var(--line); background: rgba(255,255,255,.82); backdrop-filter: blur(10px);
            padding: 1.1rem .9rem 2rem;
        }
        .brand { font-family: Fraunces, Georgia, serif; font-weight: 700; font-size: 1.15rem; color: var(--accent); margin-bottom: .35rem; }
        .base { font-size: .78rem; color: var(--muted); word-break: break-all; margin-bottom: .9rem; }
        .search {
            width: 100%; border: 1px solid var(--line); border-radius: 10px; padding: .65rem .75rem;
            font: inherit; margin-bottom: 1rem; background: #fff;
        }
        .nav-section { margin-bottom: 1rem; }
        .nav-section h3 { margin: 0 0 .35rem; font-size: .72rem; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
        .nav-group { margin: .35rem 0 .2rem .1rem; font-size: .8rem; font-weight: 600; color: #31443b; }
        .nav-link {
            display: block; padding: .28rem .45rem; border-radius: 8px; font-size: .82rem; color: var(--muted);
        }
        .nav-link:hover, .nav-link.active { background: #e7f3ec; color: var(--accent); }
        .main { padding: 1.5rem 1.6rem 3rem; max-width: 980px; }
        .hero h1 { font-family: Fraunces, Georgia, serif; font-size: 2rem; margin: 0 0 .5rem; letter-spacing: -.03em; }
        .hero p { color: var(--muted); line-height: 1.55; margin: 0 0 1rem; max-width: 62ch; }
        .callouts { display: grid; gap: .55rem; margin-bottom: 1.5rem; }
        .callout {
            background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: .75rem .9rem;
            font-size: .9rem; color: #355045;
        }
        .conventions {
            background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 1rem 1.1rem; margin-bottom: 1.75rem;
        }
        .conventions h2 { margin: 0 0 .6rem; font-size: 1.05rem; }
        .kv { display: grid; grid-template-columns: 140px 1fr; gap: .35rem .75rem; font-size: .88rem; }
        .kv span { color: var(--muted); }
        .kv code, pre, .code {
            font-family: "IBM Plex Mono", ui-monospace, monospace; font-size: .78rem;
        }
        .section { margin-top: 2rem; scroll-margin-top: 1rem; }
        .section > h2 { font-family: Fraunces, Georgia, serif; font-size: 1.55rem; margin: 0 0 .35rem; }
        .section > .blurb { color: var(--muted); margin: 0 0 1rem; }
        .group { margin: 1.4rem 0 1.8rem; scroll-margin-top: 1rem; }
        .group > h3 { margin: 0 0 .8rem; font-size: 1.05rem; }
        .endpoint {
            background: var(--panel); border: 1px solid var(--line); border-radius: 14px;
            margin-bottom: .9rem; overflow: hidden; scroll-margin-top: 1rem;
        }
        .endpoint-head {
            display: flex; flex-wrap: wrap; gap: .5rem .75rem; align-items: center;
            padding: .85rem 1rem; border-bottom: 1px solid var(--line); background: #fbfdfc;
        }
        .method {
            font-family: "IBM Plex Mono", monospace; font-size: .72rem; font-weight: 700;
            letter-spacing: .04em; padding: .28rem .5rem; border-radius: 7px; color: #fff;
        }
        .method.GET { background: var(--get); }
        .method.POST { background: var(--post); }
        .method.PUT, .method.PATCH { background: var(--put); }
        .method.DELETE { background: var(--delete); }
        .path { font-family: "IBM Plex Mono", monospace; font-size: .86rem; font-weight: 500; }
        .pill {
            font-size: .72rem; font-weight: 600; padding: .2rem .45rem; border-radius: 999px;
            background: #eef2f0; color: #40574c;
        }
        .endpoint-body { padding: .9rem 1rem 1.05rem; }
        .summary { margin: 0 0 .75rem; color: #3d5349; font-size: .92rem; line-height: 1.45; }
        .types { width: 100%; border-collapse: collapse; font-size: .82rem; margin: 0 0 1rem; }
        .types th, .types td { text-align: left; padding: .4rem .35rem; border-bottom: 1px solid var(--line); vertical-align: top; }
        .types th { color: var(--muted); font-weight: 600; }
        .scenarios { display: grid; gap: .75rem; }
        .scenario {
            border: 1px solid var(--line); border-radius: 12px; padding: .75rem .8rem; background: #fcfefd;
        }
        .scenario-top { display: flex; flex-wrap: wrap; gap: .45rem; align-items: center; margin-bottom: .35rem; }
        .scenario h4 { margin: 0; font-size: .95rem; }
        .badge {
            font-size: .68rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
            padding: .18rem .4rem; border-radius: 999px;
        }
        .badge.live { background: #e4f3ec; color: #0f6b4c; }
        .badge.example { background: #fff6dd; color: #9a6700; }
        .badge.documented { background: #eef2f7; color: #344054; }
        .explain { margin: 0 0 .55rem; color: var(--muted); font-size: .86rem; line-height: 1.45; }
        .note { margin: .35rem 0 .55rem; font-size: .8rem; color: #9a6700; background: #fff8e8; border-radius: 8px; padding: .45rem .55rem; }
        .panels { display: grid; grid-template-columns: 1fr 1fr; gap: .65rem; }
        @media (max-width: 980px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { position: relative; height: auto; }
            .panels { grid-template-columns: 1fr; }
        }
        .panel-label {
            display: flex; justify-content: space-between; align-items: center;
            font-size: .72rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); margin-bottom: .3rem;
        }
        .copy {
            border: 1px solid var(--line); background: #fff; border-radius: 7px; padding: .15rem .4rem;
            font-size: .68rem; cursor: pointer; font-weight: 600;
        }
        pre {
            margin: 0; padding: .7rem .75rem; background: #132019; color: #e7f2ec; border-radius: 10px;
            overflow: auto; max-height: 360px; line-height: 1.45; white-space: pre-wrap; word-break: break-word;
        }
        .http { font-size: .75rem; color: var(--muted); margin-top: .35rem; }
        .hidden { display: none !important; }
    </style>
</head>
<body>
@php
    $json = fn ($v) => $v === null || $v === '' ? null : json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
@endphp
<div class="layout">
    <aside class="sidebar">
        <div class="brand">{{ $brand }} Docs</div>
        <div class="base">{{ $baseUrl }}</div>
        <input class="search" id="search" type="search" placeholder="Search endpoints, fields…">
        @foreach ($catalog['sections'] as $section)
            <div class="nav-section" data-nav-section>
                <h3>{{ $section['title'] }}</h3>
                @foreach ($section['groups'] as $group)
                    <div class="nav-group">{{ $group['title'] }}</div>
                    @foreach ($group['endpoints'] as $endpoint)
                        @php $eid = $section['id'].'-'.md5($endpoint['method'].$endpoint['path']); @endphp
                        <a class="nav-link" href="#{{ $eid }}" data-nav>{{ $endpoint['method'] }} {{ $endpoint['path'] }}</a>
                    @endforeach
                @endforeach
            </div>
        @endforeach
    </aside>

    <main class="main">
        <header class="hero">
            <h1>{{ $catalog['title'] }}</h1>
            <p>{{ $catalog['intro'] }}</p>
            <div class="callouts">
                @foreach ($catalog['conventions']['notes'] as $note)
                    <div class="callout">{{ $note }}</div>
                @endforeach
            </div>
        </header>

        <section class="conventions" id="conventions">
            <h2>Conventions</h2>
            <div class="kv">
                <span>Base</span><code>{{ $baseUrl }}</code>
                <span>Client auth</span><code>{{ $catalog['conventions']['auth_client'] }}</code>
                <span>Admin auth</span><code>{{ $catalog['conventions']['auth_admin'] }}</code>
                <span>Content-Type</span><code>{{ $catalog['conventions']['content_type'] }}</code>
            </div>
            <p style="margin: .9rem 0 .35rem; font-size:.88rem; color:var(--muted);">Every JSON response uses this envelope:</p>
            <pre>{{ $json($catalog['conventions']['envelope']) }}</pre>
        </section>

        @foreach ($catalog['sections'] as $section)
            <section class="section" id="{{ $section['id'] }}">
                <h2>{{ $section['title'] }}</h2>
                <p class="blurb">{{ $section['blurb'] }}</p>

                @foreach ($section['groups'] as $group)
                    <div class="group" id="{{ $group['id'] }}">
                        <h3>{{ $group['title'] }}</h3>

                        @foreach ($group['endpoints'] as $endpoint)
                            @php $eid = $section['id'].'-'.md5($endpoint['method'].$endpoint['path']); @endphp
                            <article class="endpoint" id="{{ $eid }}" data-endpoint data-search="{{ strtolower($endpoint['method'].' '.$endpoint['path'].' '.($endpoint['summary'] ?? '').' '.$group['title']) }}">
                                <div class="endpoint-head">
                                    <span class="method {{ $endpoint['method'] }}">{{ $endpoint['method'] }}</span>
                                    <span class="path">{{ $endpoint['path'] }}</span>
                                    <span class="pill">{{ $endpoint['auth'] ?? 'none' }}</span>
                                    @if (!empty($endpoint['permission']))
                                        <span class="pill">{{ $endpoint['permission'] }}</span>
                                    @endif
                                </div>
                                <div class="endpoint-body">
                                    @if (!empty($endpoint['summary']))
                                        <p class="summary">{{ $endpoint['summary'] }}</p>
                                    @endif

                                    @if (!empty($endpoint['types']))
                                        <table class="types">
                                            <thead><tr><th>Field</th><th>Type</th><th>Notes</th></tr></thead>
                                            <tbody>
                                            @foreach ($endpoint['types'] as $t)
                                                <tr>
                                                    <td><code>{{ $t['field'] }}</code></td>
                                                    <td><code>{{ $t['type'] }}</code></td>
                                                    <td>{{ $t['notes'] ?? '' }}</td>
                                                </tr>
                                            @endforeach
                                            </tbody>
                                        </table>
                                    @endif

                                    <div class="scenarios">
                                        @foreach ($endpoint['scenarios'] as $scenario)
                                            <div class="scenario" data-scenario data-search="{{ strtolower(($scenario['title'] ?? '').' '.($scenario['explain'] ?? '')) }}">
                                                <div class="scenario-top">
                                                    <h4>{{ $scenario['title'] }}</h4>
                                                    <span class="badge {{ $scenario['source'] ?? 'documented' }}">{{ $scenario['source'] ?? 'documented' }}</span>
                                                    @if (!empty($scenario['http_status']))
                                                        <span class="pill">HTTP {{ $scenario['http_status'] }}</span>
                                                    @endif
                                                </div>
                                                @if (!empty($scenario['explain']))
                                                    <p class="explain">{{ $scenario['explain'] }}</p>
                                                @endif
                                                @if (!empty($scenario['note']))
                                                    <div class="note">{{ $scenario['note'] }}</div>
                                                @endif
                                                <div class="panels">
                                                    <div>
                                                        <div class="panel-label">
                                                            <span>Request</span>
                                                            @if ($json($scenario['request'] ?? null))
                                                                <button class="copy" type="button" data-copy>Copy</button>
                                                            @endif
                                                        </div>
                                                        <pre>@if(($scenario['request'] ?? null) === null)(no body / query only)@else{{ $json($scenario['request']) }}@endif</pre>
                                                    </div>
                                                    <div>
                                                        <div class="panel-label">
                                                            <span>Response</span>
                                                            @if ($json($scenario['response'] ?? null))
                                                                <button class="copy" type="button" data-copy>Copy</button>
                                                            @endif
                                                        </div>
                                                        <pre>@if(empty($scenario['response']))(see live test / try in app)@else{{ $json($scenario['response']) }}@endif</pre>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endforeach
            </section>
        @endforeach
    </main>
</div>
<script>
(() => {
    const search = document.getElementById('search');
    const endpoints = [...document.querySelectorAll('[data-endpoint]')];
    const links = [...document.querySelectorAll('[data-nav]')];

    search.addEventListener('input', () => {
        const q = search.value.trim().toLowerCase();
        endpoints.forEach(el => {
            const hit = !q || el.dataset.search.includes(q) || [...el.querySelectorAll('[data-scenario]')].some(s => s.dataset.search.includes(q));
            el.classList.toggle('hidden', !hit);
        });
    });

    document.querySelectorAll('[data-copy]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const pre = btn.closest('div').parentElement.querySelector('pre');
            await navigator.clipboard.writeText(pre.textContent);
            btn.textContent = 'Copied';
            setTimeout(() => btn.textContent = 'Copy', 1200);
        });
    });

    const io = new IntersectionObserver((entries) => {
        entries.forEach(e => {
            if (!e.isIntersecting) return;
            links.forEach(l => l.classList.toggle('active', l.getAttribute('href') === '#' + e.target.id));
        });
    }, { rootMargin: '-20% 0px -70% 0px', threshold: 0.01 });
    endpoints.forEach(el => io.observe(el));
})();
</script>
</body>
</html>
