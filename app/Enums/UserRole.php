<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Organizer = 'organizer';
    case Customer = 'customer';
    case Gatekeeper = 'gatekeeper';
}
