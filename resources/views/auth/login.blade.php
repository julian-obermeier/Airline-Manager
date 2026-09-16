@extends('layouts.app')

@section('title', 'Anmelden · Airline Empire')

@section('guest')
<div class="auth-wrap">
    <section class="auth-card">
        <div class="brand">
            <div class="brand-mark">AE</div>
            <div>
                <strong>Airline Empire</strong>
                <span>Operations Platform</span>
            </div>
        </div>

        <span class="eyebrow">ACCOUNT</span>
        <h1>Anmelden</h1>
        <p class="muted">Melde dich mit Benutzername oder E-Mail-Adresse an und führe deine Airline weiter.</p>

        @if($errors->any())
            <div class="errors">
                <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="post" action="{{ route('login.store') }}">
            @csrf
            <div class="field">
                <label for="login">Benutzername oder E-Mail</label>
                <input id="login" name="login" value="{{ old('login') }}" autocomplete="username" required autofocus>
            </div>

            <div class="field" style="margin-top:14px">
                <label for="password">Passwort</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
            </div>

            <label style="display:flex;gap:9px;align-items:center;margin-top:14px;color:var(--muted)">
                <input type="checkbox" name="remember" value="1"> Angemeldet bleiben
            </label>

            <div class="auth-actions">
                <a class="muted" href="{{ route('register') }}">Noch keinen Account?</a>
                <button class="button primary" type="submit">Anmelden</button>
            </div>
        </form>
    </section>
</div>
@endsection
