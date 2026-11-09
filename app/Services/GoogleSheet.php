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
     * The look of these tabs. Safe to repeat: its own colour rules and the banding are replaced, not stacked.
     *
     * @param  array{columns: int, colors: array<int, array<string, string>>, lists: array<int, list<string>>, money: list<int>,
     *     centered: list<int>, dates: list<int>, sections: list<array{int, int, string}>}  $style  by column index:
     *     colors = cell text => background "#rrggbb", lists = dropdown choices (other values still allowed), money = ₱1,234.50
     *     right-aligned, centered, dates = 03/10/2026, sections = [first, last, header colour]; the rest sits top-left
     */
    public function styleTabs(array $tabs, array $style): void
    {
        $sheets = collect($this->http()->get(self::API.$this->spreadsheetId, ['fields' => 'sheets(properties(sheetId,title),conditionalFormats,bandedRanges.bandedRangeId)'])->throw()->json('sheets'))
            ->keyBy('properties.title');
        $rgb = function (string $hex) {
            [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

            return ['red' => $r / 255, 'green' => $g / 255, 'blue' => $b / 255];
        };
        $requests = [];
        foreach ($tabs as $tab) {
            if (! $sheet = $sheets->get($tab)) {
                continue;
            }
            $id = $sheet['properties']['sheetId'];
            $column = fn (int $col) => ['sheetId' => $id, 'startRowIndex' => 1, 'startColumnIndex' => $col, 'endColumnIndex' => $col + 1];
            $format = fn (array $range, array $format, string $fields) => ['repeatCell' => ['range' => $range, 'cell' => ['userEnteredFormat' => $format], 'fields' => "userEnteredFormat({$fields})"]];

            foreach (array_reverse($sheet['conditionalFormats'] ?? [], true) as $i => $rule) {
                $text = $rule['booleanRule']['condition']['values'][0]['userEnteredValue'] ?? null;
                if (isset($style['colors'][$rule['ranges'][0]['startColumnIndex'] ?? -1][$text])) {
                    $requests[] = ['deleteConditionalFormatRule' => ['sheetId' => $id, 'index' => $i]];
                }
            }
            foreach ($sheet['bandedRanges'] ?? [] as $band) {
                $requests[] = ['deleteBanding' => ['bandedRangeId' => $band['bandedRangeId']]];
            }
            foreach ($style['colors'] as $col => $map) {
                foreach ($map as $text => $hex) {
                    $requests[] = ['addConditionalFormatRule' => ['index' => 0, 'rule' => [
                        'ranges' => [$column($col)],
                        'booleanRule' => ['condition' => ['type' => 'TEXT_EQ', 'values' => [['userEnteredValue' => $text]]], 'format' => ['backgroundColor' => $rgb($hex)]],
                    ]]];
                }
            }

            // Every cell top-left and wrapped (some hold one DR / SI per line), then the exceptions.
            $requests[] = $format(['sheetId' => $id], ['verticalAlignment' => 'TOP', 'horizontalAlignment' => 'LEFT', 'wrapStrategy' => 'WRAP'], 'verticalAlignment,horizontalAlignment,wrapStrategy');
            foreach ($style['money'] as $col) {
                $requests[] = $format($column($col), ['numberFormat' => ['type' => 'CURRENCY', 'pattern' => '"₱"#,##0.00'], 'horizontalAlignment' => 'RIGHT'], 'numberFormat,horizontalAlignment');
            }
            foreach ($style['dates'] as $col) {
                $requests[] = $format($column($col), ['numberFormat' => ['type' => 'DATE', 'pattern' => 'mm/dd/yyyy']], 'numberFormat');
            }
            foreach ($style['centered'] as $col) {
                $requests[] = $format($column($col), ['horizontalAlignment' => 'CENTER'], 'horizontalAlignment');
            }

            // Header: white bold on a colour per section (PO, DR, SI, payment), a little taller.
            foreach ($style['sections'] as [$from, $to, $hex]) {
                $requests[] = $format(
                    ['sheetId' => $id, 'startRowIndex' => 0, 'endRowIndex' => 1, 'startColumnIndex' => $from, 'endColumnIndex' => $to + 1],
                    ['backgroundColor' => $rgb($hex), 'verticalAlignment' => 'MIDDLE', 'textFormat' => ['bold' => true, 'foregroundColor' => $rgb('#ffffff')]],
                    'backgroundColor,verticalAlignment,textFormat',
                );
            }
            $requests[] = ['updateDimensionProperties' => ['range' => ['sheetId' => $id, 'dimension' => 'ROWS', 'startIndex' => 0, 'endIndex' => 1], 'properties' => ['pixelSize' => 36], 'fields' => 'pixelSize']];

            // Header and company stay put when scrolling; rows in alternating shades; a filter on the header.
            $requests[] = ['updateSheetProperties' => ['properties' => ['sheetId' => $id, 'gridProperties' => ['frozenRowCount' => 1, 'frozenColumnCount' => 1]], 'fields' => 'gridProperties(frozenRowCount,frozenColumnCount)']];
            $requests[] = ['addBanding' => ['bandedRange' => [
                'range' => ['sheetId' => $id, 'startRowIndex' => 1, 'startColumnIndex' => 0, 'endColumnIndex' => $style['columns']],
                'rowProperties' => ['firstBandColor' => $rgb('#ffffff'), 'secondBandColor' => $rgb('#f3f4f6')],
            ]]];
            // Through hidden column Z, so sorting with it moves each row's id along with the row.
            $requests[] = ['setBasicFilter' => ['filter' => ['range' => ['sheetId' => $id, 'startRowIndex' => 0, 'startColumnIndex' => 0, 'endColumnIndex' => 26]]]];

            foreach ($style['lists'] as $col => $values) {
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

    /**
     * Replaces everything in a tab with these rows (the first is the header). A missing tab is made as the first tab,
     * with whole rows coloured by the text in one column. The rest of its look comes from styleTabs.
     *
     * @param  array{int, array<string, string>}  $flag  [column index, cell text => row background "#rrggbb"]
     * @return bool whether the tab was made
     */
    public function fillTab(string $tab, array $rows, array $flag): bool
    {
        $url = self::API.$this->spreadsheetId;
        $props = collect($this->http()->get($url, ['fields' => 'sheets.properties'])->throw()->json('sheets'))
            ->pluck('properties')->first(fn ($p) => mb_strtolower($p['title']) === mb_strtolower($tab));
        $requests = [];
        if ($made = ! $props) {
            $props = $this->http()->post($url.':batchUpdate', ['requests' => [['addSheet' => ['properties' => ['title' => $tab, 'index' => 0]]]]])
                ->throw()->json('replies.0.addSheet.properties');
            [$col, $colors] = $flag;
            foreach ($colors as $text => $hex) {
                [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
                $requests[] = ['addConditionalFormatRule' => ['index' => 0, 'rule' => [
                    'ranges' => [['sheetId' => $props['sheetId'], 'startRowIndex' => 1, 'startColumnIndex' => 0, 'endColumnIndex' => count($rows[0])]],
                    'booleanRule' => [
                        'condition' => ['type' => 'CUSTOM_FORMULA', 'values' => [['userEnteredValue' => '=$'.chr(65 + $col).'2="'.$text.'"']]],
                        'format' => ['backgroundColor' => ['red' => $r / 255, 'green' => $g / 255, 'blue' => $b / 255]],
                    ],
                ]]];
            }
        }
        // Room for every row (writing past the grid is refused), then the old cells cleared and the new ones written.
        $requests[] = ['updateSheetProperties' => ['properties' => ['sheetId' => $props['sheetId'], 'gridProperties' => ['rowCount' => max(count($rows) + 20, 100)]], 'fields' => 'gridProperties.rowCount']];
        // Column Z holds the record ids: there and hidden.
        if (($missing = 26 - ($props['gridProperties']['columnCount'] ?? 26)) > 0) {
            $requests[] = ['appendDimension' => ['sheetId' => $props['sheetId'], 'dimension' => 'COLUMNS', 'length' => $missing]];
        }
        $requests[] = ['updateDimensionProperties' => ['range' => ['sheetId' => $props['sheetId'], 'dimension' => 'COLUMNS', 'startIndex' => 25, 'endIndex' => 26], 'properties' => ['hiddenByUser' => true], 'fields' => 'hiddenByUser']];
        $this->http()->post($url.':batchUpdate', ['requests' => $requests])->throw();
        $this->http()->post($url.'/values:batchClear', ['ranges' => [self::range($props['title'], 'A:Z')]])->throw();
        $this->http()->put($url.'/values/'.rawurlencode(self::range($props['title'], 'A1')).'?valueInputOption=USER_ENTERED', [
            'values' => array_map(fn ($row) => array_map(fn ($v) => $v ?? '', $row), $rows),
        ])->throw();

        return $made;
    }

    /**
     * Fits the header columns of these tabs to what is in them. Google's auto-fit measures text as if not bold, so the
     * bold headers would be cut off: each column is then made at least as wide as its header, plus some room, and at
     * most 280px (longer names wrap).
     */
    public function fitColumns(array $tabs, array $headers): void
    {
        $url = self::API.$this->spreadsheetId;
        $ids = collect($this->http()->get($url, ['fields' => 'sheets.properties(sheetId,title)'])->throw()->json('sheets'))
            ->mapWithKeys(fn ($s) => [$s['properties']['title'] => $s['properties']['sheetId']])
            ->only($tabs);
        if ($ids->isEmpty()) {
            return;
        }
        $this->http()->post($url.':batchUpdate', ['requests' => $ids->map(fn ($id) => ['autoResizeDimensions' => [
            'dimensions' => ['sheetId' => $id, 'dimension' => 'COLUMNS', 'startIndex' => 0, 'endIndex' => count($headers)],
        ]])->values()->all()])->throw();

        $widths = collect($this->http()->get($url, ['fields' => 'sheets(properties.title,data.columnMetadata.pixelSize)'])->throw()->json('sheets'))
            ->mapWithKeys(fn ($s) => [$s['properties']['title'] => array_column($s['data'][0]['columnMetadata'] ?? [], 'pixelSize')]);
        $requests = [];
        foreach ($ids as $tab => $id) {
            foreach (array_values($headers) as $i => $header) {
                $requests[] = ['updateDimensionProperties' => [
                    'range' => ['sheetId' => $id, 'dimension' => 'COLUMNS', 'startIndex' => $i, 'endIndex' => $i + 1],
                    // ponytail: ~9px a bold capital at the default font; measure text if fonts change
                    'properties' => ['pixelSize' => min(max(($widths[$tab][$i] ?? 100) + 24, mb_strlen($header) * 9 + 28), 280)],
                    'fields' => 'pixelSize',
                ]];
            }
        }
        $this->http()->post($url.':batchUpdate', ['requests' => $requests])->throw();
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
