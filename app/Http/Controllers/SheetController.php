<?php

namespace App\Http\Controllers;

use App\Models\SyncLog;
use App\Services\GoogleAccessRevoked;
use App\Services\GoogleSheet;
use App\Services\SheetSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Settings → Google Sheets: connect Google once, paste the sheet link, watch the sync. */
class SheetController extends Controller
{
    public function show(): Response
    {
        $sheet = GoogleSheet::fromConfig();
        $title = null;
        $problem = null;
        if ($sheet) {
            try {
                $title = $sheet->title();
            } catch (GoogleAccessRevoked $e) {
                $problem = $e->getMessage();
            } catch (Throwable $e) {
                $problem = 'The sheet could not be opened with '.(GoogleSheet::account() ?? 'this Google account').'. Check that it has edit access to the sheet. ('.Str::limit($e->getMessage(), 200).')';
            }
        }

        return Inertia::render('settings/sheets', [
            'ready' => GoogleSheet::ready(),
            'account' => GoogleSheet::signedIn() ? GoogleSheet::account() : null,
            'sheetId' => GoogleSheet::sheetId(),
            'title' => $title,
            'redirectUri' => GoogleSheet::redirectUri(),
            'problem' => $problem,
            'last' => Cache::get('sheets-last-sync'),
            'logs' => SyncLog::latest('id')->limit(20)->get(),
        ]);
    }

    public function connect(Request $request): RedirectResponse
    {
        abort_unless(GoogleSheet::ready(), 404);
        // Checked on the way back, so nobody can hand us a code of their own.
        $state = Str::random(40);
        $request->session()->put('google.state', $state);

        return redirect()->away(GoogleSheet::authUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $back = to_route('sheets.show');
        if ($request->query('error')) {
            return $back->withErrors(['google' => 'Google was not connected: permission was not given.']);
        }
        $expected = $request->session()->pull('google.state');
        if (! $expected || ! hash_equals($expected, (string) $request->query('state')) || ! $request->query('code')) {
            return $back->withErrors(['google' => 'That sign-in could not be checked. Press Connect Google again.']);
        }

        try {
            $email = GoogleSheet::connect((string) $request->query('code'));
        } catch (\InvalidArgumentException $e) {
            return $back->withErrors(['google' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);

            return $back->withErrors(['google' => 'Google could not be connected. Press Connect Google again.']);
        }

        return $back->with('success', "Google connected as {$email}.");
    }

    public function link(Request $request): RedirectResponse
    {
        $request->validate(['link' => ['required', 'string', 'max:500']]);
        if (! GoogleSheet::setSheet($request->link)) {
            return back()->withErrors(['link' => "Paste the Google Sheet's link, starting with https://docs.google.com/spreadsheets/"]);
        }

        return back()->with('success', 'Sheet saved. The first sync starts within 15 seconds, or press Sync now.');
    }

    public function disconnect(): RedirectResponse
    {
        GoogleSheet::disconnect();

        return back()->with('success', 'Google disconnected. The app keeps working; it just stops syncing.');
    }

    public function sync(): RedirectResponse
    {
        $log = SheetSync::make()?->run();
        if (! $log) {
            return back();
        }

        return $log->error
            ? back()->withErrors(['sync' => $log->error])
            : back()->with('success', "Synced: {$log->added} new, {$log->updated} changed and {$log->deleted} removed in the sheet; {$log->pushed} written to it.");
    }
}
