<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A transient provider error. Thrown from a queue job so Laravel retries the
 * batch later using the job's backoff; claims are released first so no
 * message is left in a "processing" state.
 */
class ProviderTemporaryException extends RuntimeException
{
}
