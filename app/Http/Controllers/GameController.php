<?php

namespace App\Http\Controllers;

use App\Models\Airline;
use App\Models\World;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class GameController extends Controller
{
    public function home(Request $request): RedirectResponse
    {
        $worldId = $this->resolveActiveWorldId($request);

        if (! $worldId) {
            return redirect()->route('worlds.index');
        }

        $airline = Airline::query()
            ->where('world_id', $worldId)
            ->where('owner_user_id', $request->user()->id)
            ->first();

        if (! $airline) {
            return redirect()->route('airlines.create');
        }

        return redirect()->route('dashboard');
    }

    public function worlds(Request $request): View
    {
        $worlds = World::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $joinedIds = DB::table('world_memberships')
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->pluck('world_id')
            ->all();

        return view('worlds.index', [
            'worlds' => $worlds,
            'joinedIds' => $joinedIds,
            'activeWorldId' => $request->session()->get('active_world_id'),
        ]);
    }

    public function enterWorld(Request $request, World $world): RedirectResponse
    {
        abort_unless($world->status === 'active', 404);

        $membership = DB::table('world_memberships')
            ->where('world_id', $world->id)
            ->where('user_id', $request->user()->id)
            ->first();

        $now = now();

        if ($membership) {
            DB::table('world_memberships')
                ->where('id', $membership->id)
                ->update([
                    'status' => 'active',
                    'last_active_at' => $now,
                    'updated_at' => $now,
                ]);
        } else {
            DB::table('world_memberships')->insert([
                'id' => (string) Str::ulid(),
                'world_id' => $world->id,
                'user_id' => $request->user()->id,
                'status' => 'active',
                'joined_at' => $now,
                'last_active_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $request->session()->put('active_world_id', $world->id);

        $hasAirline = Airline::query()
            ->where('world_id', $world->id)
            ->where('owner_user_id', $request->user()->id)
            ->exists();

        return $hasAirline
            ? redirect()->route('dashboard')
            : redirect()->route('airlines.create');
    }

    public function dashboard(Request $request): View|RedirectResponse
    {
        $worldId = $this->resolveActiveWorldId($request);

        if (! $worldId) {
            return redirect()->route('worlds.index');
        }

        $world = World::findOrFail($worldId);
        $airline = Airline::query()
            ->with('homeAirport')
            ->withCount(['aircraft', 'routes', 'flights'])
            ->where('world_id', $worldId)
            ->where('owner_user_id', $request->user()->id)
            ->first();

        if (! $airline) {
            return redirect()->route('airlines.create');
        }

        $cashBalanceMinor = (int) DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_entries.ledger_account_id', '=', 'ledger_accounts.id')
            ->where('ledger_accounts.airline_id', $airline->id)
            ->where('ledger_accounts.code', 'CASH')
            ->sum('ledger_entries.amount_minor');

        return view('dashboard', [
            'world' => $world,
            'airline' => $airline,
            'cashBalanceMinor' => $cashBalanceMinor,
        ]);
    }

    private function resolveActiveWorldId(Request $request): ?string
    {
        $worldId = $request->session()->get('active_world_id');

        if ($worldId && $this->hasMembership($request, $worldId)) {
            return $worldId;
        }

        $worldId = DB::table('world_memberships')
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->orderByDesc('last_active_at')
            ->value('world_id');

        if ($worldId) {
            $request->session()->put('active_world_id', $worldId);
        }

        return $worldId;
    }

    private function hasMembership(Request $request, string $worldId): bool
    {
        return DB::table('world_memberships')
            ->where('world_id', $worldId)
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->exists();
    }
}
