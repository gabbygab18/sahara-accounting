<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Google Sheets REST client, signed in as a person (same approach as the Isla portal):
 * someone with edit access to the sheet presses Connect Google once, and the refresh token
 * Google hands back is kept encrypted. The sheet's link is pasted in Settings → Google Sheets.
 */
class GoogleSheet
{
    private const API = 'https://sheets.googleapis.com/v4/spreadsheets/';

    private const TOKEN = 'google.refresh_token';

    private const ACCOUNT = 'google.account';

    private const SHEET = 'google.sheet_id';

    public function __construct(public readonly string $spreadsheetId) {}

    /** The OAuth client is set on the server (.env); everything else in the app. */
    public static function ready(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public static function signedIn(): bool
    {
        return self::ready() && filled(self::refreshToken());
    }

    /** Null until someone has connected Google and pasted the sheet link. */
    public static function fromConfig(): ?static
    {
        $id = Setting::get(self::SHEET);

        return self::signedIn() && $id ? new static($id) : null;
    }

    public static function account(): ?string
    {
        return Setting::get(self::ACCOUNT) ?: null;
    }

    public static function sheetId(): ?string
    {
        return Setting::get(self::SHEET) ?: null;
    }

    /** The whole link or the bare id, kept as the id. Returns null when it is not a Google Sheet link. */
    public static function setSheet(string $link): ?string
    {
        $link = trim($link);
        $id = preg_match('#docs\.google\.com/spreadsheets/d/([\w-]{20,})#', $link, $m) ? $m[1]
            : (preg_match('/^[\w-]{30,}$/', $link) ? $link : null);
        if ($id) {
            Setting::set(self::SHEET, $id);
        }

        return $id;
    }

    /** Where the Connect button sends someone to say yes. */
    public static function authUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/spreadsheets email',
            // A refresh token every time: Google only hands one over on consent.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    /** What Google sent back, swapped for a refresh token and kept. Returns the account's email. */
    public static function connect(string $code): string
    {
        $tokens = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
        ])->throw()->json();
        if (empty($tokens['refresh_token'])) {
            throw new \RuntimeException('Google did not hand over a refresh token.');
        }
        // Google's consent screen lets people untick the Sheets permission; without it every read fails with 403.
        if (! str_contains($tokens['scope'] ?? '', 'auth/spreadsheets')) {
            throw new \InvalidArgumentException('Google was connected without permission to the sheets. Press Connect Google again and tick the box "See, edit, create and delete all your Google Sheets spreadsheets".');
        }

        Setting::set(self::TOKEN, Crypt::encryptString($tokens['refresh_token']));
        Cache::put('google.access_token', $tokens['access_token'], 3000);
        $email = (string) Http::withToken($tokens['access_token'])->get('https://openidconnect.googleapis.com/v1/userinfo')->json('email');
        Setting::set(self::ACCOUNT, $email);

