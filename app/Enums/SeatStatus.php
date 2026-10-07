<?php

namespace App\Enums;

enum SeatStatus: string
{
    case Available = 'available';
    case Held = 'held';
    case Booked = 'booked';
}
