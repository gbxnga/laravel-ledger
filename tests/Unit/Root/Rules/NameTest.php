<?php

namespace Abivia\Ledger\Tests\Unit\Root\Rules;

use Abivia\Ledger\Root\Rules\Name;
use Abivia\Ledger\Tests\TestCase;
use PHPUnit\Framework\Attributes\Depends;

class NameTest extends TestCase
{
    public static Name $name;

    public function testHydration()
    {
        $obj = new Name();
        $this->assertFalse(isset($obj->name));
        $this->assertTrue($obj->hydrate('{"name":"This is a name"}'));
        $this->assertEquals('This is a name', $obj->name);
        $this->assertEquals('en', $obj->language);
        self::$name = $obj;
    }

    #[Depends('testHydration')]
    public function testEncode(): void
    {
        $json = json_encode(self::$name);
        $expect = '{"language":"en","name":"This is a name"}';
        $this->assertEquals($expect, $json);
    }

}
