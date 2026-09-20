<?php

namespace App\Enums;

enum DevicePlatform: string
{
    case IOS = 'ios';
    case ANDROID = 'android';
    case WEB = 'web';
}
