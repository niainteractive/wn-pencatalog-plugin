<?php namespace Niainteractive\PenCatalog\Controllers;

use Backend\Classes\Controller;
use BackendMenu;
use Niainteractive\PenCatalog\Models\Suggestion;

class Demand extends Controller
{
    public $requiredPermissions = ['niainteractive.pencatalog.manage_suggestions'];

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('Niainteractive\\PenCatalog', 'pencatalog', 'demand');
    }

    public function index()
    {
        $this->pageTitle = 'Pens members are asking for';

        $rows = Suggestion::demandRows();

        $this->vars['rows'] = $rows;
        $this->vars['totalAsked'] = Suggestion::where('status', '<>', 'declined')->count();
        $this->vars['totalPens'] = $rows->count();
        $this->vars['unlistedPens'] = $rows->filter(function ($row) {
            return !$row->catalogue_item;
        })->count();
    }
}
