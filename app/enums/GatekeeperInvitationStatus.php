<?php

namespace App\Enums;

enum GatekeeperInvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
