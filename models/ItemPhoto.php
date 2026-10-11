<?php namespace Niainteractive\PenCatalog\Models;

use Model;
use URL;

/**
 * One photo of a pen.
 *
 * Photos are files under storage/app/media, referenced by relative path. That directory is
 * served directly by the web server, so no copy or file record is involved: whatever the
 * pickup command (pencatalog:pickup-photos) finds in the folder is what the page loads.
 *
 * Naming convention: <make>-<model>-<number>.<ext>, where <make>-<model> is the pen's slug
 * and <number> is the display order. See Console/PickupPhotos.php.
 */
class ItemPhoto extends Model
{
    public $table = 'niainteractive_pencatalog_item_photos';

    public $fillable = ['item_id', 'file_id', 'path', 'caption', 'sort_order', 'is_primary'];

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

    /** Public URL for this photo: the media path, or the legacy file record. */
    public function getUrlAttribute()
    {
        if ($this->path) {
            return URL::to('/storage/app/media/' . ltrim($this->path, '/'));
        }

        if ($this->file) {
            return $this->file->getPath();
        }

        return null;
    }
}
