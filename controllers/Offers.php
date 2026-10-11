<?php namespace Niainteractive\PenCatalog\Controllers;

use Backend\Classes\Controller;
use BackendMenu;

class Offers extends Controller
{
    public $implement = [
        \Backend\Behaviors\ListController::class,
        \Backend\Behaviors\FormController::class,
    ];

    public $listConfig = 'config_list.yaml';
    public $formConfig = 'config_form.yaml';

    public $requiredPermissions = ['niainteractive.pencatalog.manage_items'];

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('Niainteractive.PenCatalog', 'pencatalog', 'offers');
    }

    /**
     * Offers are normally created from the pen they belong to, where the relation
     * sets the pen for you. On this controller's own create and update pages there
     * is no parent, so the pen has to be chosen - without it an offer cannot be
     * attached to anything.
     *
     * Added here rather than in fields.yaml because this method only runs for this
     * controller's form; the relation manage form on the pen page uses the model's
     * fields.yaml directly and is left alone.
     */
    public function formExtendFields($form)
    {
        if (!in_array($this->formGetContext(), ['create', 'update'])) {
            return;
        }

        $form->addFields([
            'item_id' => [
                'label' => 'Pen',
                'type' => 'dropdown',
                'options' => '\Niainteractive\PenCatalog\Models\Item::getSelectOptions',
                'span' => 'full',
                'required' => 1,
                'comment' => 'Which pen this offer is for.',
            ],
        ], 'primary');
    }
}
