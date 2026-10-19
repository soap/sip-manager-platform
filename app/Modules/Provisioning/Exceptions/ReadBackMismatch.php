<?php

namespace App\Modules\Provisioning\Exceptions;

use RuntimeException;

/**
 * The rows read back from the node differ from those written, e.g. because something else changed
 * them in between. Retried: the next attempt writes them again.
 */
class ReadBackMismatch extends RuntimeException {}
