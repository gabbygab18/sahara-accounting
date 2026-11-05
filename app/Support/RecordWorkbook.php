<?php

namespace App\Support;

use App\Models\Record;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Records to and from Excel: the export, the daily backup, and the import of the client's old tracker. */
class RecordWorkbook
{
    /**
     * Headers an old tracker may use for each field, squashed to letters and digits. A header goes to the first
     * field still free that lists it, so with GROUP and COMPANY both present, COMPANY is the sold-to company.
     */
    private const ALIASES = [
        'company' => ['GROUP', 'COMPANYGROUP', 'MAINCOMPANY', 'COMPANY', 'CLIENT', 'COMPANYNAME'],
        'sold_to' => ['COMPANYSOLDTO', 'SOLDTO', 'COMPANY', 'CUSTOMER'],
        'project' => ['PROJECT', 'PROJECTSOLDTO', 'PROJECTNAME'],
        'tin' => ['TIN', 'TINNO', 'TINNUMBER'],
        'po_date' => ['PODATE', 'PURCHASEORDERDATE'],
        'po_number' => ['PO', 'PONO', 'PONUMBER', 'PURCHASEORDER'],
        'terms_days' => ['TERMS', 'TERMSDAYS', 'TERM'],
        'dr_date' => ['DRDATE', 'DELIVERYDATE'],
        'dr_number' => ['DR', 'DRNO', 'DRNUMBER', 'DELIVERYRECEIPT'],
        'delivery_status' => ['DELIVERYSTATUS', 'DELIVERY', 'DELIVERED'],
        'si_date' => ['SIDATE', 'INVOICEDATE', 'SALESINVOICEDATE'],
        'si_number' => ['SI', 'SINO', 'SINUMBER', 'INVOICE', 'INVOICENO', 'INVOICENUMBER', 'SALESINVOICE'],
        'or_date' => ['ORDATE', 'OFFICIALRECEIPTDATE', 'DATEPAID', 'PAYMENTDATE'],
        'or_number' => ['OR', 'ORNO', 'ORNUMBER', 'OFFICIALRECEIPT'],
        'si_amount' => ['SIAMOUNT', 'INVOICEAMOUNT'],
        'amount' => ['TOTALAMOUNT', 'AMOUNT', 'TOTAL', 'TOTALAMOUNTDUE', 'AMOUNTDUE'],
        'paid' => ['PAYMENTSTATUS', 'PAYMENT', 'PAID', 'STATUS'],
        'check_number' => ['CHECK', 'CHECKNO', 'CHECKNUMBER', 'CHEQUE', 'CHEQUENO', 'CHECKNUM'],
        'check_date' => ['CHECKDATE', 'CHECKDATEDEPOSIT', 'DEPOSITDATE', 'DATEOFDEPOSIT', 'CHEQUEDATE'],
        'check_cleared' => ['CHECKCLEARED', 'CLEARED', 'CHECKSTATUS'],
    ];

    /** Every record, in the Google Sheet's columns, as an .xlsx file. */
    public static function save(iterable $records, string $path): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('RECORDS');
        $sheet->fromArray(array_values(Record::COLUMNS));
        $sheet->getStyle('1:1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        $row = 1;
        foreach ($records as $record) {
            $row++;
            $sheet->fromArray(array_map(
                // One date becomes an Excel date; several (one per DR / SI line) stay text.
                fn ($v, $column) => in_array($column, Record::DATES, true) && $v && ! str_contains($v, "\n") ? Date::stringToExcel($v) : $v,
                $record->toRow(),
                array_keys(Record::COLUMNS),
            ), null, "A{$row}", true);
        }
        $letter = fn (string $column) => chr(65 + array_search($column, array_keys(Record::COLUMNS), true));
        foreach (Record::DATES as $column) {
            $sheet->getStyle("{$letter($column)}2:{$letter($column)}{$row}")->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        }
        foreach (['si_amount', 'amount'] as $column) {
            $sheet->getStyle("{$letter($column)}2:{$letter($column)}{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        }
        foreach (array_keys(Record::COLUMNS) as $column) {
            $sheet->getColumnDimension($letter($column))->setAutoSize(true);
        }
        $sheet->getStyle("A2:{$letter('or_number')}{$row}")->getAlignment()->setWrapText(true); // several DRs / SIs, one per line

        (new Xlsx($book))->save($path);
    }

    /**
     * Rows of every tab that has a header row naming at least three fields. A tab without a company column
     * (one tab per company) takes the tab's name as the company.
     *
     * @return list<array> Record fields per row
     */
    public static function read(string $path): array
    {
        $out = [];
        foreach (IOFactory::load($path)->getAllSheets() as $sheet) {
            $rows = $sheet->toArray(null, true, false, false);
            [$at, $map] = self::header($rows);
            if ($map === null) {
                continue;
            }
            foreach (array_slice($rows, $at + 1) as $cells) {
                $ordered = array_map(fn ($column) => isset($map[$column]) ? $cells[$map[$column]] : null, array_keys(Record::COLUMNS));
                if (Record::blankRow($ordered)) {
                    continue;
                }
                $fields = Record::fromRow($ordered);
                if (! isset($map['company'])) {
                    $fields['company'] = trim($sheet->getTitle());
                }
                $out[] = $fields;
            }
        }

        return $out;
    }

    /** [row index, field => column index] of the first of the top 15 rows that names three fields or more. */
    private static function header(array $rows): array
    {
        foreach (array_slice($rows, 0, 15) as $i => $cells) {
            $map = [];
            foreach ($cells as $col => $text) {
                $key = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $text));
                foreach (self::ALIASES as $field => $names) {
                    if ($key !== '' && ! isset($map[$field]) && in_array($key, $names, true) && ! in_array($col, $map, true)) {
                        $map[$field] = $col;
                        break;
                    }
                }
            }
            if (count($map) >= 3) {
                return [$i, $map];
            }
        }

        return [0, null];
    }
}
