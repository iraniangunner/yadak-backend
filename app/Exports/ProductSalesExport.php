<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ProductSalesExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private \Illuminate\Support\Collection $rows) {}

    public function collection()
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['شناسه محصول', 'عنوان', 'SKU', 'تعداد فروخته‌شده', 'مبلغ فروش (تومان)'];
    }

    public function map($row): array
    {
        return [
            $row->product_id,
            $row->title,
            $row->sku,
            $row->total_quantity,
            $row->total_revenue,
        ];
    }
}
