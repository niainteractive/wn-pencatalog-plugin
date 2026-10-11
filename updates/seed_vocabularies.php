<?php namespace Niainteractive\PenCatalog\Updates;

use Db;
use Winter\Storm\Database\Updates\Migration;

class SeedVocabularies extends Migration
{
    protected $types = [
        'Fountain Pen' => 'fountain-pen',
    ];

    protected $groups = [
        'Nationality' => [
            'slug'    => 'nationality',
            'terms'   => ['Chinese', 'Japanese', 'Taiwanese', 'German', 'Italian', 'French',
                          'American', 'British', 'Indian', 'Other'],
        ],
        'Shape / Style' => [
            'slug'    => 'shape',
            'terms'   => ['Cigar', 'Flat-top', 'Torpedo', 'Tapered', 'Retractable', 'Hooded',
                          'Pocket', 'Desk'],
        ],
        'Colour' => [
            'slug'    => 'colour',
            'terms'   => ['Black', 'Blue', 'Green', 'Red', 'Brown', 'Grey', 'White',
                          'Orange', 'Purple', 'Clear', 'Gold', 'Silver', 'Multicolour'],
        ],
        'Material' => [
            'slug'    => 'material',
            'terms'   => ['Resin', 'Ebonite', 'Celluloid', 'Acrylic', 'Urushi', 'Stainless Steel',
                          'Brass', 'Titanium', 'Aluminium', 'Gold-filled', 'Wood'],
        ],
        'Nib Size' => [
            'slug'    => 'nib-size',
            'terms'   => ['Extra Fine', 'Fine', 'Medium', 'Broad', 'Double Broad', 'Stub',
                          'Cursive Italic', 'Architect'],
        ],
        'Nib Material' => [
            'slug'    => 'nib-material',
            'terms'   => ['Steel', 'Gold-plated', '14k Gold', '18k Gold', '21k Gold', 'Titanium'],
        ],
        'Ink System' => [
            'slug'    => 'ink-system',
            'terms'   => ['Cartridge / Converter', 'Piston', 'Vacuum', 'Eyedropper', 'Lever',
                          'Button', 'Touchdown', 'Aerometric'],
        ],
    ];

    public function up()
    {
        $now = date('Y-m-d H:i:s');

        foreach ($this->types as $name => $slug) {
            Db::table('niainteractive_pencatalog_item_types')->insert([
                'name'       => $name,
                'slug'       => $slug,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $typeId = Db::table('niainteractive_pencatalog_item_types')->where('slug', 'fountain-pen')->value('id');
        $groupSort = 0;

        foreach ($this->groups as $groupName => $data) {
            Db::table('niainteractive_pencatalog_term_groups')->insert([
                'name'       => $groupName,
                'slug'       => $data['slug'],
                'is_multi'   => true,
                'sort_order' => $groupSort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $groupId = Db::table('niainteractive_pencatalog_term_groups')->where('slug', $data['slug'])->value('id');

            Db::table('niainteractive_pencatalog_type_groups')->insert([
                'type_id'  => $typeId,
                'group_id' => $groupId,
            ]);

            $termSort = 0;
            foreach ($data['terms'] as $termName) {
                Db::table('niainteractive_pencatalog_terms')->insert([
                    'group_id'   => $groupId,
                    'name'       => $termName,
                    'slug'       => strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $termName), '-')),
                    'sort_order' => $termSort,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $termSort++;
            }
            $groupSort++;
        }
    }

    public function down()
    {
        Db::table('niainteractive_pencatalog_type_groups')->delete();
        Db::table('niainteractive_pencatalog_terms')->delete();
        Db::table('niainteractive_pencatalog_term_groups')->delete();
        Db::table('niainteractive_pencatalog_item_types')->delete();
    }
}
