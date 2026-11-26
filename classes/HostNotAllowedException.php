<?php

/**
 * @file classes/HostNotAllowedException.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class HostNotAllowedException
 *
 * @brief The SFTP host is on a private or reserved network, which deposits are not sent to.
 */

namespace APP\plugins\generic\pubmedCentral\classes;

use RuntimeException;
use Throwable;

class HostNotAllowedException extends RuntimeException
{
    public function __construct(public readonly string $host, string $message = '', ?Throwable $previous = null)
    {
        parent::__construct($message ?: "The SFTP host {$host} is on a private or reserved network.", 0, $previous);
    }
}
