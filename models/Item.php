<?php namespace Niainteractive\PenCatalog\Models;

use Model;
use Niainteractive\PenCatalog\Traits\GeneratesSlug;

/**
 * A catalog entry — a fountain pen (v1).
 *
 * NOTE: the brand relation is called "brand", not "make", because Model::make()
 * is a real inherited method — a relation named "make" is shadowed by it and
 * every framework call to $model->make() returns a new model instance instead
 * of a relation.
 *
 * Relations are also declared as explicit methods so that callers which invoke
 * $model->relationName() always get a relation object.
 */
class Item extends Model
{
    use \Winter\Storm\Database\Traits\Validation;
    use GeneratesSlug;

    public $table = 'niainteractive_pencatalog_items';

    public $fillable = [
        'type_id', 'make_id', 'model', 'slug', 'rating', 'length_closed', 'length_uncapped',
        'length_posted', 'weight', 'description', 'notes', 'inspiration', 'is_published',
    ];

    public $rules = [
        'make_id' => 'required',
        'model' => 'required',
        'rating' => 'nullable|numeric|min:1|max:10',
    ];

    public $belongsTo = [
        'type'  => [ItemType::class, 'key' => 'type_id'],
        'brand' => [Make::class, 'key' => 'make_id'],
    ];

    public $hasMany = [
        'offers' => [Offer::class, 'key' => 'item_id'],
        'photos' => [ItemPhoto::class, 'key' => 'item_id'],
    ];

    public $belongsToMany = [
        'terms' => [
            Term::class,
            'table'    => 'niainteractive_pencatalog_item_term',
            'key'      => 'item_id',
            'otherKey' => 'term_id',
        ],
    ];

    public function type()
    {
        return $this->belongsTo(ItemType::class, 'type_id');
    }

    public function brand()
    {
        return $this->belongsTo(Make::class, 'make_id');
    }

    public function offers()
    {
        return $this->hasMany(Offer::class, 'item_id');
    }

    public function photos()
    {
        return $this->hasMany(ItemPhoto::class, 'item_id');
    }

    public function terms()
    {
        return $this->belongsToMany(
            Term::class,
            'niainteractive_pencatalog_item_term',
            'item_id',
            'term_id'
        );
    }

    /** @var array group slug => selected term ids, waiting to be written */
    protected $pendingTermSelection = [];

    public function getSlugBase()
    {
        $brand = $this->brand ? $this->brand->name : '';

        return trim($brand . ' ' . (string) $this->model);
    }

    public function beforeCreate()
    {
        if (!$this->type_id) {
            $this->type_id = ItemType::orderBy('sort_order')->value('id');
        }
    }

    /**
     * Ratings are half points: 1, 1.5 ... 10. Anything the user types is
     * snapped to the nearest half so the scale stays consistent.
     */
    public function beforeSave()
    {
        if ($this->rating === null || $this->rating === '') {
            $this->rating = null;
        } else {
            $this->rating = round(((float) $this->rating) * 2) / 2;
        }
    }

    /** The Nib Swappable term (single-select group), or null. */
    public function getNibSwappableAttribute()
    {
        foreach ($this->terms as $term) {
            if ($term->group && $term->group->slug === 'nib-swappable') {
                return $term;
            }
        }

        return null;
    }

    /* ------------------------------------------------- dynamic terms_<group> attributes */

    public function hasGetMutator($key)
    {
        if ($this->isTermSelectionKey($key)) {
            return true;
        }
        return parent::hasGetMutator($key);
    }

    public function hasSetMutator($key)
    {
        if ($this->isTermSelectionKey($key)) {
            return true;
        }
        return parent::hasSetMutator($key);
    }

    protected function mutateAttribute($key, $value)
    {
        if ($this->isTermSelectionKey($key)) {
            $slug = $this->termSelectionSlug($key);
            $ids = $this->getSelectedTermIds($slug);

            // Single-select groups (is_multi = 0) feed a dropdown, which needs a
            // scalar — handing it an array makes the widget blow up.
            $group = TermGroup::where('slug', $slug)->first();
            if ($group && (int) $group->is_multi === 0) {
                return $ids ? (int) reset($ids) : null;
            }

            return $ids;
        }
        return parent::mutateAttribute($key, $value);
    }

    protected function setMutatedAttributeValue($key, $value)
    {
        if ($this->isTermSelectionKey($key)) {
            $this->pendingTermSelection[$this->termSelectionSlug($key)] =
                array_values(array_filter(array_map('intval', (array) $value)));
            return $this;
        }
        return parent::setMutatedAttributeValue($key, $value);
    }

    protected function isTermSelectionKey($key)
    {
        return is_string($key) && preg_match('/^terms_[a-z0-9_]+$/i', $key) === 1;
    }

    /**
     * Field keys use underscores ("terms_nib_size") because group slugs contain
     * hyphens ("nib-size").
     */
    protected function termSelectionSlug($key)
    {
        return str_replace('_', '-', substr($key, strlen('terms_')));
    }

    /** Term ids currently attached to this item for the given group slug. */
    public function getSelectedTermIds($groupSlug)
    {
        $ids = [];
        foreach ($this->terms as $term) {
            if ($term->group && $term->group->slug === $groupSlug) {
                $ids[] = (int) $term->id;
            }
        }
        return $ids;
    }

    public function hasPendingTermSelection()
    {
        return !empty($this->pendingTermSelection);
    }

    /** Write the pending per-group selections into the item_term pivot. */
    public function syncSelectedTerms()
    {
        foreach ($this->pendingTermSelection as $slug => $ids) {
            $group = TermGroup::where('slug', $slug)->first();
            if (!$group) {
                continue;
            }

            $groupTermIds = Term::where('group_id', $group->id)->pluck('id')->all();
            $current = [];
            foreach ($this->terms as $term) {
                if (in_array($term->id, $groupTermIds)) {
                    $current[] = (int) $term->id;
                }
            }

            $detach = array_diff($current, $ids);
            $attach = array_diff($ids, $current);

            if (!empty($detach)) {
                $this->terms()->detach($detach);
            }
            if (!empty($attach)) {
                $this->terms()->attach($attach);
            }
        }

        $this->pendingTermSelection = [];
        $this->load('terms');
    }
    /** Options for the Pen picker: id => "Make Model". */
    public static function getSelectOptions()
    {
        $out = [];
        foreach (static::with('brand')->orderBy('model')->get() as $item) {
            $out[$item->id] = trim(($item->brand ? $item->brand->name : '') . ' ' . $item->model);
        }
        return $out;
    }

}
