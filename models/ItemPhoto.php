<?php namespace Niainteractive\PenCatalog\Models;

use Model;

class ItemPhoto extends Model
{
    public $table = 'niainteractive_pencatalog_item_photos';

    public $fillable = ['item_id', 'file_id', 'caption', 'sort_order', 'is_primary'];

    public $belongsTo = [
        'item' => [Item::class, 'key' => 'item_id'],
        'file' => [\System\Models\File::class, 'key' => 'file_id'],
    ];

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function file()
    {
        return $this->belongsTo(\System\Models\File::class, 'file_id');
    }
}
