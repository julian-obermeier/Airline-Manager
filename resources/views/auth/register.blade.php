@extends('layouts.app')

@section('title', 'Registrieren · Airline Empire')

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

        <span class="eyebrow">NEUER ACCOUNT</span>
        <h1>Airline-Karriere starten</h1>
        <p class="muted">Erstelle deinen globalen Spieleraccount. Danach wählst du eine Spielwelt und gründest dort deine Airline.</p>

        @if($errors->any())
            <div class="errors">
                <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="post" action="{{ route('register.store') }}">
            @csrf
            <div class="form-grid">
                <div class="field full">
                    <label for="name">Anzeigename</label>
                    <input id="name" name="name" value="{{ old('name') }}" maxlength="120" required autofocus>
                </div>
                <div class="field">
                    <label for="username">Benutzername</label>
                    <input id="username" name="username" value="{{ old('username') }}" maxlength="60" autocomplete="username" required>
                </div>
                <div class="field">
                    <label for="email">E-Mail-Adresse</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
                </div>
                <div class="field">
                    <label for="password">Passwort</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" required>
                    <span class="help">Mindestens 10 Zeichen, Buchstaben und Zahlen.</span>
                </div>
                <div class="field">
                    <label for="password_confirmation">Passwort wiederholen</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
                </div>
            </div>

            <div class="auth-actions">
                <a class="muted" href="{{ route('login') }}">Bereits registriert?</a>
                <button class="button primary" type="submit">Account erstellen</button>
            </div>
        </form>
    </section>
</div>
@endsection
