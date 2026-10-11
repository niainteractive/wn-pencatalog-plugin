<?php namespace Niainteractive\PenCatalog\Controllers;

use Backend\Classes\Controller;
use BackendMenu;

class Terms extends Controller
{
    public $implement = [
        \Backend\Behaviors\ListController::class,
        \Backend\Behaviors\FormController::class,
    ];

    public $listConfig = 'config_list.yaml';
    public $formConfig = 'config_form.yaml';

    public $requiredPermissions = ['niainteractive.pencatalog.manage_vocabularies'];

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('Niainteractive.PenCatalog', 'pencatalog', 'termgroups');
    }
}
