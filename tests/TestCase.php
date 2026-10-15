<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // I test non devono mai usare le credenziali vere del .env: un invio non simulato scriverebbe a un numero reale.
        config(['services.whatsapp.token' => 'TEST-TOKEN', 'services.whatsapp.phone_number_id' => '0']);
    }
}
