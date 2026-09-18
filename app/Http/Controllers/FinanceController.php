<?php

namespace App\Http\Controllers;

use App\Models\AircraftProcurement;
use App\Models\Airline;
use App\Models\CrewMember;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\World;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FinanceController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $context = $this->activeContext($request);

        if (! $context) {
            return redirect()->route('home');
        }

        [$world, $airline] = $context;

        $days = (int) $request->integer('days', 30);
        if (! in_array($days, [7, 30, 90, 365], true)) {
            $days = 30;
        }

        $asOf = ($world->simulated_at ?? now())->copy();
        $from = $asOf->copy()->subDays($days)->startOfDay();

        $accounts = LedgerAccount::query()
            ->withSum('entries as raw_balance_minor', 'amount_minor')
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->orderByRaw("FIELD(type, 'asset', 'liability', 'equity', 'income', 'expense')")
            ->orderBy('code')
            ->get();

        $accountBalances = $accounts->map(function (LedgerAccount $account): array {
            $raw = (int) ($account->raw_balance_minor ?? 0);

            return [
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'currency' => $account->currency,
                'raw_balance_minor' => $raw,
                'display_balance_minor' => $this->normaliseBalance($account->type, $raw),
            ];
        });

        $periodAccounts = LedgerEntry::query()
            ->select([
                'ledger_accounts.code',
                'ledger_accounts.name',
                'ledger_accounts.type',
                DB::raw('SUM(ledger_entries.amount_minor) as total_minor'),
            ])
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->join('ledger_transactions', 'ledger_transactions.id', '=', 'ledger_entries.ledger_transaction_id')
            ->where('ledger_transactions.world_id', $world->id)
            ->where('ledger_transactions.airline_id', $airline->id)
            ->whereBetween('ledger_transactions.occurred_at', [$from, $asOf])
            ->groupBy('ledger_accounts.code', 'ledger_accounts.name', 'ledger_accounts.type')
            ->orderBy('ledger_accounts.type')
            ->orderBy('ledger_accounts.code')
            ->get()
            ->map(function ($row): array {
                $raw = (int) $row->total_minor;

                return [
                    'code' => $row->code,
                    'name' => $row->name,
                    'type' => $row->type,
                    'amount_minor' => $this->normaliseBalance($row->type, $raw),
                ];
            });

        $incomeAccounts = $periodAccounts->where('type', 'income')->values();
        $expenseAccounts = $periodAccounts->where('type', 'expense')->values();
        $revenueMinor = (int) $incomeAccounts->sum('amount_minor');
        $expenseMinor = (int) $expenseAccounts->sum('amount_minor');
        $operatingResultMinor = $revenueMinor - $expenseMinor;

        $cashAccount = $accounts->firstWhere('code', 'CASH');
        $cashBalanceMinor = $cashAccount ? (int) ($cashAccount->raw_balance_minor ?? 0) : 0;

        $cashFlowMinor = (int) LedgerEntry::query()
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->join('ledger_transactions', 'ledger_transactions.id', '=', 'ledger_entries.ledger_transaction_id')
            ->where('ledger_transactions.world_id', $world->id)
            ->where('ledger_transactions.airline_id', $airline->id)
            ->where('ledger_accounts.code', 'CASH')
            ->whereBetween('ledger_transactions.occurred_at', [$from, $asOf])
            ->sum('ledger_entries.amount_minor');

        $assetValueMinor = (int) $accountBalances->where('type', 'asset')->sum('display_balance_minor');
        $liabilityValueMinor = (int) $accountBalances->where('type', 'liability')->sum('display_balance_minor');
        $equityValueMinor = (int) $accountBalances->where('type', 'equity')->sum('display_balance_minor');

        $leaseContracts = AircraftProcurement::query()
            ->with('type')
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->where('procurement_type', 'lease')
            ->whereIn('status', ['ordered', 'delivered'])
            ->orderBy('next_payment_at')
            ->get();

        $monthlyLeaseCommitmentMinor = (int) $leaseContracts
            ->where('status', 'delivered')
            ->sum('monthly_payment_minor');

        $monthlyPayrollMinor = (int) CrewMember::query()
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->where('status', 'active')
            ->sum('monthly_salary_minor');

        $monthlyFixedCommitmentMinor = $monthlyLeaseCommitmentMinor + $monthlyPayrollMinor;
        $netAssetValueMinor = $assetValueMinor - $liabilityValueMinor;
        $operatingMarginPercent = $revenueMinor > 0
            ? round(($operatingResultMinor / $revenueMinor) * 100, 1)
            : 0.0;
        $averageDailyExpenseMinor = $days > 0
            ? (int) round($expenseMinor / $days)
            : 0;
        $cashRunwayDays = $averageDailyExpenseMinor > 0
            ? (int) floor(max(0, $cashBalanceMinor) / $averageDailyExpenseMinor)
            : null;

        $expenseMix = $expenseAccounts
            ->sortByDesc('amount_minor')
            ->values()
            ->map(function (array $account) use ($expenseMinor): array {
                return $account + [
                    'share_percent' => $expenseMinor > 0
                        ? round(($account['amount_minor'] / $expenseMinor) * 100, 1)
                        : 0.0,
                ];
            });

        $periodTransactions = LedgerTransaction::query()
            ->with(['entries.account'])
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->whereBetween('occurred_at', [$from, $asOf])
            ->orderBy('occurred_at')
            ->get();

        $dailyPerformance = $periodTransactions
            ->groupBy(fn (LedgerTransaction $transaction): string => $transaction->occurred_at->toDateString())
            ->map(function ($transactions, string $date): array {
                $revenue = 0;
                $expense = 0;
                $cash = 0;

                foreach ($transactions as $transaction) {
                    foreach ($transaction->entries as $entry) {
                        $type = $entry->account?->type;
                        $amount = (int) $entry->amount_minor;

                        if ($type === 'income') {
                            $revenue += -$amount;
                        } elseif ($type === 'expense') {
                            $expense += $amount;
                        }

                        if ($entry->account?->code === 'CASH') {
                            $cash += $amount;
                        }
                    }
                }

                return [
                    'date' => $date,
                    'revenue_minor' => max(0, $revenue),
                    'expense_minor' => max(0, $expense),
                    'result_minor' => $revenue - $expense,
                    'cash_effect_minor' => $cash,
                ];
            })
            ->values();

        $maxDailyActivityMinor = max(
            1,
            (int) $dailyPerformance->max(fn (array $row): int => max($row['revenue_minor'], $row['expense_minor']))
        );

        $healthScore = 0;
        $healthScore += $cashBalanceMinor > 0 ? 20 : 0;
        $healthScore += $operatingResultMinor >= 0 ? 25 : 0;
        $healthScore += $cashFlowMinor >= 0 ? 20 : 0;
        $healthScore += $assetValueMinor > 0 && $liabilityValueMinor <= ($assetValueMinor * 0.60) ? 15 : 5;
        $healthScore += $cashRunwayDays === null || $cashRunwayDays >= 90 ? 20 : ($cashRunwayDays >= 30 ? 12 : 4);
        $healthScore = min(100, max(0, $healthScore));

        $recentTransactions = LedgerTransaction::query()
            ->with(['entries.account'])
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->where('occurred_at', '<=', $asOf)
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->limit(60)
            ->get()
            ->map(function (LedgerTransaction $transaction): array {
                $cashEffectMinor = (int) $transaction->entries
                    ->filter(fn (LedgerEntry $entry): bool => $entry->account?->code === 'CASH')
                    ->sum('amount_minor');

                return [
                    'id' => $transaction->id,
                    'description' => $transaction->description,
                    'reference_type' => $transaction->reference_type,
                    'occurred_at' => $transaction->occurred_at,
                    'cash_effect_minor' => $cashEffectMinor,
                    'entries' => $transaction->entries->map(fn (LedgerEntry $entry): array => [
                        'account' => $entry->account?->name ?? 'Unbekanntes Konto',
                        'code' => $entry->account?->code ?? '–',
                        'amount_minor' => (int) $entry->amount_minor,
                    ]),
                ];
            });

        return view('finance.index', [
            'world' => $world,
            'airline' => $airline,
            'days' => $days,
            'asOf' => $asOf,
            'from' => $from,
            'cashBalanceMinor' => $cashBalanceMinor,
            'cashFlowMinor' => $cashFlowMinor,
            'assetValueMinor' => $assetValueMinor,
            'liabilityValueMinor' => $liabilityValueMinor,
            'equityValueMinor' => $equityValueMinor,
            'revenueMinor' => $revenueMinor,
            'expenseMinor' => $expenseMinor,
            'operatingResultMinor' => $operatingResultMinor,
            'incomeAccounts' => $incomeAccounts,
            'expenseAccounts' => $expenseAccounts,
            'accountBalances' => $accountBalances,
            'leaseContracts' => $leaseContracts,
            'monthlyLeaseCommitmentMinor' => $monthlyLeaseCommitmentMinor,
            'monthlyPayrollMinor' => $monthlyPayrollMinor,
            'monthlyFixedCommitmentMinor' => $monthlyFixedCommitmentMinor,
            'netAssetValueMinor' => $netAssetValueMinor,
            'operatingMarginPercent' => $operatingMarginPercent,
            'averageDailyExpenseMinor' => $averageDailyExpenseMinor,
            'cashRunwayDays' => $cashRunwayDays,
            'expenseMix' => $expenseMix,
            'dailyPerformance' => $dailyPerformance,
            'maxDailyActivityMinor' => $maxDailyActivityMinor,
            'healthScore' => $healthScore,
            'recentTransactions' => $recentTransactions,
        ]);
    }

    private function normaliseBalance(string $type, int $rawMinor): int
    {
        return in_array($type, ['income', 'liability', 'equity'], true)
            ? -$rawMinor
            : $rawMinor;
    }

    private function activeContext(Request $request): ?array
    {
        $worldId = $request->session()->get('active_world_id');

        if (! $worldId) {
            return null;
        }

        $membershipExists = DB::table('world_memberships')
            ->where('world_id', $worldId)
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->exists();

        if (! $membershipExists) {
            return null;
        }

        $world = World::find($worldId);
        $airline = Airline::query()
            ->where('world_id', $worldId)
            ->where('owner_user_id', $request->user()->id)
            ->first();

        return $world && $airline ? [$world, $airline] : null;
    }
}
