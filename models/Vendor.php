<?php namespace Niainteractive\PenCatalog\Models;

use Model;

class Vendor extends Model
{
    use \Winter\Storm\Database\Traits\Validation;
    use \Niainteractive\PenCatalog\Traits\GeneratesSlug;

    public $table = 'niainteractive_pencatalog_vendors';

    public $fillable = ['name', 'slug', 'website', 'notes', 'is_active'];

    public $rules = [
        'name' => 'required',
    ];

    public $hasMany = [
        'offers' => [Offer::class, 'key' => 'vendor_id'],
    ];

    public function offers()
    {
        return $this->hasMany(Offer::class, 'vendor_id');
    }
    /** Options for the Vendor picker: id => name. */
    public static function getSelectOptions()
    {
        return static::orderBy('name')->pluck('name', 'id')->all();
    }

}
