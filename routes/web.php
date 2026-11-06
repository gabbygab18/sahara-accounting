<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\SheetController;
use App\Http\Controllers\UserController;
use App\Models\Record;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::patch('dashboard/booklet', [DashboardController::class, 'booklet'])->middleware('can:dashboard')->name('dashboard.booklet');
    // Polled every second by every open page: a fingerprint of the records, so pages reload only when it changes.
    Route::get('live', fn () => response()->json(['v' => md5(Record::withTrashed()->max('updated_at').'|'.Record::count())]))->name('live');

    // Each needs its box ticked on Settings → Users (User::PERMISSIONS).
    Route::get('records/export', [RecordController::class, 'export'])->middleware('can:export')->name('records.export');
    // Company, project and status suggestions for the add / edit form, wherever it is opened.
    Route::get('records/options', [RecordController::class, 'options'])->name('records.options');
    Route::get('records', [RecordController::class, 'index'])->middleware('can:records')->name('records.index');
    Route::post('records', [RecordController::class, 'store'])->middleware('can:add')->name('records.store');
    Route::put('records/{record}', [RecordController::class, 'update'])->middleware('can:edit')->name('records.update');
    Route::patch('records/pay', [RecordController::class, 'payMany'])->middleware('can:edit')->name('records.pay-many');
    Route::patch('records/{record}/pay', [RecordController::class, 'pay'])->middleware('can:edit')->name('records.pay');
    Route::patch('records/confirm', [RecordController::class, 'confirmMany'])->middleware('can:confirm')->name('records.confirm-many');
    Route::patch('records/{record}/confirm', [RecordController::class, 'confirm'])->middleware('can:confirm')->name('records.confirm');
    Route::delete('records/{record}', [RecordController::class, 'destroy'])->middleware('can:delete')->name('records.destroy');

    Route::get('history', [HistoryController::class, 'index'])->middleware('can:history')->name('history.index');

    Route::middleware('can:settings')->group(function () {
        Route::get('settings/sheets', [SheetController::class, 'show'])->name('sheets.show');
        Route::post('sheets/sync', [SheetController::class, 'sync'])->name('sheets.sync');
        Route::get('settings/sheets/connect', [SheetController::class, 'connect'])->name('sheets.connect');
        Route::get('settings/sheets/callback', [SheetController::class, 'callback'])->name('sheets.callback');
        Route::post('settings/sheets/link', [SheetController::class, 'link'])->name('sheets.link');
        Route::delete('settings/sheets', [SheetController::class, 'disconnect'])->name('sheets.disconnect');

        Route::resource('settings/users', UserController::class)->only(['index', 'store', 'update'])->names('users');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
