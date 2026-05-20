<?php

declare(strict_types=1);

/**
 * Network-resilience layer toggles.
 *
 * The layer is designed for the factory production deployment on a weak
 * link; on local dev (`APP_ENV=local`) it is OFF by default so it cannot
 * mask local Livewire / Alpine / Vite issues with stale service workers
 * or compressed dev-server responses.
 *
 * Override in .env:
 *   NETWORK_RESILIENCE_ENABLED=true     # force on (e.g. staging)
 *   NETWORK_RESILIENCE_ENABLED=false    # force off
 *
 * NOTE: do NOT call `app()` here. Config files load during a phase where
 * the Application instance is mid-construction and the container can
 * misresolve helper calls. Read APP_ENV directly via env() instead.
 */

$appEnv = env('APP_ENV', 'production');
$nonLocalDefault = $appEnv !== 'local';

return [

    /*
     * Master switch. When false, the AdminPanelProvider injects only a
     * tiny kill-switch script that unregisters any previously registered
     * Service Worker and clears its caches — so flipping this off cleans
     * up a stale install on the user's next reload.
     */
    'enabled' => env('NETWORK_RESILIENCE_ENABLED', $nonLocalDefault),

    /*
     * HTTP response compression (CompressResponse middleware). PHP's
     * built-in `artisan serve` does not always cleanly handle binary
     * bodies with Content-Encoding, so this defaults off in local even
     * when the master switch is forced on.
     */
    'compress_responses' => env('NETWORK_RESILIENCE_COMPRESS', $nonLocalDefault),

    /*
     * Service Worker registration. Even when the resilience JS is
     * loaded, the SW can be disabled here so we keep idempotency + draft
     * autosave but never intercept any fetch from the SW layer.
     */
    'service_worker' => env('NETWORK_RESILIENCE_SW', true),

    /*
     * Server-side Idempotency-Key replay deduplication. Should normally
     * stay on — it is cheap and only affects requests that send the
     * header, which is opt-in from the client.
     */
    'idempotency' => env('NETWORK_RESILIENCE_IDEMPOTENCY', true),

];
