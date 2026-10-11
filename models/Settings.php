<?php namespace Niainteractive\PenCatalog\Models;

use Model;

class Settings extends Model
{
    public $implement = ['System.Behaviors.SettingsModel'];

    public $settingsCode = 'niainteractive_pencatalog_settings';

    public $settingsFields = 'fields.yaml';
}
