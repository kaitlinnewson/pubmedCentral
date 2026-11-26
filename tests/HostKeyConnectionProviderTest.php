<?php

/**
 * @file tests/HostKeyConnectionProviderTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Unit tests for the SFTP connection that checks the server's host key.
 */

namespace APP\plugins\generic\pubmedCentral\tests;

use APP\plugins\generic\pubmedCentral\classes\HostKeyConnectionProvider;
use APP\plugins\generic\pubmedCentral\classes\HostKeyMismatchException;
use APP\plugins\generic\pubmedCentral\classes\HostNotAllowedException;
use League\Flysystem\PhpseclibV3\UnableToAuthenticate;
use League\Flysystem\PhpseclibV3\UnableToConnectToSftpHost;
use phpseclib3\Net\SFTP;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PKP\tests\PKPTestCase;
use RuntimeException;

#[CoversClass(HostKeyConnectionProvider::class)]
class HostKeyConnectionProviderTest extends PKPTestCase
{
    // Throwaway keys, with the fingerprints `ssh-keygen -l -E sha256` and `-E md5` give for them
    private const ED25519_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAICqQGxvlHkmK4AuiP4zU7sstYcbsmJKw33GNEEbu/gCl';
    private const ED25519_SHA256 = 'SHA256:UnuSuf27CkGdsMtclRwXM+Kwlfxm3tq7c8x2OcD6rZM';
    private const ED25519_MD5 = 'MD5:30:bb:4c:ea:39:47:ea:0b:b8:15:a4:18:99:4f:62:f7';
    private const RSA_BLOB = 'AAAAB3NzaC1yc2EAAAADAQABAAAAgQDYuQ3q+UK5PzThjG7plx1/1JpHPaQ/5EpM2dnOnN/zYFzW8YOH/qHMOQhXa74shO2S/1FjLzergYsMnEc6ZRQdwljuB3UpI1y1xz32foXuro5cvy3YJjoAxM5cRY8lMRJG8m+J2J00Md/oy820Da9AZ/HU/rYOE9HvOdqm+qmj6Q==';
    private const RSA_SHA256 = 'SHA256:8iF492S8TEsjOYv4CBvy72Xuwj7GOzTD9mP3+RPPwd4';

    /**
     * Build a provider whose connection is the given stand-in, recording any key it records.
     */
    private function createProvider(
        SFTP $connection,
        ?string $recordedKey = null,
        ?string $expectedFingerprint = null,
        array &$recorded = [],
        array $addresses = ['8.8.8.8']
    ): HostKeyConnectionProvider {
        return new class ($connection, $recordedKey, $expectedFingerprint, $recorded, $addresses) extends HostKeyConnectionProvider {
            /** The address connect() was given */
            public ?string $connectedTo = null;

            public function __construct(
                private SFTP $fakeConnection,
                ?string $recordedKey,
                ?string $expectedFingerprint,
                array &$recorded,
                private array $addresses
            ) {
                parent::__construct(
                    'SFTP.Example.org',
                    22,
                    'user',
                    'secret',
                    $recordedKey,
                    $expectedFingerprint,
                    function (string $key) use (&$recorded) {
                        $recorded[] = $key;
                    }
                );
            }

            protected function lookUp(string $host): array
            {
                return $this->addresses;
            }

            protected function connect(string $address): SFTP
            {
                $this->connectedTo = $address;
                return $this->fakeConnection;
            }
        };
    }

    private function createConnection(string|false $hostKey, bool $loginSucceeds = true): SFTP&MockObject
    {
        $connection = $this->createMock(SFTP::class);
        $connection->method('getServerPublicHostKey')->willReturn($hostKey);
        $connection->method('login')->willReturn($loginSucceeds);
        return $connection;
    }

    public function testFingerprintMatchesSshKeygen(): void
    {
        $this->assertSame(self::ED25519_SHA256, HostKeyConnectionProvider::fingerprint(self::ED25519_KEY));
        $this->assertSame(self::RSA_SHA256, HostKeyConnectionProvider::fingerprint('ssh-rsa ' . self::RSA_BLOB));
    }

