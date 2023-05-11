<?php
/**
* @covers \KMM\Hacks\Core
*/
use KMM\Hacks\Core;

class TestHacks extends \WP_UnitTestCase
{
    public function setUp(): void
    {
        // setup a rest server
        parent::setUp();
        $this->core = new Core('i18n');
    }

    /**
     * @test
     */
    public function sample()
    {
        $this->assertEquals(1, 1);
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }
}
