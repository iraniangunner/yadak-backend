<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class CitySalesExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private \Illuminate\Support\Collection $rows) {}

    public function collection()
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['شهر', 'تعداد سفارش', 'مجموع فروش (تومان)'];
    }

    public function map($row): array
    {
        return [
            $row->city ?? 'نامشخص',
            $row->total_orders,
            $row->total_sales,
        ];
    }
}
