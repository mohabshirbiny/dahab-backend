<?php

namespace App\Enums;

/** `order_extension_request.state` (04 §10; `extension_request_transition`, guard DH010). Only `waiting` moves. */
enum ExtensionRequestState: string
{
    case WAITING = 'waiting';
    case ACCEPTED = 'accepted';
    case REFUSED = 'refused';
    case LAPSED = 'lapsed';
}
