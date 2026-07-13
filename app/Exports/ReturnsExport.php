<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ReturnsExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private \Illuminate\Support\Collection $rows) {}

    public function collection()
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['شناسه', 'مشتری', 'کالا', 'تعداد', 'دلیل', 'وضعیت', 'مبلغ بازگشتی', 'تاریخ'];
    }

    public function map($row): array
    {
        return [
            $row->id,
            $row->user?->name,
            $row->orderItem?->title,
            $row->quantity,
            $row->reason,
            $row->status,
            $row->refund_amount,
            $row->created_at?->format('Y-m-d H:i'),
        ];
    }
}
