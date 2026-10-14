<?php

namespace App\Provisioning\Clients;

use App\Provisioning\SafeMessage;

// 409 från POST /applications/private-github-app: domänen används redan.
final class CoolifyConflictException extends \RuntimeException implements SafeMessage
{
}
