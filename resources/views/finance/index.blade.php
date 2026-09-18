@extends('layouts.app')

@section('title', 'Finanzen · Airline Empire')
@section('eyebrow', 'FINANCE & CONTROLLING')
@section('heading', 'Finanzzentrum')
@section('subheading', $airline->name.' · '.$world->name)

@section('content')
@php
    $accountTypeLabels = [
        'asset' => 'Aktiva',
        'liability' => 'Verbindlichkeiten',
        'equity' => 'Eigenkapital',
        'income' => 'Erträge',
        'expense' => 'Aufwendungen',
    ];
    $referenceLabels = [
        'airline_creation' => 'Gründung',
        'flight_completion' => 'Flugbetrieb',
        'aircraft_order' => 'Flottenbeschaffung',
        'aircraft_lease_setup' => 'Leasing',
        'aircraft_lease_payment' => 'Leasingrate',
        'aircraft_maintenance' => 'Maintenance',
        'crew_payroll' => 'Personal',
        'marketing_campaign' => 'Marketing',
        'airport_station' => 'Airport Operations',
    ];
    $procurementLabels = [
        'purchase_new' => 'Neukauf',
        'purchase_used' => 'Gebrauchtkauf',
        'lease' => 'Leasing',
    ];
    $healthLabel = match(true) {
        $healthScore >= 80 => 'Sehr stabil',
        $healthScore >= 60 => 'Stabil',
        $healthScore >= 40 => 'Beobachten',
        default => 'Kritisch',
    };
@endphp

