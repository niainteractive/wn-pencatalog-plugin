<?php namespace Niainteractive\PenCatalog\Controllers;

use Backend\Classes\Controller;
use BackendMenu;
use Niainteractive\PenCatalog\Models\Term;
use Niainteractive\PenCatalog\Models\TermGroup;

class Items extends Controller
{
    public $implement = [
        \Backend\Behaviors\ListController::class,
        \Backend\Behaviors\FormController::class,
        \Backend\Behaviors\RelationController::class,
    ];

    public $listConfig = 'config_list.yaml';
    public $formConfig = 'config_form.yaml';
    public $relationConfig = 'config_relation.yaml';

    public $requiredPermissions = ['niainteractive.pencatalog.manage_items'];

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('Niainteractive.PenCatalog', 'pencatalog', 'items');
    }

    /**
     * Term groups are data, so one control per group is generated here and added
     * to the Attributes tab. Saving them is handled by the Item model's dynamic
     * terms_<groupSlug> attributes.
     *
     * Groups flagged is_multi=0 are single-value (e.g. Nib Swappable) and render
     * as a dropdown instead of a checklist.
     */
    public function formExtendFields($form)
    {
        $fields = [];

        foreach (TermGroup::orderBy('sort_order')->get() as $group) {
            $options = [];
            foreach (Term::where('group_id', $group->id)->orderBy('sort_order')->get() as $term) {
                $options[$term->id] = $term->name;
            }

            if (empty($options)) {
                continue;
            }

            $key = 'terms_' . str_replace('-', '_', $group->slug);

            if ((int) $group->is_multi === 0) {
                $fields[$key] = [
                    'label'       => $group->name,
                    'type'        => 'dropdown',
                    'tab'         => 'Attributes',
                    'span'        => 'left',
                    'options'     => $options,
                    'emptyOption' => '-- none --',
                ];
            } else {
                $fields[$key] = [
                    'label'   => $group->name,
                    'type'    => 'checkboxlist',
                    'tab'     => 'Attributes',
                    'span'    => 'left',
                    'options' => $options,
                ];
            }
        }

        $form->addTabFields($fields);
    }
}
