<?php

namespace App\Enums;

enum RefundRequestStatus: string
{
    case Pending = 'pending';
    case Rejected = 'rejected';
    case ProcessingRefund = 'processing_refund';
    case Approved = 'approved';
    case RefundFailed = 'refund_failed';
}
