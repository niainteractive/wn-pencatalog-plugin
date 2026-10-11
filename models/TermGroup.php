<?php namespace Niainteractive\PenCatalog\Models;

use Model;

class TermGroup extends Model
{
    use \Winter\Storm\Database\Traits\Validation;

    public $table = 'niainteractive_pencatalog_term_groups';

    public $fillable = ['name', 'slug', 'description', 'is_multi', 'sort_order', 'is_active'];

    public $rules = [
        'name' => 'required',
    ];

    public $hasMany = [
        'terms' => [Term::class, 'key' => 'group_id'],
    ];

    public function terms()
    {
        return $this->hasMany(Term::class, 'group_id');
    }
    /** Options for the Group picker: id => name. */
    public static function getSelectOptions()
    {
        return static::orderBy('name')->pluck('name', 'id')->all();
    }

}
