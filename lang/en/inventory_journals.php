<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Automatic inventory journals (Final_Modifications slides 2-3)
|--------------------------------------------------------------------------
|
| Description of the entry posted automatically with every document that moves
| stock (InventoryJournalService).
|
*/
return [
    'addition' => 'Stock received on addition voucher :number',
    'issue' => 'Materials issued to production on issue voucher :number (WO #:wo)',
    'return' => 'Materials returned from production on return voucher :number (WO #:wo)',
    'finish' => 'Manufacturing of WO #:wo finished — cost moved from work in progress to finished goods',
    'reversal' => 'Reversal of entry :number',
];
