<?php

namespace Tests\Feature;

use App\Services\GoogleSheet;
use Illuminate\Support\Carbon;

/** In-memory stand-in for the Google Sheet: tab => row number => cells (A = index 0, Z = 25). */
class FakeSheet extends GoogleSheet
{
    public array $tabs;

    public function __construct(array $tabs)
    {
        $this->tabs = $tabs;
    }

    public function read(array $ranges): array
    {
        $out = [];
        foreach ($ranges as $range) {
            preg_match("/^'(.+)'!A(\d+):([A-Z])$/", $range, $m);
            $rows = array_filter($this->tabs[str_replace("''", "'", $m[1])], fn ($row) => $row >= (int) $m[2], ARRAY_FILTER_USE_KEY);
            // Dense rows up to the last filled cell, like the API returns them.
            $out[$range] = array_map(function ($cells) use ($m) {
                $cells = array_filter($cells, fn ($c) => $c < ord($m[3]) - 64, ARRAY_FILTER_USE_KEY);

                return $cells ? array_replace(array_fill(0, max(array_keys($cells)) + 1, ''), $cells) : [];
            }, $rows);
        }

        return $out;
    }

    public function write(array $rows): void
    {
        foreach ($rows as $range => $values) {
            preg_match("/^'(.+)'!([A-Z])(\d+)/", $range, $m);
            $m[1] = str_replace("''", "'", $m[1]);
            foreach (array_values($values) as $i => $value) {
                // Like Sheets with USER_ENTERED: typed dates become dates.
                $value = is_string($value) && preg_match('/^\d{4}-\d\d-\d\d$/', $value) ? Carbon::parse($value) : $value;
                $this->tabs[$m[1]][(int) $m[3]][ord($m[2]) - 65 + $i] = $value;
            }
            ksort($this->tabs[$m[1]]);
        }
    }

    /** Like the API: the last row with a value in the range's columns ("'IN'!D:D" looks at column D only). */
    public function lastRows(array $ranges): array
    {
        return collect($ranges)->mapWithKeys(function ($r) {
            preg_match("/^'(.+)'!([A-Z]):([A-Z])$/", $r, $m);
            $filled = array_filter($this->tabs[$m[1]], function ($cells) use ($m) {
                foreach ($cells as $col => $value) {
                    if ($col >= ord($m[2]) - 65 && $col <= ord($m[3]) - 65 && $value !== null && $value !== '') {
                        return true;
                    }
                }

                return false;
            });

            return [$r => max([1, ...array_keys($filled)])];
        })->all();
    }

    public function tabs(): array
    {
        return array_map('strval', array_keys($this->tabs));
    }

    public function prepareTab(string $tab, array $headers): string
    {
        $tab = collect($this->tabs())->first(fn ($t) => mb_strtolower($t) === mb_strtolower($tab)) ?? $tab;
        $this->tabs[$tab] ??= [];
        $this->write([self::range($tab, 'A1') => $headers, self::range($tab, 'Z1') => ['SYNC ID']]);

        return $tab;
    }

    public array $styled = [];

    public function styleTabs(array $tabs, array $style): void
    {
        $this->styled = [...$this->styled, ...$tabs];
    }

    public array $fitted = [];

    public function fitColumns(array $tabs, array $headers): void
    {
        $this->fitted = [...$this->fitted, ...$tabs];
    }

    public function fillTab(string $tab, array $rows, array $flag): bool
    {
        $made = ! isset($this->tabs[$tab]);
        $this->tabs = [$tab => [], ...$this->tabs]; // like Sheets: made as the first tab
        $this->tabs[$tab] = [];
        foreach (array_values($rows) as $i => $row) {
            $this->tabs[$tab][$i + 1] = array_values($row);
        }

        return $made;
    }

    /** Like Sheets: the rows below move up. */
    public function deleteRows(array $rowsByTab): void
    {
        foreach ($rowsByTab as $tab => $rows) {
            rsort($rows);
            foreach ($rows as $gone) {
                $shifted = [];
                foreach ($this->tabs[$tab] as $n => $cells) {
                    if ($n !== $gone) {
                        $shifted[$n > $gone ? $n - 1 : $n] = $cells;
                    }
                }
                $this->tabs[$tab] = $shifted;
            }
        }
    }

    /** Cells of a row as a plain list (blank cells filled), for assertions. */
    public function row(string $tab, int $row): array
    {
        return array_replace(array_fill(0, 26, null), $this->tabs[$tab][$row] ?? []);
    }
}
