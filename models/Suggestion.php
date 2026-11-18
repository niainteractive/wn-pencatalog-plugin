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
}