        return $email;
    }

    /** Forgotten here, and the permission handed back to Google. */
    public static function disconnect(): void
    {
        if ($token = self::refreshToken()) {
            rescue(fn () => Http::asForm()->post('https://oauth2.googleapis.com/revoke', ['token' => $token]), report: false);
        }
        Setting::set(self::TOKEN, null);
        Setting::set(self::ACCOUNT, null);
        Cache::forget('google.access_token');
    }

    /** Must match a redirect URI on the OAuth client exactly. Set GOOGLE_REDIRECT_URI when browsing on another host. */
    public static function redirectUri(): string
    {
        return config('services.google.redirect') ?: route('sheets.callback');
    }

    private static function refreshToken(): ?string
    {
        try {
            return ($stored = Setting::get(self::TOKEN)) ? Crypt::decryptString($stored) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function title(): string
    {
        return $this->http()->get(self::API.$this->spreadsheetId, ['fields' => 'properties.title'])->throw()->json('properties.title');
    }

    /**
     * Reads ranges like "'Cityland'!A2:Z". Returns range => [sheet row number => cells], where numbers are
     * numbers, date cells are Carbon (told apart by how the sheet displays them) and the rest text.
     *
     * @param  string[]  $ranges
     * @return array<string, array<int, array>>
     */
    public function read(array $ranges): array
    {
        $raw = $this->batchGet($ranges, 'UNFORMATTED_VALUE');
        $shown = $this->batchGet($ranges, 'FORMATTED_VALUE');
        $out = [];

        foreach ($ranges as $i => $range) {
            $first = preg_match('/![A-Z]+(\d+)/', $range, $m) ? (int) $m[1] : 1;
            $rows = [];
            foreach ($raw[$i] as $r => $cells) {
                $rows[$first + $r] = array_map(
                    fn ($value, $c) => is_numeric($value) && self::looksLikeDate($shown[$i][$r][$c] ?? '')
                        ? Carbon::create(1899, 12, 30)->addDays((int) floor($value)) // Sheets serial date
                        : $value,
                    $cells,
                    array_keys($cells),
                );
            }
            $out[$range] = $rows;
        }

        return $out;
    }

    /**
     * Writes cells as if typed by a person (so "2026-10-22" becomes a date and "=SUMIFS(...)" a formula).
     *
     * @param  array<string, array>  $rows  range => one row of values
     */
    public function write(array $rows): void
    {
        foreach (array_chunk($rows, 500, true) as $chunk) {
            $this->http()->post(self::API.$this->spreadsheetId.'/values:batchUpdate', [
                'valueInputOption' => 'USER_ENTERED',
                'data' => collect($chunk)->map(fn ($values, $range) => [
                    'range' => $range,
                    'values' => [array_map(fn ($v) => $v ?? '', array_values($values))],
                ])->values()->all(),
            ])->throw();
        }
    }

    /** Removes whole rows (sheet row numbers per tab), bottom first so the numbers above stay right. */
    public function deleteRows(array $rowsByTab): void
    {
        $ids = collect($this->http()->get(self::API.$this->spreadsheetId, ['fields' => 'sheets.properties(sheetId,title)'])->throw()->json('sheets'))
            ->mapWithKeys(fn ($s) => [$s['properties']['title'] => $s['properties']['sheetId']]);
        $requests = [];
        foreach ($rowsByTab as $tab => $rows) {
            rsort($rows);
            foreach ($rows as $row) {
                $requests[] = ['deleteDimension' => ['range' => ['sheetId' => $ids[$tab], 'dimension' => 'ROWS', 'startIndex' => $row - 1, 'endIndex' => $row]]];
            }
        }
        if ($requests) {
            $this->http()->post(self::API.$this->spreadsheetId.':batchUpdate', ['requests' => $requests])->throw();
        }
    }

    /** "'Tab'!A2:Z", quoting the tab name the way Sheets wants (a ' inside is doubled). */
    public static function range(string $tab, string $cells): string
    {
        return "'".str_replace("'", "''", $tab)."'!".$cells;
    }

    /** @return list<string> the tab names, left to right */
    public function tabs(): array
    {
        return collect($this->http()->get(self::API.$this->spreadsheetId, ['fields' => 'sheets.properties.title'])->throw()->json('sheets'))
            ->pluck('properties.title')->all();
    }

    /**
     * Makes a tab (or takes over one of that name, any case) and returns its name: header row bold and frozen, and column Z
     * (the SYNC ID that ties each row to its record) labelled and hidden.
     */
    public function prepareTab(string $tab, array $headers): string
    {
        $props = collect($this->http()->get(self::API.$this->spreadsheetId, ['fields' => 'sheets.properties'])->throw()->json('sheets'))
            ->pluck('properties')->first(fn ($p) => mb_strtolower($p['title']) === mb_strtolower($tab));
        if (! $props) {
            $props = $this->http()->post(self::API.$this->spreadsheetId.':batchUpdate', ['requests' => [['addSheet' => ['properties' => ['title' => $tab]]]]])
                ->throw()->json('replies.0.addSheet.properties');
        }
        $id = $props['sheetId'];
        $requests = [];
        if (($missing = 26 - ($props['gridProperties']['columnCount'] ?? 26)) > 0) {
            $requests[] = ['appendDimension' => ['sheetId' => $id, 'dimension' => 'COLUMNS', 'length' => $missing]];
        }
        $requests[] = ['updateDimensionProperties' => ['range' => ['sheetId' => $id, 'dimension' => 'COLUMNS', 'startIndex' => 25, 'endIndex' => 26], 'properties' => ['hiddenByUser' => true], 'fields' => 'hiddenByUser']];
        $requests[] = ['updateSheetProperties' => ['properties' => ['sheetId' => $id, 'gridProperties' => ['frozenRowCount' => 1]], 'fields' => 'gridProperties.frozenRowCount']];
        $requests[] = ['repeatCell' => ['range' => ['sheetId' => $id, 'startRowIndex' => 0, 'endRowIndex' => 1], 'cell' => ['userEnteredFormat' => ['textFormat' => ['bold' => true]]], 'fields' => 'userEnteredFormat.textFormat.bold']];
        $this->http()->post(self::API.$this->spreadsheetId.':batchUpdate', ['requests' => $requests])->throw();
        $this->write([self::range($props['title'], 'A1') => $headers, self::range($props['title'], 'Z1') => ['SYNC ID']]);

        return $props['title'];
    }

    /**
     * Colours and dropdowns for status columns of these tabs. Safe to repeat: its own colour rules are
     * replaced, not stacked, and other rules are left alone.
     *
     * @param  array<int, array<string, string>>  $colors  column index => [cell text => background "#rrggbb"]
     * @param  array<int, list<string>>  $lists  column index => dropdown choices (other values still allowed)
     * @param  list<int>  $money  column indexes shown as ₱1,234.50
     */
    public function styleTabs(array $tabs, array $colors, array $lists, array $money = []): void
    {
        $sheets = collect($this->http()->get(self::API.$this->spreadsheetId, ['fields' => 'sheets(properties(sheetId,title),conditionalFormats)'])->throw()->json('sheets'))
            ->keyBy('properties.title');
        $requests = [];
        foreach ($tabs as $tab) {
            if (! $sheet = $sheets->get($tab)) {
                continue;
            }
            $id = $sheet['properties']['sheetId'];
            $column = fn (int $col) => ['sheetId' => $id, 'startRowIndex' => 1, 'startColumnIndex' => $col, 'endColumnIndex' => $col + 1];
            foreach (array_reverse($sheet['conditionalFormats'] ?? [], true) as $i => $rule) {
                $text = $rule['booleanRule']['condition']['values'][0]['userEnteredValue'] ?? null;
                if (isset($colors[$rule['ranges'][0]['startColumnIndex'] ?? -1][$text])) {
                    $requests[] = ['deleteConditionalFormatRule' => ['sheetId' => $id, 'index' => $i]];
                }
            }
            foreach ($colors as $col => $map) {
                foreach ($map as $text => $hex) {
                    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
                    $requests[] = ['addConditionalFormatRule' => ['index' => 0, 'rule' => [
                        'ranges' => [$column($col)],
                        'booleanRule' => [
                            'condition' => ['type' => 'TEXT_EQ', 'values' => [['userEnteredValue' => $text]]],
                            'format' => ['backgroundColor' => ['red' => $r / 255, 'green' => $g / 255, 'blue' => $b / 255]],
                        ],
                    ]]];
                }
            }
            foreach ($money as $col) {
                $requests[] = ['repeatCell' => [
                    'range' => $column($col),
                    'cell' => ['userEnteredFormat' => ['numberFormat' => ['type' => 'CURRENCY', 'pattern' => '"₱"#,##0.00']]],
                    'fields' => 'userEnteredFormat.numberFormat',
                ]];
            }
            foreach ($lists as $col => $values) {
                $requests[] = ['setDataValidation' => ['range' => $column($col), 'rule' => [
                    'condition' => ['type' => 'ONE_OF_LIST', 'values' => array_map(fn ($v) => ['userEnteredValue' => $v], $values)],
                    'showCustomUi' => true,
                    'strict' => false,
                ]]];
            }
        }
        if ($requests) {
            $this->http()->post(self::API.$this->spreadsheetId.':batchUpdate', ['requests' => $requests])->throw();
        }
    }

    /** Widens (or narrows) the first $count columns of these tabs to fit what is in them. */
    public function fitColumns(array $tabs, int $count): void
    {
        $ids = collect($this->http()->get(self::API.$this->spreadsheetId, ['fields' => 'sheets.properties(sheetId,title)'])->throw()->json('sheets'))
            ->mapWithKeys(fn ($s) => [$s['properties']['title'] => $s['properties']['sheetId']]);
        $requests = collect($tabs)->filter(fn ($tab) => isset($ids[$tab]))->map(fn ($tab) => ['autoResizeDimensions' => [
            'dimensions' => ['sheetId' => $ids[$tab], 'dimension' => 'COLUMNS', 'startIndex' => 0, 'endIndex' => $count],
        ]])->values()->all();
        if ($requests) {
            $this->http()->post(self::API.$this->spreadsheetId.':batchUpdate', ['requests' => $requests])->throw();
        }
    }

    public static function looksLikeDate(mixed $shown): bool
    {
        return is_string($shown) && preg_match('#^(\d{1,4}[/.-]\d{1,2}([/.-]\d{1,4})?|[A-Za-z]{3,9}\.? \d{1,2},? \d{4}|\d{1,2} [A-Za-z]{3,9},? \d{4})$#', trim($shown));
    }

    private function batchGet(array $ranges, string $render): array
    {
        $query = implode('&', [
            ...array_map(fn ($r) => 'ranges='.rawurlencode($r), $ranges),
            'valueRenderOption='.$render,
            'dateTimeRenderOption=SERIAL_NUMBER',
        ]);
        $ranges = $this->http()->get(self::API.$this->spreadsheetId.'/values:batchGet?'.$query)->throw()->json('valueRanges');

        return array_map(fn ($r) => $r['values'] ?? [], $ranges);
    }

    private function http(): PendingRequest
    {
        return Http::withToken(self::accessToken())->acceptJson()->timeout(30)
            ->retry(2, 500, function ($e, PendingRequest $request) {
                // A rejected access token (revoked early): drop it and retry once with a fresh one.
                if ($e instanceof RequestException && $e->response->status() === 401) {
                    Cache::forget('google.access_token');
                    $request->withToken(self::accessToken());
                }

                return true;
            }, throw: false);
    }

    /** Access tokens last an hour; reuse them for 50 minutes. */
    private static function accessToken(): string
    {
        return Cache::remember('google.access_token', 3000, function () {
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'refresh_token' => self::refreshToken(),
                'grant_type' => 'refresh_token',
            ]);
            if ($response->json('error') === 'invalid_grant') {
                throw new GoogleAccessRevoked(self::account());
            }

            return $response->throw()->json('access_token');
        });
    }
}