    /**
     * phpseclib names the signature the server chose, which for an RSA key is usually not
     * "ssh-rsa", so the same key may be reported under different names.
     */
    public function testKeysAreComparedWithoutTheirAlgorithmName(): void
    {
        $this->assertTrue(HostKeyConnectionProvider::isSameKey('ssh-rsa ' . self::RSA_BLOB, 'rsa-sha2-256 ' . self::RSA_BLOB));
        $this->assertFalse(HostKeyConnectionProvider::isSameKey(self::ED25519_KEY, 'ssh-rsa ' . self::RSA_BLOB));
        $this->assertSame(self::RSA_SHA256, HostKeyConnectionProvider::fingerprint('rsa-sha2-512 ' . self::RSA_BLOB));
    }

    public static function fingerprintProvider(): array
    {
        return [
            'sha256' => [self::ED25519_SHA256, self::ED25519_SHA256],
            'sha256 without prefix' => [substr(self::ED25519_SHA256, 7), self::ED25519_SHA256],
            'sha256 with padding and spaces' => [' ' . self::ED25519_SHA256 . '= ', self::ED25519_SHA256],
            'md5' => [self::ED25519_MD5, self::ED25519_MD5],
            'md5 uppercase without prefix' => [strtoupper(substr(self::ED25519_MD5, 4)), self::ED25519_MD5],
            'truncated' => ['SHA256:UnuSuf27CkGdsMtcl', null],
            'empty' => ['', null],
            'a key rather than a fingerprint' => [self::ED25519_KEY, null],
        ];
    }

    #[DataProvider('fingerprintProvider')]
    public function testNormalizeFingerprint(string $fingerprint, ?string $expected): void
    {
        $this->assertSame($expected, HostKeyConnectionProvider::normalizeFingerprint($fingerprint));
    }

    public function testMatchesFingerprintInEitherForm(): void
    {
        $this->assertTrue(HostKeyConnectionProvider::matchesFingerprint(self::ED25519_KEY, self::ED25519_SHA256));
        $this->assertTrue(HostKeyConnectionProvider::matchesFingerprint(self::ED25519_KEY, self::ED25519_MD5));
        $this->assertFalse(HostKeyConnectionProvider::matchesFingerprint(self::ED25519_KEY, self::RSA_SHA256));
    }

    public function testHostIdIgnoresCaseAndCarriesThePort(): void
    {
        $this->assertSame('sftp.example.org:2222', HostKeyConnectionProvider::hostId(' SFTP.Example.org ', 2222));
    }

    public function testFirstConnectionRecordsTheKeyAndLogsIn(): void
    {
        $connection = $this->createConnection(self::ED25519_KEY);
        $connection->expects($this->once())->method('login')->with('user', 'secret')->willReturn(true);
        $recorded = [];

        $provider = $this->createProvider($connection, null, null, $recorded);

        $this->assertSame($connection, $provider->provideConnection());
        // Reused rather than connecting again
        $this->assertSame($connection, $provider->provideConnection());
        $this->assertSame([self::ED25519_KEY], $recorded);
    }

    public function testRecordedKeyIsAccepted(): void
    {
        $recorded = [];
        $provider = $this->createProvider($this->createConnection('rsa-sha2-256 ' . self::RSA_BLOB), 'ssh-rsa ' . self::RSA_BLOB, null, $recorded);

        $provider->provideConnection();

        $this->assertSame([], $recorded);
    }

    /**
     * The check happens before logging in, so the credentials never reach a server presenting another key.
     */
    public function testAnotherKeyIsRefusedBeforeLoggingIn(): void
    {
        $connection = $this->createConnection('ssh-rsa ' . self::RSA_BLOB);
        $connection->expects($this->never())->method('login');
        $connection->expects($this->once())->method('disconnect');

        $provider = $this->createProvider($connection, self::ED25519_KEY);

        try {
            $provider->provideConnection();
            $this->fail('A host key mismatch was not reported');
        } catch (HostKeyMismatchException $e) {
            $this->assertSame('sftp.example.org:22', $e->host);
            $this->assertSame(self::RSA_SHA256, $e->fingerprint);
        }
    }

