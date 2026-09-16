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
    ];
    $procurementLabels = [
        'purchase_new' => 'Neukauf',
        'purchase_used' => 'Gebrauchtkauf',
        'lease' => 'Leasing',
    ];
@endphp

<section class="card">
    <div class="section-title">
        <div>
            <span class="eyebrow">REPORTING PERIOD</span>
            <h3>Auswertungszeitraum</h3>
        </div>
        <span class="badge">ab {{ $from->timezone('Europe/Berlin')->format('d.m.Y') }}</span>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
        @foreach([7 => '7 Tage', 30 => '30 Tage', 90 => '90 Tage', 365 => '12 Monate'] as $value => $label)
            <a class="button {{ $days === $value ? 'primary' : 'ghost' }}" href="{{ route('finance.index', ['days' => $value]) }}">{{ $label }}</a>
        @endforeach
    </div>
</section>

<section class="grid grid-4" style="margin-top:18px">
    <article class="card metric">
        <span class="eyebrow">LIQUIDITÄT</span>
        <strong class="{{ $cashBalanceMinor >= 0 ? 'kpi-positive' : 'kpi-negative' }}">{{ number_format($cashBalanceMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>aktuelles Bankguthaben</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">UMSATZ</span>
        <strong class="kpi-positive">{{ number_format($revenueMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>{{ $days }}-Tage-Erträge</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">AUFWAND</span>
        <strong>{{ number_format($expenseMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>{{ $days }}-Tage-Aufwendungen</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">ERGEBNIS</span>
        <strong class="{{ $operatingResultMinor >= 0 ? 'kpi-positive' : 'kpi-negative' }}">{{ number_format($operatingResultMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>Erträge minus Aufwendungen</small>
    </article>
</section>

<section class="grid grid-4" style="margin-top:18px">
    <article class="card metric">
        <span class="eyebrow">CASHFLOW</span>
        <strong class="{{ $cashFlowMinor >= 0 ? 'kpi-positive' : 'kpi-negative' }}">{{ $cashFlowMinor >= 0 ? '+' : '' }}{{ number_format($cashFlowMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>Veränderung Bankguthaben im Zeitraum</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">AKTIVA</span>
        <strong>{{ number_format($assetValueMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>bilanzielle Vermögenswerte</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">EIGENKAPITAL</span>
        <strong>{{ number_format($equityValueMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>gebuchtes Eigenkapital</small>
    </article>
    <article class="card metric">
        <span class="eyebrow">LEASING / MONAT</span>
        <strong>{{ number_format($monthlyLeaseCommitmentMinor / 100, 2, ',', '.') }} {{ $airline->base_currency }}</strong>
        <small>laufende Monatsraten</small>
    </article>
</section>

<section class="grid grid-2" style="margin-top:18px">
    <article class="card">
        <div class="section-title">
            <div>
                <span class="eyebrow">PROFIT & LOSS</span>
                <h3>Erträge</h3>
            </div>
            <span class="badge">{{ $days }} Tage</span>
        </div>
        @if($incomeAccounts->isEmpty())
            <div class="empty">Im gewählten Zeitraum wurden noch keine Erträge gebucht.</div>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Konto</th><th>Betrag</th></tr></thead>
                    <tbody>
                    @foreach($incomeAccounts as $account)
                        <tr>
                            <td><strong>{{ $account['name'] }}</strong><br><span class="muted">{{ $account['code'] }}</span></td>
                            <td class="kpi-positive">{{ number_format($account['amount_minor'] / 100, 2, ',', '.') }} {{ $airline->base_currency }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </article>

    <article class="card">
        <div class="section-title">
            <div>
                <span class="eyebrow">PROFIT & LOSS</span>
                <h3>Aufwendungen</h3>
            </div>
            <span class="badge">{{ $days }} Tage</span>
        </div>
        @if($expenseAccounts->isEmpty())
            <div class="empty">Im gewählten Zeitraum wurden noch keine Aufwendungen gebucht.</div>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Konto</th><th>Betrag</th></tr></thead>
                    <tbody>
                    @foreach($expenseAccounts as $account)
                        <tr>
                            <td><strong>{{ $account['name'] }}</strong><br><span class="muted">{{ $account['code'] }}</span></td>
                            <td>{{ number_format($account['amount_minor'] / 100, 2, ',', '.') }} {{ $airline->base_currency }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </article>
</section>

<section class="card" style="margin-top:18px">
    <div class="section-title">
        <div>
            <span class="eyebrow">BALANCE SHEET</span>
            <h3>Kontensalden</h3>
        </div>
        <span class="badge">{{ $accountBalances->count() }} Konten</span>
    </div>
    <div class="table-wrap">
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
</section>

<section class="card" style="margin-top:18px">
    <div class="section-title">
        <div>
            <span class="eyebrow">CONTRACTUAL OBLIGATIONS</span>
            <h3>Leasingverpflichtungen</h3>
        </div>
        <span class="badge">{{ $leaseContracts->count() }} Verträge</span>
    </div>

    @if($leaseContracts->isEmpty())
        <div class="empty">Keine laufenden oder bestellten Leasingflugzeuge.</div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Flugzeug</th><th>Vertrag</th><th>Monatsrate</th><th>Nächste Zahlung</th><th>Vertragsende</th><th>Status</th></tr></thead>
                <tbody>
                @foreach($leaseContracts as $contract)
                    <tr>
                        <td><strong>{{ $contract->registration }}</strong><br><span class="muted">{{ $contract->type?->manufacturer }} {{ $contract->type?->model }}</span></td>
                        <td>{{ $procurementLabels[$contract->procurement_type] ?? $contract->procurement_type }} · {{ $contract->lease_term_months ?? '–' }} Monate</td>
                        <td>{{ number_format($contract->monthly_payment_minor / 100, 2, ',', '.') }} {{ $contract->currency }}</td>
                        <td>{{ $contract->next_payment_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') ?? 'nach Auslieferung' }}</td>
                        <td>{{ $contract->lease_ends_at?->timezone('Europe/Berlin')->format('d.m.Y') ?? '–' }}</td>
                        <td><span class="badge">{{ $contract->status === 'delivered' ? 'Aktiv' : 'Bestellt' }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<section class="card" style="margin-top:18px">
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
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Zeitpunkt</th><th>Vorgang</th><th>Kategorie</th><th>Cash-Effekt</th><th>Buchungssatz</th></tr></thead>
                <tbody>
                @foreach($recentTransactions as $transaction)
                    <tr>
                        <td>{{ $transaction['occurred_at']->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</td>
                        <td><strong>{{ $transaction['description'] }}</strong></td>
                        <td><span class="badge">{{ $referenceLabels[$transaction['reference_type']] ?? ($transaction['reference_type'] ?: 'System') }}</span></td>
                        <td class="{{ $transaction['cash_effect_minor'] >= 0 ? 'kpi-positive' : 'kpi-negative' }}">
                            {{ $transaction['cash_effect_minor'] >= 0 ? '+' : '' }}{{ number_format($transaction['cash_effect_minor'] / 100, 2, ',', '.') }} {{ $airline->base_currency }}
                        </td>
                        <td>
                            @foreach($transaction['entries'] as $entry)
                                <div><span class="muted">{{ $entry['code'] }}</span> {{ $entry['account'] }}: {{ $entry['amount_minor'] >= 0 ? '+' : '' }}{{ number_format($entry['amount_minor'] / 100, 2, ',', '.') }}</div>
                            @endforeach
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<p class="footer-note">Alle Werte stammen aus dem doppelten Ledger. Flugumsätze, Treibstoff, operative Kosten, Wartungen, Flugzeugbeschaffung und Leasing werden damit nachvollziehbar in einer gemeinsamen Finanzsicht zusammengeführt.</p>
@endsection
