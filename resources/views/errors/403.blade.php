@extends('errors.layout')

@section('code', '403')
@section('title', "Accès refusé")
@section('message')
    {{ $exception->getMessage() ?: "Vous n'avez pas accès à cette page." }}
@endsection
