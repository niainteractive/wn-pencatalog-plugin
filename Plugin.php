<?php namespace Niainteractive\PenCatalog;

use System\Classes\PluginBase;
use Niainteractive\PenCatalog\Models\Item;

/**
 * PenCatalog plugin
 *
 * A catalog of fountain pens: backend-managed makes, terms (vocabularies),
 * purchase offers and photos, with a sortable/filterable front-end table.
 */
class Plugin extends PluginBase
{
    public function boot()
    {
        /*
         * Term selections arrive as dynamic "terms_<groupSlug>" attributes
         * (the per-group checklists are generated from the database), so they
         * are written to the item_term pivot after the model is saved.
         */
        Item::extend(function ($model) {
            $model->bindEvent('model.afterSave', function () use ($model) {
                if ($model->hasPendingTermSelection()) {
                    $model->syncSelectedTerms();
                }
            });
        });

        /*
         * Slugs are generated ourselves (see the GeneratesSlug trait).
         */
        foreach ([\Niainteractive\PenCatalog\Models\Make::class,
                  \Niainteractive\PenCatalog\Models\Vendor::class,
                  Item::class] as $modelClass) {
            $modelClass::extend(function ($model) {
                $model->bindEvent('model.beforeSave', function () use ($model) {
                    $model->generateSlugIfEmpty();
                });
            });
        }
    }

    public function register()
    {
        $this->registerConsoleCommand('pencatalog.pickup-photos', \Niainteractive\PenCatalog\Console\PickupPhotos::class);
    }

    public function pluginDetails()
    {
        return [
            'name'        => 'Pen Catalog',
            'description' => 'Catalog of fountain pens: makes, terms, offers and photos.',
            'author'      => 'Annie Easley (Nia Interactive)',
            'icon'        => 'icon-pencil',
            'homepage'    => 'https://niainteractive.com',
        ];
    }

    public function registerComponents()
    {
        return [
            \Niainteractive\PenCatalog\Components\CatalogTable::class => 'catalogTable',
            \Niainteractive\PenCatalog\Components\ItemDetail::class => 'itemDetail',
        ];
    }

    public function registerPermissions()
    {
        return [
            'niainteractive.pencatalog.manage_items' => [
                'tab'   => 'Pen Catalog',
                'label' => 'Create, edit and delete catalog entries',
            ],
            'niainteractive.pencatalog.manage_vocabularies' => [
                'tab'   => 'Pen Catalog',
                'label' => 'Manage types, groups and terms',
            ],
            'niainteractive.pencatalog.manage_vendors' => [
                'tab'   => 'Pen Catalog',
                'label' => 'Manage vendors',
            ],
            'niainteractive.pencatalog.import_export' => [
                'tab'   => 'Pen Catalog',
                'label' => 'Import and export catalog data (CSV)',
            ],
            'niainteractive.pencatalog.access_settings' => [
                'tab'   => 'Pen Catalog',
                'label' => 'Configure Pen Catalog settings',
            ],
        ];
    }

    public function registerSettings()
    {
        return [
            'pencatalog' => [
                'label'       => 'Pen Catalog',
                'description' => 'Catalog display, units and access settings.',
                'category'    => 'Pen Catalog',
                'icon'        => 'icon-cog',
                'class'       => \Niainteractive\PenCatalog\Models\Settings::class,
                'order'       => 500,
                'keywords'    => 'pen catalog fountain',
                'permissions' => ['niainteractive.pencatalog.access_settings'],
            ],
        ];
    }

    public function registerNavigation()
    {
        return [
            'pencatalog' => [
                'label'       => 'Pen Catalog',
                'url'         => \Backend::url('niainteractive/pencatalog/items'),
                'icon'        => 'icon-pencil',
                'permissions' => ['niainteractive.pencatalog.manage_items'],
                'order'       => 500,
                'sideMenu'    => [
                    'items' => [
                        'label'       => 'Pens',
                        'url'         => \Backend::url('niainteractive/pencatalog/items'),
                        'icon'        => 'icon-pencil',
                        'permissions' => ['niainteractive.pencatalog.manage_items'],
                    ],
                    'makes' => [
                        'label'       => 'Makes',
                        'url'         => \Backend::url('niainteractive/pencatalog/makes'),
                        'icon'        => 'icon-industry',
                        'permissions' => ['niainteractive.pencatalog.manage_vocabularies'],
                    ],
                    'vendors' => [
                        'label'       => 'Vendors',
                        'url'         => \Backend::url('niainteractive/pencatalog/vendors'),
                        'icon'        => 'icon-shopping-cart',
                        'permissions' => ['niainteractive.pencatalog.manage_vendors'],
                    ],
                    'offers' => [
                        'label'       => 'Offers',
                        'url'         => \Backend::url('niainteractive/pencatalog/offers'),
                        'icon'        => 'icon-tag',
                        'permissions' => ['niainteractive.pencatalog.manage_items'],
                    ],
                    'termgroups' => [
                        'label'       => 'Terms & Groups',
                        'url'         => \Backend::url('niainteractive/pencatalog/termgroups'),
                        'icon'        => 'icon-list-ul',
                        'permissions' => ['niainteractive.pencatalog.manage_vocabularies'],
                    ],
                    'itemtypes' => [
                        'label'       => 'Types',
                        'url'         => \Backend::url('niainteractive/pencatalog/itemtypes'),
                        'icon'        => 'icon-cubes',
                        'permissions' => ['niainteractive.pencatalog.manage_vocabularies'],
                    ],
                ],
            ],
        ];
    }
}
