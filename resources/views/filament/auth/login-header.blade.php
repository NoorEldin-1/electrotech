{{--
    Form-side header — injected above the login form (AUTH_LOGIN_FORM_BEFORE).
    Replaces the default in-card brand block (hidden via custom-login.css).
    The compact logo only shows on small screens where the showcase is hidden.
--}}
<div class="et-login-formhead">
    <span class="et-login-formhead-logo">
        <img src="{{ asset('images/electrotech-logo.jpg') }}" alt="ElectroTech Orwa" />
    </span>
    <h2 class="et-login-formhead-title">{{ __('login.welcome') }}</h2>
    <p class="et-login-formhead-subtitle">{{ __('login.subtitle') }}</p>
</div>
