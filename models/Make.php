<?php namespace Niainteractive\PenCatalog\Models;

use Model;

class Make extends Model
{
    use \Winter\Storm\Database\Traits\Validation;
    use \Niainteractive\PenCatalog\Traits\GeneratesSlug;

    public $table = 'niainteractive_pencatalog_makes';

    public $fillable = ['name', 'slug', 'website', 'country_term_id', 'notes', 'is_active'];

    public $rules = [
        'name' => 'required',
    ];

    public $attachOne = [
        'logo' => \System\Models\File::class,
    ];

    public $hasMany = [
        'items' => [Item::class, 'key' => 'make_id'],
    ];

    public $belongsTo = [
        'country' => [Term::class, 'key' => 'country_term_id'],
    ];

    public function items()
    {
        return $this->hasMany(Item::class, 'make_id');
    }

    public function country()
    {
        return $this->belongsTo(Term::class, 'country_term_id');
    }

    /** Options for the Make picker: id => name. */
    public static function getSelectOptions()
    {
        return static::orderBy('name')->pluck('name', 'id')->all();
    }

}
