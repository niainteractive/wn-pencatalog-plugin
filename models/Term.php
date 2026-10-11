<?php namespace Niainteractive\PenCatalog\Models;

use Model;

class Term extends Model
{
    use \Winter\Storm\Database\Traits\Validation;

    public $table = 'niainteractive_pencatalog_terms';

    public $fillable = ['group_id', 'name', 'slug', 'description', 'color_hex', 'sort_order', 'is_active'];

    public $rules = [
        'name'     => 'required',
        'group_id' => 'required',
    ];

    public $belongsTo = [
        'group' => [TermGroup::class, 'key' => 'group_id'],
    ];

    public $belongsToMany = [
        'items' => [
            Item::class,
            'table'    => 'niainteractive_pencatalog_item_term',
            'key'      => 'term_id',
            'otherKey' => 'item_id',
        ],
    ];

    public function group()
    {
        return $this->belongsTo(TermGroup::class, 'group_id');
    }

    public function items()
    {
        return $this->belongsToMany(
            Item::class,
            'niainteractive_pencatalog_item_term',
            'term_id',
            'item_id'
        );
    }
    /** Nationality terms, for the Make -> country picker. */
    public static function getNationalityOptions()
    {
        $group = TermGroup::where('slug', 'nationality')->first();
        if (!$group) { return []; }
        return static::where('group_id', $group->id)->orderBy('sort_order')->pluck('name', 'id')->all();
    }

}
