<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#06101d">
    <title>@yield('title', 'Airline Empire')</title>
    <link rel="stylesheet" href="{{ asset('assets/airline-empire.css') }}">
</head>
<body>
@if(auth()->check())
<div class="shell">
    <aside class="sidebar">
        <a class="brand" href="{{ route('home') }}">
            <div class="brand-mark">AE</div>
            <div>
                <strong>Airline Empire</strong>
                <span>Operations Platform</span>
            </div>
        </a>

        <nav class="nav" aria-label="Hauptnavigation">
            <a class="{{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('home') }}">Dashboard</a>
            <a class="{{ request()->routeIs('worlds.*') ? 'active' : '' }}" href="{{ route('worlds.index') }}">Spielwelten</a>
        </nav>

        <div class="side-spacer"></div>

        <div class="userbox">
            <strong>{{ auth()->user()->name }}</strong>
            <small>{{ '@'.auth()->user()->username }}</small>
            <form method="post" action="{{ route('logout') }}">
                @csrf
                <button class="button ghost logout" type="submit">Abmelden</button>
            </form>
        </div>
    </aside>

    <main class="content">
        <header class="topbar">
            <div>
                <span class="eyebrow">@yield('eyebrow', 'AIRLINE OPERATIONS')</span>
                <h1>@yield('heading', 'Command Center')</h1>
                @hasSection('subheading')<div class="muted">@yield('subheading')</div>@endif
            </div>
            <span class="status-pill">System online</span>
        </header>

        @if(session('success'))
            <div class="flash">{{ session('success') }}</div>
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
</body>
</html>
