<?php namespace Niainteractive\PenCatalog\Traits;

use Winter\Storm\Support\Str;

/**
 * Deterministic slug generation with collision handling.
 *
 * Replaces Storm's Sluggable trait, which routes source attributes through
 * relation accessors and fails on plain attributes in 1.3.
 */
trait GeneratesSlug
{
    public function generateSlugIfEmpty()
    {
        if (!empty($this->slug)) {
            return;
        }

        $base = trim((string) $this->getSlugBase());
        $base = Str::slug($base);

        if ($base === '') {
            $base = 'item';
        }

        $slug = $base;
        $counter = 2;

        while (static::where('slug', $slug)->where('id', '<>', (int) $this->id)->exists()) {
            $slug = $base . '-' . $counter;
            $counter++;
        }

        $this->slug = $slug;
    }

    public function getSlugBase()
    {
        return (string) ($this->name ?: $this->model ?: '');
    }
}
