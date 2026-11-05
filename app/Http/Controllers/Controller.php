<?php

namespace App\Http\Controllers;

use App\Services\SheetSync;

abstract class Controller
{
    /** Sync with the Google Sheet right after the response is sent (no-op until it is set up). */
    protected function syncSheetSoon(): void
    {
        if (SheetSync::make()) {
            dispatch(fn () => SheetSync::make()?->run())->afterResponse();
        }
    }
}
