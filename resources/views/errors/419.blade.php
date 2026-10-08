@extends('errors.layout')

@section('code', '419')
@section('title', "Page expirée")
@section('message', "La page est restée ouverte trop longtemps et votre action n'a pas été enregistrée. Revenez en arrière, rechargez la page et recommencez.")
@section('actions')
    <a class="btn btn-secondary" href="{{ url()->previous() }}">Revenir en arrière</a>
@endsection
