<?php

namespace App\Services\Shipping;

use App\Contracts\ShippingProviderContract;

/**
 * ترکیب هر سه شرکت حمل (اسنپ‌باکس، تیپاکس، پست پیشتاز) توی یه لیست واحد،
 * تا مشتری همه‌ی گزینه‌ها رو با هم ببینه و انتخاب کنه. اگه یکی از
 * شرکت‌ها Down باشه یا خطا بده، همون یکی خالی برمی‌گرده (چون هر provider
 * خودش try/catch داره) و بقیه‌ی گزینه‌ها همچنان نمایش داده می‌شن.
 */
class AggregateShippingProvider implements ShippingProviderContract
{
    public function __construct(
        private SnappBoxShippingProvider $snappBox,
        private TipaxShippingProvider $tipax,
        private PostPishtazShippingProvider $postPishtaz,
    ) {}

    public function getOptions(string $city, float $totalWeightKg): array
    {
        return [
            ...$this->snappBox->getOptions($city, $totalWeightKg),
            ...$this->tipax->getOptions($city, $totalWeightKg),
            ...$this->postPishtaz->getOptions($city, $totalWeightKg),
        ];
    }
}
