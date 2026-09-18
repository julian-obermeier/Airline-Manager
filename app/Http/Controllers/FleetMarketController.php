<?php

namespace App\Http\Controllers;

use App\Models\AircraftMarketOffer;
use App\Models\AircraftProcurement;
use App\Models\AircraftType;
use App\Models\Airline;
use App\Models\World;
use App\Services\Operations\ProcurementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class FleetMarketController extends Controller
{
    public function __construct(private readonly ProcurementService $procurement)
    {
    }

    public function index(Request $request): View|RedirectResponse
    {
        $context = $this->activeContext($request);
        if (! $context) {
            return redirect()->route('home');
        }
        [$world, $airline] = $context;

        return view('fleet-market.index', [
            'world' => $world,
            'airline' => $airline,
            'cashBalanceMinor' => $this->procurement->cashBalanceMinor($airline),
            'types' => AircraftType::query()
                ->whereNotNull('reference_purchase_price_minor')
                ->orderBy('manufacturer')
                ->orderBy('model')
                ->get(),
            'newTypes' => AircraftType::query()
                ->whereNotNull('reference_purchase_price_minor')
                ->where('production_status', 'active')
                ->orderBy('manufacturer')
                ->orderBy('model')
                ->get(),
            'usedOffers' => AircraftMarketOffer::query()->with(['type', 'locationAirport'])
                ->where('world_id', $world->id)->where('status', 'available')->orderBy('price_minor')->get(),
            'procurements' => AircraftProcurement::query()->with(['type', 'marketOffer', 'deliveredAircraft'])
                ->where('world_id', $world->id)->where('airline_id', $airline->id)->orderByDesc('ordered_at')->limit(40)->get(),
        ]);
    }

    public function orderNew(Request $request): RedirectResponse
    {
        [$world, $airline] = $this->requireContext($request);
        $validated = $request->validate([
            'aircraft_type_id' => ['required', 'string', 'exists:aircraft_types,id'],
            'registration' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);
        $type = AircraftType::findOrFail($validated['aircraft_type_id']);
        if ($type->production_status !== 'active') {
            throw ValidationException::withMessages([
                'aircraft_type_id' => 'Dieses Flugzeugmuster wird nicht mehr als Neuflugzeug angeboten.',
            ]);
        }

        try {
            $order = $this->procurement->orderNewPurchase($airline, $type, $validated['registration'] ?? null, $world->simulated_at ?? now());
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['procurement' => $exception->getMessage()]);
        }

        return redirect()->route('fleet-market.index')->with('success', 'Herstellerbestellung '.$order->registration.' aufgegeben. Geplante Auslieferung: '.$order->delivery_due_at->timezone('Europe/Berlin')->format('d.m.Y H:i').'.');
    }

    public function orderLease(Request $request): RedirectResponse
    {
        [$world, $airline] = $this->requireContext($request);
        $validated = $request->validate([
            'aircraft_type_id' => ['required', 'string', 'exists:aircraft_types,id'],
            'registration' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9-]+$/'],
            'lease_term_months' => ['required', 'integer', Rule::in([36, 60, 84])],
        ]);
        $type = AircraftType::findOrFail($validated['aircraft_type_id']);
        if ($type->production_status !== 'active') {
            throw ValidationException::withMessages([
                'aircraft_type_id' => 'Dieses Flugzeugmuster steht nicht für neue Operating-Lease-Verträge zur Verfügung.',
            ]);
        }

        try {
            $order = $this->procurement->orderLease($airline, $type, (int) $validated['lease_term_months'], $validated['registration'] ?? null, $world->simulated_at ?? now());
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['procurement' => $exception->getMessage()]);
        }

        return redirect()->route('fleet-market.index')->with('success', 'Leasingvertrag für '.$order->registration.' abgeschlossen. Geplante Auslieferung: '.$order->delivery_due_at->timezone('Europe/Berlin')->format('d.m.Y H:i').'.');
    }

    public function buyUsed(Request $request, AircraftMarketOffer $offer): RedirectResponse
    {
        [$world, $airline] = $this->requireContext($request);
        abort_unless($offer->world_id === $world->id, 404);
        $validated = $request->validate([
            'registration' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);

        try {
            $order = $this->procurement->buyUsed($airline, $offer, $validated['registration'] ?? null, $world->simulated_at ?? now());
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['procurement' => $exception->getMessage()]);
        }

        return redirect()->route('fleet-market.index')->with('success', 'Gebrauchtflugzeug reserviert und bezahlt. '.$order->registration.' wird am '.$order->delivery_due_at->timezone('Europe/Berlin')->format('d.m.Y H:i').' ausgeliefert.');
    }

    private function requireContext(Request $request): array
    {
        $context = $this->activeContext($request);
        abort_unless($context, 404);
        return $context;
    }

    private function activeContext(Request $request): ?array
    {
        $worldId = $request->session()->get('active_world_id');
        if (! $worldId) {
            return null;
        }

        $membershipExists = DB::table('world_memberships')
            ->where('world_id', $worldId)->where('user_id', $request->user()->id)->where('status', 'active')->exists();
        if (! $membershipExists) {
            return null;
        }

        $world = World::find($worldId);
        $airline = Airline::query()->where('world_id', $worldId)->where('owner_user_id', $request->user()->id)->first();
        return $world && $airline ? [$world, $airline] : null;
    }
}
