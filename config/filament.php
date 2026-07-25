<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Filament overrides
|--------------------------------------------------------------------------
|
| Partial config: Filament merges this on top of its own defaults, so only
| the keys we deliberately change are listed here.
|
*/

return [

    /*
    | Filament waits 200ms ("default") before showing a button's loading
    | spinner. On a fast/local connection most actions answer sooner than
    | that, so clicking a row action gave no feedback at all. 'shortest'
    | (50ms) still swallows the flicker of instant responses but makes the
    | spinner visible on anything that takes real work.
    */
    'livewire_loading_delay' => 'shortest',

];
