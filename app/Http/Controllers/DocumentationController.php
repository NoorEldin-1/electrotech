<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\DocumentationOutline;
use Illuminate\Http\Response;

/**
 * Public, unauthenticated documentation of every module in the system
 * (/documentation).
 *
 * Deliberately outside the Filament panel and outside the `auth` middleware:
 * the link is meant to be shared (WhatsApp / email) with people who do not
 * have — and should not need — an account. Nothing here reads business data;
 * the page is a static, hand-written manual, so exposing it leaks nothing.
 *
 * The page always renders in Arabic RTL regardless of the visitor's session
 * locale: it is written in one language on purpose (no language switcher).
 */
class DocumentationController extends Controller
{
    public function __invoke(): Response
    {
        $response = response()->view('documentation.index', [
            'groups' => DocumentationOutline::groups(),
            'sectionCount' => DocumentationOutline::sectionCount(),
            'departmentCount' => DocumentationOutline::departmentCount(),
        ]);

        // Static content — let shared caches and the browser keep it. The
        // page changes only on deploy, and the asset URLs below are
        // cache-busted by ?v=, so a stale HTML copy is harmless.
        return $response->header('Cache-Control', 'public, max-age=1800');
    }
}
