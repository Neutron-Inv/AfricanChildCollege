<?php

namespace App\Services\Legacy;

use RuntimeException;

/**
 * A legacy import problem with a message that is safe and useful to show an operator
 * (never contains passwords or raw legacy rows).
 */
class LegacyImportException extends RuntimeException {}
