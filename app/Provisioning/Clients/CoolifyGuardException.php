<?php

namespace App\Provisioning\Clients;

use App\Provisioning\SafeMessage;

// Spärren i CoolifyClient: uuid:t kommer inte från en run, eller appen ligger
// inte i Demo-projektet. Ingen begäran har skickats.
final class CoolifyGuardException extends \RuntimeException implements SafeMessage
{
}
