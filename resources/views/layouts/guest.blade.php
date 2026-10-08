@extends('layouts.base')

@section('body')
    <main class="auth">
        <div class="auth-card">
            <div class="auth-brand">
                <img src="{{ asset('icons/icon-192.png') }}" alt="" width="72" height="72" style="border-radius:18px">
                <h1>Argent</h1>
                <p class="slogan">Vos comptes perso et pro</p>
            </div>
            <div class="card">
                @if (session('status'))
                    <div class="alert alert-success" role="status">{{ session('status') }}</div>
                @endif
                @yield('content')
            </div>
        </div>
    </main>
@endsection
