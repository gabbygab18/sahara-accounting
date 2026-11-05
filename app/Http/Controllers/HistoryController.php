<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Record;
use Inertia\Inertia;
use Inertia\Response;

/** Activity log: every record added, edited and deleted, in the app or the Google Sheet, newest first. */
class HistoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('history/index', [
            'activities' => Activity::with('user:id,name')->latest('id')->paginate(30)
                ->through(fn (Activity $a) => [...$a->only('id', 'record_id', 'action', 'summary', 'changes', 'created_at'), 'user' => $a->user?->name]),
            'labels' => Record::COLUMNS + ['paid_confirmed' => 'PAYMENT CONFIRMED'],
        ]);
    }
}
