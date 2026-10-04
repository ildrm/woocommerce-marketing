<?php
declare(strict_types=1);
namespace Wmos\Tests\Unit;
use PHPUnit\Framework\TestCase;
use Wmos\Infrastructure\Secrets;
final class SecretsTest extends TestCase
{
    public function testVaultIsRandomizedAuthenticatedAndPurposeBound(): void
    {
        $vault=new Secrets(base64_encode(str_repeat('k',32)));$first=$vault->encrypt('credential','provider:a');$second=$vault->encrypt('credential','provider:a');
        self::assertNotSame($first,$second);self::assertStringNotContainsString('credential',$first);self::assertSame('credential',$vault->decrypt($first,'provider:a'));
        $this->expectException(\RuntimeException::class);$vault->decrypt($first,'provider:b');
    }
    public function testMissingKeyFailsClosed():void
    {
        $vault=new Secrets('invalid');self::assertFalse($vault->available());$this->expectException(\RuntimeException::class);$vault->encrypt('x','a');
    }
    public function testIdentityHmacScopesAreIndependent():void
    {
        $vault=new Secrets(base64_encode(str_repeat('k',32)));self::assertSame(32,strlen($vault->hash('a','email')));self::assertNotSame($vault->hash('a','email'),$vault->hash('a','phone'));
    }
}
