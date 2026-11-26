<?php

/**
 * @file classes/HostKeyConnectionProvider.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class HostKeyConnectionProvider
 *
 * @brief An SFTP connection that checks the server's host key before logging in.
 *
 * The key is checked against the one recorded for the host on its first connection, and
 * against a fingerprint entered in the settings where there is one. With neither, the key
 * is recorded, and every later connection must present the same one.
 */

namespace APP\plugins\generic\pubmedCentral\classes;

use Closure;
use League\Flysystem\PhpseclibV3\ConnectionProvider;
use League\Flysystem\PhpseclibV3\UnableToAuthenticate;
use League\Flysystem\PhpseclibV3\UnableToConnectToSftpHost;
use phpseclib3\Net\SFTP;
use Throwable;

class HostKeyConnectionProvider implements ConnectionProvider
{
    protected ?SFTP $connection = null;

    /**
     * @param ?string $recordedKey The host key recorded for the host, as "type base64"
     * @param ?string $expectedFingerprint A fingerprint entered in the settings, normalized by normalizeFingerprint()
     * @param Closure(string): void $recordKey Records the key presented on a first connection
     */
    public function __construct(
        protected string $host,
        protected int $port,
        protected string $username,
        protected string $password,
        protected ?string $recordedKey,
        protected ?string $expectedFingerprint,
        protected Closure $recordKey,
        protected int $timeout = 10
    ) {
    }

    /**
     * The key a host's recorded key is stored under.
     */
    public static function hostId(string $host, int $port): string
    {
        return strtolower(trim($host)) . ':' . $port;
    }

    /**
     * A key's SHA256 fingerprint, in the form `ssh-keygen -l` shows.
     */
    public static function fingerprint(string $key): string
    {
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', self::keyBlob($key), true)), '=');
    }

    /**
     * A fingerprint in the form fingerprint() gives, or in the colon-separated MD5 form,
     * with or without its "SHA256:" or "MD5:" prefix; null for anything else.
     */
    public static function normalizeFingerprint(?string $fingerprint): ?string
    {
        $fingerprint = trim((string) $fingerprint);
        if (preg_match('/^(?:MD5:)?((?:[0-9a-f]{2}:){15}[0-9a-f]{2})$/i', $fingerprint, $matches)) {
            return 'MD5:' . strtolower($matches[1]);
        }
        if (preg_match('#^(?:SHA256:)?([A-Za-z0-9+/]{43})=?$#i', $fingerprint, $matches)) {
            return 'SHA256:' . $matches[1];
        }
        return null;
    }

    /**
     * Whether a key has the given normalized fingerprint.
     */
    public static function matchesFingerprint(string $key, string $fingerprint): bool
    {
        if (str_starts_with($fingerprint, 'MD5:')) {
            return hash_equals($fingerprint, 'MD5:' . implode(':', str_split(md5(self::keyBlob($key)), 2)));
        }
        return hash_equals($fingerprint, self::fingerprint($key));
    }

    /**
     * Whether two keys are the same key. Compared without the algorithm name, which names
     * the signature the server chose (e.g. "rsa-sha2-256" for an "ssh-rsa" key).
     */
    public static function isSameKey(string $key, string $otherKey): bool
    {
        return hash_equals(self::keyBlob($key), self::keyBlob($otherKey));
    }

    protected static function keyBlob(string $key): string
    {
        return (string) base64_decode(explode(' ', trim($key), 3)[1] ?? '', true);
    }

    /**
     * Whether an address is on the public internet, rather than the server itself, a private
     * network or a reserved range (including link-local addresses such as cloud metadata services).
     */
    public static function isPublicAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * @throws HostNotAllowedException When the host is on a private or reserved network
     * @throws HostKeyMismatchException When the server presents another key
     * @throws Throwable
     */
    public function provideConnection(): SFTP
    {
        if ($this->connection) {
            return $this->connection;
        }

        $connection = $this->connect($this->allowedAddress());
        try {
            // The reason a connection failed would tell whether something is listening on the
            // port, so every failure before the server is identified is reported alike
            try {
                $key = $connection->getServerPublicHostKey();
            } catch (Throwable $exception) {
                throw UnableToConnectToSftpHost::atHostname($this->host, $exception);
            }
            if (!$key) {
                throw UnableToConnectToSftpHost::atHostname($this->host);
            }

            if (
                ($this->recordedKey && !self::isSameKey($this->recordedKey, $key))
                || ($this->expectedFingerprint && !self::matchesFingerprint($key, $this->expectedFingerprint))
            ) {
                throw new HostKeyMismatchException(self::hostId($this->host, $this->port), self::fingerprint($key));
            }

            if (!$this->recordedKey) {
                ($this->recordKey)($key);
                $this->recordedKey = $key;
            }

            if (!$connection->login($this->username, $this->password)) {
                throw UnableToAuthenticate::withPassword($connection->getLastError());
            }
        } catch (Throwable $exception) {
            $connection->disconnect();
            throw $exception;
        }

        return $this->connection = $connection;
    }

    public function disconnect(): void
    {
        $this->connection?->disconnect();
        $this->connection = null;
    }

    /**
     * The address to connect to, checked to be public.
     *
     * The connection is made to this address rather than to the host name, so that the name
     * cannot be pointed at an internal address between the check and the connection.
     *
     * @throws HostNotAllowedException When any address the host resolves to is not public
     */
    protected function allowedAddress(): string
    {
        $addresses = $this->lookUp(trim($this->host, ' []'));
        if (!$addresses) {
            throw UnableToConnectToSftpHost::atHostname($this->host);
        }
        foreach ($addresses as $address) {
            if (!self::isPublicAddress($address)) {
                throw new HostNotAllowedException($this->host);
            }
        }
        return str_contains($addresses[0], ':') ? "[{$addresses[0]}]" : $addresses[0];
    }

    /**
     * The addresses a host resolves to, IPv4 first.
     *
     * @return string[]
     */
    protected function lookUp(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ipv4 = gethostbynamel($host) ?: [];
        $ipv6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');
        return [...$ipv4, ...$ipv6];
    }

    protected function connect(string $address): SFTP
    {
        return new SFTP($address, $this->port, $this->timeout);
    }
}
