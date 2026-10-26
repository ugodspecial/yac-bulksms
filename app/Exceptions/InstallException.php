<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when the web installer cannot write the environment file or prepare
 * the database. The message is safe to show to the person installing the
 * application: it never contains credentials.
 */
class InstallException extends RuntimeException
{
}
