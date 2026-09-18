<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#f5f7fb">
    <title>@yield('title', 'Airline Empire')</title>
    <link rel="stylesheet" href="{{ asset('assets/airline-empire.css') }}">
</head>
<body>
@if(auth()->check())
@php
    $navGroups = [
        'Übersicht' => [
            ['route' => 'home', 'match' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard'],
            ['route' => 'operations.index', 'match' => 'operations.*', 'label' => 'Operations', 'icon' => 'operations'],
        ],
        'Planung & Netzwerk' => [
            ['route' => 'schedules.index', 'match' => 'schedules.*', 'label' => 'Flugpläne', 'icon' => 'schedule'],
            ['route' => 'map.index', 'match' => 'map.*', 'label' => 'Weltkarte', 'icon' => 'map'],
            ['route' => 'airport-operations.index', 'match' => 'airport-operations.*', 'label' => 'Airports & Slots', 'icon' => 'airport'],
        ],
        'Flotte & Personal' => [
            ['route' => 'fleet-market.index', 'match' => 'fleet-market.*', 'label' => 'Flottenmarkt', 'icon' => 'fleet'],
            ['route' => 'maintenance.index', 'match' => 'maintenance.*', 'label' => 'Maintenance', 'icon' => 'maintenance'],
            ['route' => 'crew.index', 'match' => 'crew.*', 'label' => 'Personal & Crew', 'icon' => 'crew'],
        ],
        'Commercial' => [
            ['route' => 'revenue-management.index', 'match' => 'revenue-management.*', 'label' => 'Revenue Management', 'icon' => 'revenue'],
            ['route' => 'market.index', 'match' => 'market.*', 'label' => 'Markt & Konkurrenz', 'icon' => 'market'],
            ['route' => 'marketing.index', 'match' => 'marketing.*', 'label' => 'Marketing', 'icon' => 'marketing'],
        ],
        'Unternehmen' => [
            ['route' => 'finance.index', 'match' => 'finance.*', 'label' => 'Finanzen', 'icon' => 'finance'],
            ['route' => 'worlds.index', 'match' => 'worlds.*', 'label' => 'Spielwelten', 'icon' => 'world'],
        ],
    ];
@endphp
<div class="shell">
    <aside class="sidebar">
        <a class="brand" href="{{ route('home') }}">
            <div class="brand-mark"><x-icon name="plane" :size="22" /></div>
            <div class="brand-copy">
                <strong>Airline Empire</strong>
                <span>Airline Management</span>
            </div>
        </a>

        <nav class="nav" aria-label="Hauptnavigation">
            @foreach($navGroups as $group => $items)
                <div class="nav-group">
                    <div class="nav-group-label">{{ $group }}</div>
                    <div class="nav-group-items">
                        @foreach($items as $item)
                            <a class="nav-link {{ request()->routeIs($item['match']) ? 'active' : '' }}" href="{{ route($item['route']) }}">
                                <span class="nav-icon"><x-icon :name="$item['icon']" :size="18" /></span>
                                <span>{{ $item['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </nav>

        <div class="side-spacer"></div>

        <div class="sidebar-status">
            <span class="sidebar-status-dot"></span>
            <div>
                <strong>Simulation aktiv</strong>
                <small>Shared-Hosting ready</small>
            </div>
        </div>

        <div class="userbox">
            <div class="user-avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</div>
            <div class="user-copy">
                <strong>{{ auth()->user()->name }}</strong>
                <small>{{ '@'.auth()->user()->username }}</small>
            </div>
            <form method="post" action="{{ route('logout') }}">
                @csrf
                <button class="icon-button" type="submit" title="Abmelden" aria-label="Abmelden"><x-icon name="logout" :size="18" /></button>
            </form>
        </div>
    </aside>

    <main class="content">
        <header class="topbar">
            <div class="page-heading">
                <span class="eyebrow">@yield('eyebrow', 'AIRLINE OPERATIONS')</span>
                <h1>@yield('heading', 'Command Center')</h1>
                @hasSection('subheading')<div class="muted page-subheading">@yield('subheading')</div>@endif
            </div>
            <div class="topbar-actions">
                <span class="status-pill"><span class="status-dot"></span>System online</span>
                <a class="icon-button" href="{{ route('map.index') }}" title="Weltkarte"><x-icon name="map" :size="18" /></a>
            </div>
        </header>

        @if(session('success'))
            <div class="flash"><x-icon name="status" :size="18" /><span>{{ session('success') }}</span></div>
        @endif

        @if($errors->any())
            <div class="errors">
                <strong>Bitte prüfe deine Eingaben.</strong>
                <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        @yield('content')
    </main>
</div>
@else
    @yield('guest')
@endif
<script src="{{ asset('assets/airline-empire.js') }}" defer></script>
</body>
</html>
