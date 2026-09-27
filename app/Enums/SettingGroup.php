<?php

namespace App\Enums;

/** Spec 005 pricing vocabulary (data-model). */
enum SettingGroup: string
{
    case RATES = 'rates';
    case OPERATIONS = 'operations';
}
