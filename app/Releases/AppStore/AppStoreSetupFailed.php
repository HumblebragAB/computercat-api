<?php

namespace App\Releases\AppStore;

use App\Provisioning\SafeMessage;

final class AppStoreSetupFailed extends \RuntimeException implements SafeMessage {}
