<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Mfa;

use InvalidArgumentException;
use Nowo\AuthKitBundle\Mfa\Totp\Base32;
use Nowo\AuthKitBundle\Mfa\Totp\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function strlen;

final class TotpTest extends TestCase
{
    /**
     * RFC 6238 Appendix B (SHA-1, secret "12345678901234567890", 8 digits).
     *
     * @return iterable<string, array{int, string}>
     */
    public static function rfcVectors(): iterable
    {
        yield '59' => [59, '94287082'];
        yield '1111111109' => [1111111109, '07081804'];
        yield '1111111111' => [1111111111, '14050471'];
        yield '1234567890' => [1234567890, '89005924'];
        yield '2000000000' => [2000000000, '69279037'];
        yield '20000000000' => [20000000000, '65353130'];
    }

    #[DataProvider('rfcVectors')]
    public function testRfc6238Vectors(int $time, string $expected): void
    {
        $totp   = new Totp(8, 30, 0);
        $secret = Base32::encode('12345678901234567890');
        self::assertSame($expected, $totp->code($secret, $totp->step($time)));
        self::assertSame($totp->step($time), $totp->verify($secret, $expected, $time));
    }

    public function testWindowReplayAndFormat(): void
    {
        $totp   = new Totp();
        $secret = Totp::generateSecret();
        self::assertSame(32, strlen($secret));
        $now  = 1_800_000_000;
        $step = $totp->step($now);
        self::assertSame($step - 1, $totp->verify($secret, $totp->code($secret, $step - 1), $now), 'One step of clock skew is accepted.');
        self::assertNull($totp->verify($secret, $totp->code($secret, $step - 2), $now), 'Two steps are not.');
        self::assertNull($totp->verify($secret, $totp->code($secret, $step), $now, $step), 'A used step is refused.');
        self::assertSame($step + 1, $totp->verify($secret, $totp->code($secret, $step + 1), $now, $step));
        self::assertNull($totp->verify($secret, '12345', $now));
        self::assertNull($totp->verify($secret, "123456\n", $now));
        self::assertNull($totp->verify($secret, 'abcdef', $now));
    }

    public function testProvisioningUri(): void
    {
        self::assertSame(
            'otpauth://totp/Acme%20Inc:ana%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=Acme%20Inc&algorithm=SHA1&digits=6&period=30',
            (new Totp())->provisioningUri('Acme Inc', 'ana@example.com', 'JBSWY3DPEHPK3PXP'),
        );
    }

    public function testShortSecretsAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Totp())->code('JBSWY3DP', 1);
    }

    public function testBase32(): void
    {
        self::assertSame('', Base32::encode(''));
        self::assertSame('MZXW6YTBOI', Base32::encode('foobar'));
        self::assertSame('foobar', Base32::decode('mzxw 6ytb-oi======'));
        self::assertSame('MZXW 6YTB OI', Base32::group('MZXW6YTBOI'));
        self::assertSame('', Base32::group(''));
        $this->expectException(InvalidArgumentException::class);
        Base32::decode('MZ1W');
    }
}
