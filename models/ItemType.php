<?php namespace Niainteractive\PenCatalog\Models;

use Model;

class ItemType extends Model
{
    use \Winter\Storm\Database\Traits\Validation;

    public $table = 'niainteractive_pencatalog_item_types';

    public $fillable = ['name', 'slug', 'description', 'sort_order', 'is_active'];

    public $rules = [
        'name' => 'required',
    ];

    public $hasMany = [
        'items' => [Item::class, 'key' => 'type_id'],
    ];

    public function items()
    {
        return $this->hasMany(Item::class, 'type_id');
    }
}
