<?php

/**
 * @file classes/HostKeyMismatchException.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class HostKeyMismatchException
 *
 * @brief The SFTP server presented a host key other than the one recorded or expected for it.
 */

namespace APP\plugins\generic\pubmedCentral\classes;

use RuntimeException;
use Throwable;

class HostKeyMismatchException extends RuntimeException
{
    /**
     * @param string $host The host and port, as "host:port"
     * @param string $fingerprint The SHA256 fingerprint of the key the server presented
     */
    public function __construct(
        public readonly string $host,
        public readonly string $fingerprint,
        string $message = '',
        ?Throwable $previous = null
    ) {
        parent::__construct($message ?: "The SFTP server at {$host} presented an unexpected host key ({$fingerprint}).", 0, $previous);
    }
}
