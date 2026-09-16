@extends('layouts.app')

@section('title', 'Spielwelten · Airline Empire')
@section('eyebrow', 'WORLD SELECT')
@section('heading', 'Spielwelt wählen')
@section('subheading', 'Dein Account kann in mehreren Welten spielen. Jede Welt hat eine getrennte Wirtschaft und Airline-Struktur.')

@section('content')
<div class="grid grid-3">
    @forelse($worlds as $world)
        <article class="card world-card">
            <div>
                <span class="eyebrow">{{ strtoupper($world->type) }}</span>
                <h2>{{ $world->name }}</h2>
                <p class="muted">Persistente Simulationswelt mit serverseitiger Zeit- und Wirtschaftslogik.</p>
            </div>

            <div class="world-meta">
                <span class="badge">Tempo {{ number_format((float) $world->speed_multiplier, 2, ',', '.') }}×</span>
                <span class="badge">{{ data_get($world->settings, 'currency', 'EUR') }}</span>
                @if(in_array($world->id, $joinedIds, true))
                    <span class="badge">Bereits beigetreten</span>
                @endif
            </div>

            <form method="post" action="{{ route('worlds.enter', $world) }}">
                @csrf
                <button class="button primary" type="submit">
                    {{ $activeWorldId === $world->id ? 'Welt fortsetzen' : (in_array($world->id, $joinedIds, true) ? 'Welt betreten' : 'Welt beitreten') }}
                </button>
            </form>
        </article>
    @empty
        <div class="empty">Aktuell ist keine aktive Spielwelt verfügbar.</div>
    @endforelse
</div>
@endsection
