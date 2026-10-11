<?php

namespace App\Enums;

/** Spec 005 pricing vocabulary (data-model). */
enum SettingGroup: string
{
    case RATES = 'rates';
    case OPERATIONS = 'operations';

    /** Spec 019: what customers can see; changed only from Switches, never by the generic settings PATCH. */
    case VISIBILITY = 'visibility';
}
