<?php namespace Niainteractive\PenCatalog\Models;

use Model;

class Offer extends Model
{
    use \Winter\Storm\Database\Traits\Validation;

    public $table = 'niainteractive_pencatalog_offers';

    public $fillable = ['item_id', 'vendor_id', 'label', 'url', 'price', 'currency', 'price_date',
                        'availability', 'shipping', 'notes'];

    public $rules = [
        'price' => 'nullable|numeric',
    ];

    public $belongsTo = [
        'item'   => [Item::class, 'key' => 'item_id'],
        'vendor' => [Vendor::class, 'key' => 'vendor_id'],
    ];

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function getAvailabilityOptions()
    {
        return [
            'unknown'      => 'Unknown',
            'in_stock'     => 'In stock',
            'out_of_stock' => 'Out of stock',
            'backorder'    => 'Backorder',
            'discontinued' => 'Discontinued',
        ];
    }
}