    public function testFirstConnectionIsCheckedAgainstAnExpectedFingerprint(): void
    {
        $connection = $this->createConnection('ssh-rsa ' . self::RSA_BLOB);
        $connection->expects($this->never())->method('login');
        $recorded = [];

        $provider = $this->createProvider($connection, null, self::ED25519_SHA256, $recorded);

        try {
            $provider->provideConnection();
            $this->fail('A host key mismatch was not reported');
        } catch (HostKeyMismatchException) {
            // Nothing is recorded from a server that failed the check
            $this->assertSame([], $recorded);
        }
    }

    public function testFirstConnectionMatchingTheExpectedFingerprintIsRecorded(): void
    {
        $recorded = [];
        $provider = $this->createProvider($this->createConnection(self::ED25519_KEY), null, self::ED25519_MD5, $recorded);

        $provider->provideConnection();

        $this->assertSame([self::ED25519_KEY], $recorded);
    }

    public function testFailedLoginIsReported(): void
    {
        $provider = $this->createProvider($this->createConnection(self::ED25519_KEY, false));

        $this->expectException(UnableToAuthenticate::class);
        $provider->provideConnection();
    }

    public static function addressProvider(): array
    {
        return [
            'public IPv4' => ['8.8.8.8', true],
            'public IPv6' => ['2001:4860:4860::8888', true],
            'loopback' => ['127.0.0.1', false],
            'IPv6 loopback' => ['::1', false],
            'unspecified' => ['0.0.0.0', false],
            'private 10/8' => ['10.1.2.3', false],
            'private 172.16/12' => ['172.20.0.1', false],
            'private 192.168/16' => ['192.168.1.1', false],
            'cloud metadata service' => ['169.254.169.254', false],
            'IPv6 unique local' => ['fd00::1', false],
            'IPv6 link-local' => ['fe80::1', false],
        ];
    }

    #[DataProvider('addressProvider')]
    public function testIsPublicAddress(string $address, bool $public): void
    {
        $this->assertSame($public, HostKeyConnectionProvider::isPublicAddress($address));
    }

    /**
     * The connection is made to the address that was checked, so the host name cannot be
     * pointed elsewhere between the check and the connection.
     */
    public function testConnectsToTheCheckedAddress(): void
    {
        $recorded = [];
        $provider = $this->createProvider($this->createConnection(self::ED25519_KEY), null, null, $recorded, ['8.8.8.8', '2001:4860:4860::8888']);

        $provider->provideConnection();

        $this->assertSame('8.8.8.8', $provider->connectedTo);
    }

    public function testIpv6AddressIsBracketed(): void
    {
        $recorded = [];
        $provider = $this->createProvider($this->createConnection(self::ED25519_KEY), null, null, $recorded, ['2001:4860:4860::8888']);

        $provider->provideConnection();

        $this->assertSame('[2001:4860:4860::8888]', $provider->connectedTo);
    }

    /**
     * A host with any internal address is refused without connecting, as a name may
     * resolve to several addresses.
     */
    public function testHostWithAnInternalAddressIsRefused(): void
    {
        $recorded = [];
        $provider = $this->createProvider($this->createConnection(self::ED25519_KEY), null, null, $recorded, ['8.8.8.8', '10.0.0.5']);

        try {
            $provider->provideConnection();
            $this->fail('An internal host was not refused');
        } catch (HostNotAllowedException $e) {
            $this->assertSame('SFTP.Example.org', $e->host);
            $this->assertNull($provider->connectedTo);
        }
    }

    public function testHostThatDoesNotResolveIsReportedAsUnreachable(): void
    {
        $recorded = [];
        $provider = $this->createProvider($this->createConnection(self::ED25519_KEY), null, null, $recorded, []);

        $this->expectException(UnableToConnectToSftpHost::class);
        $provider->provideConnection();
    }

    /**
     * Why a connection failed would tell whether something listens on the port, so it is
     * kept out of the message.
     */
    public function testConnectionFailuresAreReportedAlike(): void
    {
        $connection = $this->createMock(SFTP::class);
        $connection->method('getServerPublicHostKey')
            ->willThrowException(new RuntimeException('Cannot connect to 8.8.8.8:5432. Error 111. Connection refused'));
        $connection->expects($this->never())->method('login');

        try {
            $this->createProvider($connection)->provideConnection();
            $this->fail('The connection failure was not reported');
        } catch (UnableToConnectToSftpHost $e) {
            $this->assertSame('Unable to connect to host: SFTP.Example.org', $e->getMessage());
        }
    }
}
