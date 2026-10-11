<?php namespace Niainteractive\PenCatalog\Console;

use Illuminate\Console\Command;
use Niainteractive\PenCatalog\Models\Item;
use Niainteractive\PenCatalog\Models\ItemPhoto;
use Symfony\Component\Console\Input\InputOption;

/**
 * Attaches photos dropped into storage/app/media/pens to their pens.
 *
 * Convention: <make>-<model>-<number>.<ext>, e.g. pilot-custom-823-2.jpg
 *   - the trailing -<number> is the display order (missing = 1)
 *   - everything before it must match the pen's slug (make + model)
 *
 * Idempotent: a file already attached is skipped, so this is safe to run on a schedule.
 * Files that match no pen are REPORTED, never guessed at, and nothing is ever deleted —
 * if a file disappears, the photo row stays put and is listed as missing.
 */
class PickupPhotos extends Command
{
    protected $name = 'pencatalog:pickup-photos';

    protected $description = 'Attach photos in storage/app/media to their pens by filename.';

    public function handle()
    {
        $folder = trim((string) ($this->option('folder') ?: 'pens'), '/');
        $dryRun = (bool) $this->option('dry-run');

        $dir = storage_path('app/media/' . $folder);

        if (!is_dir($dir)) {
            $this->error("Folder not found: {$dir}");
            return 1;
        }

        $files = glob($dir . '/*.{jpg,jpeg,png,webp,gif,JPG,JPEG,PNG,WEBP,GIF}', GLOB_BRACE) ?: [];
        sort($files);

        $attached = $skipped = 0;
        $unmatched = $ambiguous = [];

        foreach ($files as $absolute) {
            $filename = basename($absolute);

            if (!preg_match('/^(.*?)-(\d+)\.[A-Za-z0-9]+$/', $filename, $m)) {
                $slug = preg_replace('/\.[A-Za-z0-9]+$/', '', $filename);
                $order = 1;
            } else {
                $slug = $m[1];
                $order = (int) $m[2];
            }

            $matches = Item::where('slug', $slug)->get();

            if ($matches->isEmpty()) {
                $unmatched[] = $filename . '  (no pen with slug "' . $slug . '")';
                continue;
            }

            if ($matches->count() > 1) {
                // Two rows share make+model, so the filename cannot say which one is meant.
                $ambiguous[] = $filename . '  (' . $matches->count() . ' pens share slug "' . $slug . '")';
            }

            $item = $matches->first();
            $relative = $folder . '/' . $filename;

            if (ItemPhoto::where('item_id', $item->id)->where('path', $relative)->exists()) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->line("  would attach  {$filename}  ->  {$slug}  (order {$order})");
                $attached++;
                continue;
            }

            $hasPrimary = ItemPhoto::where('item_id', $item->id)->where('is_primary', 1)->exists();

            $photo = new ItemPhoto();
            $photo->item_id = $item->id;
            $photo->path = $relative;
            $photo->sort_order = $order;
            $photo->is_primary = (!$hasPrimary && $order === 1) ? 1 : 0;
            $photo->save();

            $this->line("  attached  {$filename}  ->  {$slug}  (order {$order}"
                . ($photo->is_primary ? ', primary' : '') . ')');
            $attached++;
        }

        // Photos on record whose file has gone missing are reported, never auto-deleted.
        $missing = [];
        foreach (ItemPhoto::whereNotNull('path')->get() as $photo) {
            if (!is_file(storage_path('app/media/' . $photo->path))) {
                $missing[] = $photo->path . '  (pen id ' . $photo->item_id . ')';
            }
        }

        $this->info('');
        $this->info(sprintf('%s: %d attached, %d already present, %d unmatched, %d ambiguous, %d missing files',
            $dryRun ? 'DRY RUN' : 'Done', $attached, $skipped, count($unmatched), count($ambiguous), count($missing)));

        foreach (['Unmatched (no such pen)' => $unmatched,
                  'Ambiguous (several pens share the slug)' => $ambiguous,
                  'Missing files (row kept, file gone)' => $missing] as $label => $rows) {
            if (!$rows) {
                continue;
            }
            $this->warn('');
            $this->warn($label . ':');
            foreach ($rows as $row) {
                $this->warn('  ' . $row);
            }
        }

        return 0;
    }

    protected function getOptions()
    {
        return [
            ['folder', null, InputOption::VALUE_OPTIONAL, 'Subfolder of storage/app/media to scan.', 'pens'],
            ['dry-run', null, InputOption::VALUE_NONE, 'Report what would happen without writing.'],
        ];
    }
}
