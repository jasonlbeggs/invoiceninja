@extends('portal.ninja2020.layout.app')
@section('meta_title', ctrans('texts.invoices'))

@section('body')
    @livewire('invoices-table', ['company_id' => $company->id, 'db' => $company->db])
@endsection
