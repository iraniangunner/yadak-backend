<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class CustomerSalesExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private \Illuminate\Support\Collection $rows) {}

    public function collection()
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['شناسه مشتری', 'نام', 'موبایل', 'تعداد سفارش', 'مجموع خرید (تومان)'];
    }

    public function map($row): array
    {
        return [
            $row->user_id,
            $row->name,
            $row->phone,
            $row->total_orders,
            $row->total_spent,
        ];
    }
}
