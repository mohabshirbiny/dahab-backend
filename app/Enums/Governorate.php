<?php

namespace App\Enums;

/**
 * The 27 Egyptian governorates. Stored as a stable code on `customer.governorate`
 * and CHECK-constrained at the database. UI labels (Arabic/English) live in the
 * frontend or a translation file — never in business logic.
 */
enum Governorate: string
{
    case CAIRO = 'cairo';
    case GIZA = 'giza';
    case ALEXANDRIA = 'alexandria';
    case DAKAHLIA = 'dakahlia';
    case RED_SEA = 'red_sea';
    case BEHEIRA = 'beheira';
    case FAYOUM = 'fayoum';
    case GHARBIA = 'gharbia';
    case ISMAILIA = 'ismailia';
    case MENOFIA = 'menofia';
    case MINYA = 'minya';
    case QALIUBIA = 'qaliubia';
    case NEW_VALLEY = 'new_valley';
    case SUEZ = 'suez';
    case ASWAN = 'aswan';
    case ASYUT = 'asyut';
    case BENI_SUEF = 'beni_suef';
    case PORT_SAID = 'port_said';
    case DAMIETTA = 'damietta';
    case SHARKIA = 'sharkia';
    case SOUTH_SINAI = 'south_sinai';
    case KAFR_EL_SHEIKH = 'kafr_el_sheikh';
    case MATROUH = 'matrouh';
    case LUXOR = 'luxor';
    case QENA = 'qena';
    case NORTH_SINAI = 'north_sinai';
    case SOHAG = 'sohag';
}
