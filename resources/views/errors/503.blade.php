{{-- E2E report §4.3 — see errors/layout.blade.php. The layout resolves the
     locale first, then looks the strings up, so nothing is translated here. --}}
@include('errors.layout', ['code' => 503])