<section class="card game-panel">
    <div class="section-title">
        <div>
            <span class="eyebrow">REPORTING PERIOD</span>
            <h3>Finanzielle Lage</h3>
        </div>
        <div class="split-actions">
            @foreach([7 => '7 Tage', 30 => '30 Tage', 90 => '90 Tage', 365 => '12 Monate'] as $value => $label)
                <a class="button {{ $days === $value ? 'primary' : 'ghost' }}" href="{{ route('finance.index', ['days' => $value]) }}">{{ $label }}</a>
            @endforeach
        </div>
    </div>

    <div class="finance-hero">
        <div class="finance-score">
            <div class="finance-score-ring" style="--score:{{ $healthScore }}%"><strong>{{ $healthScore }}</strong></div>
            <div>
                <span class="game-label">Financial Health</span>
                <h2 style="margin:4px 0 5px">{{ $healthLabel }}</h2>
                <div class="muted">
                    Zeitraum {{ $from->timezone('Europe/Berlin')->format('d.m.Y') }} bis {{ $asOf->timezone('Europe/Berlin')->format('d.m.Y') }}
                </div>
            </div>
        </div>

        <div class="list">
            <div class="row"><span class="muted">Operative Marge</span><strong class="{{ $operatingMarginPercent >= 0 ? 'kpi-positive' : 'kpi-negative' }}">{{ number_format($operatingMarginPercent, 1, ',', '.') }} %</strong></div>
            <div class="row"><span class="muted">Cash-Runway</span><strong>{{ $cashRunwayDays === null ? '∞' : number_format($cashRunwayDays, 0, ',', '.').' Tage' }}</strong></div>
            <div class="row"><span class="muted">Monatliche Fixkosten</span><strong>{{ number_format($monthlyFixedCommitmentMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong></div>
        </div>
    </div>
</section>

<section class="grid grid-4" style="margin-top:18px">
    <article class="card metric game-panel">
        <div class="metric-icon"><x-icon name="cash" :size="19" /></div>
        <span class="eyebrow">LIQUIDITÄT</span>
        <strong class="{{ $cashBalanceMinor >= 0 ? 'kpi-positive' : 'kpi-negative' }}">{{ number_format($cashBalanceMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>aktuelles Bankguthaben</small>
    </article>
    <article class="card metric game-panel">
        <div class="metric-icon"><x-icon name="revenue" :size="19" /></div>
        <span class="eyebrow">UMSATZ</span>
        <strong class="kpi-positive">{{ number_format($revenueMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>{{ $days }}-Tage-Erträge</small>
    </article>
    <article class="card metric game-panel">
        <div class="metric-icon"><x-icon name="finance" :size="19" /></div>
        <span class="eyebrow">AUFWAND</span>
        <strong>{{ number_format($expenseMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>{{ number_format($averageDailyExpenseMinor / 100, 0, ',', '.') }} {{ $airline->base_currency }} Ø pro Tag</small>
    </article>
    <article class="card metric game-panel">
        <div class="metric-icon"><x-icon name="status" :size="19" /></div>
        <span class="eyebrow">ERGEBNIS</span>
        <strong class="{{ $operatingResultMinor >= 0 ? 'kpi-positive' : 'kpi-negative' }}">{{ number_format($operatingResultMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>{{ $operatingMarginPercent >= 0 ? '+' : '' }}{{ number_format($operatingMarginPercent, 1, ',', '.') }} % operative Marge</small>
    </article>
</section>

<section class="grid grid-4" style="margin-top:18px">
    <article class="card metric">
        <span class="eyebrow">CASHFLOW</span>
        <strong class="{{ $cashFlowMinor >= 0 ? 'kpi-positive' : 'kpi-negative' }}">{{ $cashFlowMinor >= 0 ? '+' : '' }}{{ number_format($cashFlowMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>Bankveränderung im Zeitraum</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">NETTO-VERMÖGEN</span>
        <strong class="{{ $netAssetValueMinor >= 0 ? 'kpi-positive' : 'kpi-negative' }}">{{ number_format($netAssetValueMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>Aktiva abzüglich Verbindlichkeiten</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">PAYROLL / MONAT</span>
        <strong>{{ number_format($monthlyPayrollMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>aktiver Personalbestand</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">LEASING / MONAT</span>
        <strong>{{ number_format($monthlyLeaseCommitmentMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>laufende Monatsraten</small>
    </article>
</section>

<section class="grid grid-2" style="margin-top:18px">
    <article class="card game-panel">
        <div class="section-title">
            <div>
                <span class="eyebrow">PERFORMANCE</span>
                <h3>Tagesentwicklung</h3>
            </div>
            <span class="badge">{{ $dailyPerformance->count() }} aktive Buchungstage</span>
        </div>

        @if($dailyPerformance->isEmpty())
            <div class="empty">Im Zeitraum liegen noch keine erfolgswirksamen Buchungen vor.</div>
        @else
            <div class="list">
                @foreach($dailyPerformance->take(-14) as $row)
                    <div style="padding:10px 0;border-bottom:1px solid #edf1f5">
                        <div style="display:flex;justify-content:space-between;gap:10px;margin-bottom:7px">
                            <strong>{{ substr($row['date'], 8, 2) }}.{{ substr($row['date'], 5, 2) }}.</strong>
                            <span class="{{ $row['result_minor'] >= 0 ? 'kpi-positive' : 'kpi-negative' }}">
                                {{ $row['result_minor'] >= 0 ? '+' : '' }}{{ number_format($row['result_minor'] / 100, 0, ',', '.') }}
                            </span>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                            <div>
                                <span class="route-meta">Umsatz {{ number_format($row['revenue_minor'] / 100, 0, ',', '.') }}</span>
                                <div class="finance-bar"><span style="width:{{ min(100, ($row['revenue_minor'] / $maxDailyActivityMinor) * 100) }}%"></span></div>
                            </div>
                            <div>
                                <span class="route-meta">Aufwand {{ number_format($row['expense_minor'] / 100, 0, ',', '.') }}</span>
                                <div class="finance-bar expense"><span style="width:{{ min(100, ($row['expense_minor'] / $maxDailyActivityMinor) * 100) }}%"></span></div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </article>

    <article class="card game-panel">
        <div class="section-title">
            <div>
                <span class="eyebrow">COST STRUCTURE</span>
                <h3>Kostenmix</h3>
            </div>
            <span class="badge">{{ $days }} Tage</span>
        </div>

        @if($expenseMix->isEmpty())
            <div class="empty">Noch keine Aufwendungen im Zeitraum.</div>
        @else
            <div class="list">
                @foreach($expenseMix->take(8) as $account)
                    <div style="padding:9px 0">
                        <div style="display:flex;justify-content:space-between;gap:12px;margin-bottom:5px">
                            <div><strong>{{ $account['name'] }}</strong><br><span class="route-meta">{{ $account['code'] }}</span></div>
                            <div style="text-align:right"><strong>{{ number_format($account['amount_minor'] / 100, 0, ',', '.') }}</strong><br><span class="route-meta">{{ number_format($account['share_percent'], 1, ',', '.') }} %</span></div>
                        </div>
                        <div class="finance-bar expense"><span style="width:{{ min(100, $account['share_percent']) }}%"></span></div>
                    </div>
                @endforeach
            </div>
        @endif
    </article>
</section>

<section class="grid grid-2" style="margin-top:18px">
    <article class="card">
        <div class="section-title">
            <div><span class="eyebrow">PROFIT & LOSS</span><h3>Erträge</h3></div>
            <span class="badge">{{ number_format($revenueMinor / 100, 0, ',', '.') }} {{ $airline->base_currency }}</span>
        </div>
        @if($incomeAccounts->isEmpty())
            <div class="empty">Noch keine Erträge im gewählten Zeitraum.</div>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Konto</th><th>Betrag</th></tr></thead>
                    <tbody>
                    @foreach($incomeAccounts as $account)
                        <tr>
                            <td><strong>{{ $account['name'] }}</strong><br><span class="muted">{{ $account['code'] }}</span></td>
                            <td class="kpi-positive"><strong>{{ number_format($account['amount_minor'] / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </article>

    <article class="card">
        <div class="section-title">
            <div><span class="eyebrow">PROFIT & LOSS</span><h3>Aufwendungen</h3></div>
            <span class="badge">{{ number_format($expenseMinor / 100, 0, ',', '.') }} {{ $airline->base_currency }}</span>
        </div>
        @if($expenseAccounts->isEmpty())
            <div class="empty">Noch keine Aufwendungen im gewählten Zeitraum.</div>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Konto</th><th>Betrag</th></tr></thead>
                    <tbody>
                    @foreach($expenseAccounts as $account)
                        <tr>
                            <td><strong>{{ $account['name'] }}</strong><br><span class="muted">{{ $account['code'] }}</span></td>
                            <td><strong>{{ number_format($account['amount_minor'] / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </article>
</section>

<section class="grid grid-2" style="margin-top:18px">
    <article class="card">
        <div class="section-title">
            <div><span class="eyebrow">BALANCE SHEET</span><h3>Bilanzübersicht</h3></div>
            <span class="badge">{{ $accountBalances->count() }} Konten</span>
        </div>
        <div class="list">
            <div class="row"><span class="muted">Aktiva</span><strong>{{ number_format($assetValueMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong></div>
            <div class="row"><span class="muted">Verbindlichkeiten</span><strong>{{ number_format($liabilityValueMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong></div>
            <div class="row"><span class="muted">Eigenkapital</span><strong>{{ number_format($equityValueMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong></div>
            <div class="row"><span class="muted">Netto-Vermögen</span><strong class="{{ $netAssetValueMinor >= 0 ? 'kpi-positive' : 'kpi-negative' }}">{{ number_format($netAssetValueMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong></div>
        </div>
        <details style="margin-top:12px">
            <summary class="button ghost" style="cursor:pointer">Alle Konten anzeigen</summary>
            <div class="table-wrap" style="margin-top:12px">
                <table class="data-table">
                    <thead><tr><th>Konto</th><th>Typ</th><th>Saldo</th></tr></thead>
                    <tbody>
                    @foreach($accountBalances as $account)
                        <tr>
                            <td><strong>{{ $account['name'] }}</strong><br><span class="muted">{{ $account['code'] }}</span></td>
                            <td><span class="badge">{{ $accountTypeLabels[$account['type']] ?? $account['type'] }}</span></td>
                            <td>{{ number_format($account['display_balance_minor'] / 100, 2, ',', '.') }} {{ $account['currency'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    </article>

    <article class="card">
        <div class="section-title">
            <div><span class="eyebrow">FIXED COMMITMENTS · LEASINGVERPFLICHTUNGEN</span><h3>Monatliche Verpflichtungen</h3></div>
            <span class="badge">{{ number_format($monthlyFixedCommitmentMinor / 100, 0, ',', '.') }} {{ $airline->base_currency }}</span>
        </div>
        <div class="list">
            <div class="row"><span class="muted">Personal</span><strong>{{ number_format($monthlyPayrollMinor / 100, 2, ',', '.') }}</strong></div>
            <div class="row"><span class="muted">Leasing</span><strong>{{ number_format($monthlyLeaseCommitmentMinor / 100, 2, ',', '.') }}</strong></div>
            <div class="row"><span class="muted">Cash-Runway</span><strong>{{ $cashRunwayDays === null ? 'keine laufenden Aufwendungen' : number_format($cashRunwayDays, 0, ',', '.').' Tage' }}</strong></div>
        </div>

        @if($leaseContracts->isNotEmpty())
            <details style="margin-top:12px">
                <summary class="button ghost" style="cursor:pointer">Leasingverträge anzeigen</summary>
                <div class="table-wrap" style="margin-top:12px">
                    <table class="data-table">
                        <thead><tr><th>Flugzeug</th><th>Rate</th><th>Nächste Zahlung</th><th>Ende</th></tr></thead>
                        <tbody>
                        @foreach($leaseContracts as $contract)
                            <tr>
                                <td><strong>{{ $contract->registration }}</strong><br><span class="muted">{{ $contract->type?->manufacturer }} {{ $contract->type?->model }}</span></td>
                                <td>{{ number_format($contract->monthly_payment_minor / 100, 2, ',', '.') }}</td>
                                <td>{{ $contract->next_payment_at?->timezone('Europe/Berlin')->format('d.m.Y') ?? 'nach Auslieferung' }}</td>
                                <td>{{ $contract->lease_ends_at?->timezone('Europe/Berlin')->format('d.m.Y') ?? '–' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @endif
    </article>
</section>

<section class="card game-panel" style="margin-top:18px" data-table-filter>
    <div class="section-title">
        <div>
            <span class="eyebrow">GENERAL LEDGER</span>
            <h3>Buchungsjournal</h3>
        </div>
        <span class="badge">letzte {{ $recentTransactions->count() }} Buchungen</span>
    </div>

    @if($recentTransactions->isEmpty())
        <div class="empty">Noch keine Buchungen vorhanden.</div>
    @else
        <div class="filter-bar">
            <div class="field search">
                <label>Buchungen durchsuchen</label>
                <input type="search" data-table-search placeholder="Flug, Leasing, Marketing, Maintenance…">
            </div>
            <div><span class="game-label">Treffer</span><div class="filter-count" data-table-count>{{ $recentTransactions->count() }}</div></div>
        </div>

        <div class="transaction-list">
            @foreach($recentTransactions as $transaction)
                <article class="transaction-item"
                         data-filter-row
                         data-search="{{ $transaction['description'] }} {{ $referenceLabels[$transaction['reference_type']] ?? $transaction['reference_type'] }}">
                    <div class="transaction-icon {{ $transaction['cash_effect_minor'] > 0 ? 'positive' : ($transaction['cash_effect_minor'] < 0 ? 'negative' : '') }}">
                        <x-icon :name="$transaction['cash_effect_minor'] >= 0 ? 'revenue' : 'finance'" :size="17" />
                    </div>
                    <div>
                        <strong>{{ $transaction['description'] }}</strong>
                        <div class="route-meta">
                            {{ $transaction['occurred_at']->timezone('Europe/Berlin')->format('d.m.Y H:i') }} ·
                            {{ $referenceLabels[$transaction['reference_type']] ?? ($transaction['reference_type'] ?: 'System') }}
                        </div>
                        <details style="margin-top:5px">
                            <summary class="route-meta" style="cursor:pointer">Buchungssatz anzeigen</summary>
                            <div style="margin-top:5px">
                                @foreach($transaction['entries'] as $entry)
                                    <div class="route-meta">
                                        <strong>{{ $entry['code'] }}</strong> · {{ $entry['account'] }} ·
                                        {{ $entry['amount_minor'] >= 0 ? '+' : '' }}{{ number_format($entry['amount_minor'] / 100, 2, ',', '.') }}
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    </div>
                    <div style="text-align:right">
                        <strong class="{{ $transaction['cash_effect_minor'] >= 0 ? 'kpi-positive' : 'kpi-negative' }}">
                            {{ $transaction['cash_effect_minor'] >= 0 ? '+' : '' }}{{ number_format($transaction['cash_effect_minor'] / 100, 2, ',', '.') }}
                        </strong>
                        <div class="route-meta">{{ $airline->base_currency }}</div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>

<p class="footer-note">Financial Health und Cash-Runway sind Spielindikatoren. Alle Geldbewegungen basieren auf dem doppelten Ledger von Airline Empire.</p>
@endsection
