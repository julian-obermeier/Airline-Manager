<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#07111f">
    <title>{{ config('app.name', 'Airline Empire') }}</title>
    <link rel="stylesheet" href="{{ asset('build/assets/app-Cdklvegz.css') }}">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-mark">AE</div>
            <div>
                <strong>Airline Empire</strong>
                <span>Operations Platform</span>
            </div>
        </div>

        <nav class="nav" aria-label="Hauptnavigation">
            <a class="nav-link router-link-active" href="/">
                <span class="nav-icon">⌂</span>
                <span class="nav-label">Dashboard</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <span class="phase-pill">Phase 1 · Foundation</span>
        </div>
    </aside>

    <main class="workspace">
        <header class="topbar">
            <div>
                <span class="eyebrow">AIRLINE OPERATIONS</span>
                <h1>Command Center</h1>
            </div>
            <div class="topbar-meta">
                <span class="status-dot" aria-hidden="true"></span>
                Foundation
            </div>
        </header>

        <section class="dashboard-grid">
            <article class="hero-card">
                <div>
                    <span class="eyebrow">FOUNDATION STATUS</span>
                    <h2>Die technische Basis für deine Airline-Simulation steht.</h2>
                    <p>Dieses Dashboard zeigt ausschließlich bereits implementierte Systemfunktionen. Airline-, Flotten- und Flugmodule werden aktiviert, sobald ihre Backend-Workflows vollständig verfügbar sind.</p>
                </div>
                <div class="hero-orbit" aria-hidden="true">
                    <div class="orbit-ring"></div>
                    <div class="aircraft-glyph">✈</div>
                </div>
            </article>

            <article class="metric-card">
                <span class="metric-label">API</span>
                <strong id="api-status">Prüfung läuft</strong>
                <span class="metric-detail">/api/v1/health</span>
            </article>

            <article class="metric-card">
                <span class="metric-label">Backend</span>
                <strong>Laravel 13</strong>
                <span class="metric-detail">API-first Foundation</span>
            </article>

            <article class="metric-card">
                <span class="metric-label">Simulation</span>
                <strong>Serverseitig</strong>
                <span class="metric-detail">World-time ready</span>
            </article>

            <article class="panel system-panel">
                <div class="panel-header">
                    <div>
                        <span class="eyebrow">SYSTEM CHECK</span>
                        <h3>Backend-Verbindung</h3>
                    </div>
                    <span id="api-badge" class="state-badge">Prüfung läuft</span>
                </div>

                <dl id="system-list" class="system-list" hidden>
                    <div><dt>Service</dt><dd id="api-service">–</dd></div>
                    <div><dt>API-Version</dt><dd id="api-version">–</dd></div>
                    <div><dt>Status</dt><dd id="api-result">–</dd></div>
                </dl>
                <p id="api-error" class="error-message" hidden>Die API konnte nicht erreicht werden.</p>
            </article>

            <article class="panel foundation-panel">
                <div class="panel-header">
                    <div>
                        <span class="eyebrow">PHASE 1</span>
                        <h3>Foundation-Domänen</h3>
                    </div>
                </div>
                <div class="domain-chips" aria-label="Bereits modellierte Domänen">
                    <span>Accounts</span><span>Worlds</span><span>Airlines</span><span>Airports</span>
                    <span>Aircraft</span><span>Routes</span><span>Flights</span><span>Finance Ledger</span>
                </div>
            </article>
        </section>
    </main>
</div>

<script>
(async () => {
    const status = document.getElementById('api-status');
    const badge = document.getElementById('api-badge');
    const list = document.getElementById('system-list');
    const error = document.getElementById('api-error');

    try {
        const response = await fetch('/api/v1/health', {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        });

        if (!response.ok) throw new Error('Health endpoint returned ' + response.status);
        const data = await response.json();

        status.textContent = data.status === 'ok' ? 'Online' : 'Nicht erreichbar';
        badge.textContent = status.textContent;
        if (data.status === 'ok') badge.classList.add('state-ok');

        document.getElementById('api-service').textContent = data.service ?? 'Airline Empire';
        document.getElementById('api-version').textContent = 'v' + (data.version ?? '1');
        document.getElementById('api-result').textContent = data.status ?? 'unknown';
        list.hidden = false;
    } catch (e) {
        status.textContent = 'Nicht erreichbar';
        badge.textContent = 'Nicht erreichbar';
        error.hidden = false;
    }
})();
</script>
</body>
</html>
