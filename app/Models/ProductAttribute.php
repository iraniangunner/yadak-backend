<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductAttribute extends Model
{
    use HasFactory;

    protected $fillable = ['product_id', 'name', 'value', 'sort_order', 'is_filterable'];

    protected $casts = [
        'is_filterable' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
