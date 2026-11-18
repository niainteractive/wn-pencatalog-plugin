<?php namespace Niainteractive\PenCatalog\Controllers;

use Backend\Classes\Controller;
use Backend\Behaviors\ListController;
use BackendMenu;
use Niainteractive\PenCatalog\Models\Suggestion;

class Suggestions extends Controller
{
    public $implement = [ListController::class];

    public $listConfig = 'config_list.yaml';

    public $requiredPermissions = ['niainteractive.pencatalog.manage_suggestions'];

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('Niainteractive.PenCatalog', 'pencatalog', 'suggestions');
    }

    public function index()
    {
        $this->pageTitle = 'Suggested pens';
        return $this->listRender();
    }

    public function onPromote()
    {
        return $this->setStatus('promoted', 'Marked as added to the catalogue.');
    }

    public function onDecline()
    {
        return $this->setStatus('declined', 'Marked as declined.');
    }

    protected function setStatus($status, $message)
    {
        $checked = post('checked', []);

        if (!is_array($checked) || !count($checked)) {
            return;
        }

        $count = Suggestion::whereIn('id', $checked)->update(['status' => $status]);
        \Flash::success($count . ' suggestion(s). ' . $message);

        return $this->listRefresh();
    }
}
