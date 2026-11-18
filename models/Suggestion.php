<?php namespace Niainteractive\PenCatalog\Models;

use Model;

class Suggestion extends Model
{
    public $table = 'niainteractive_pencatalog_suggestions';

    public $fillable = ['user_id', 'make', 'model', 'url', 'notes', 'status', 'ip'];

    public $belongsTo = [
        'user' => [\Winter\User\Models\User::class, 'key' => 'user_id'],
    ];

    public function scopeNew($query)
    {
        return $query->where('status', 'new');
    }

    public function getLabelAttribute()
    {
        return trim((string) $this->make . ' ' . (string) $this->model);
    }

    /**
     * Demand report: which pens members keep asking for, most requested first.
     *
     * Declined suggestions are excluded - they are noise by definition. Each row
     * carries the catalogue entry it matches, if any, so an already-listed pen
     * is obvious and does not get added twice.
     */
    public static function demandRows()
    {
        $rows = self::where('status', '<>', 'declined')
            ->selectRaw('make, model, COUNT(*) as requests, COUNT(DISTINCT user_id) as members, MAX(created_at) as last_at')
            ->groupBy('make', 'model')
            ->orderByDesc('requests')
            ->orderByDesc('last_at')
            ->get();

        foreach ($rows as $row) {
            $row->catalogue_item = Item::query()
                ->whereRaw('LOWER(model) = ?', [mb_strtolower((string) $row->model)])
                ->when($row->make, function ($query) use ($row) {
                    $query->whereHas('brand', function ($brand) use ($row) {
                        $brand->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $row->make)]);
                    });
                })
                ->first();
        }

        return $rows;
    }
}
