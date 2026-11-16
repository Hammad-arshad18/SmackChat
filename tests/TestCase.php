<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // CI does not build Vite assets for the Tests job.
        $this->withoutVite();

        // Deferred work (push fan-out, mail) runs after the response in
        // production; run it inline here so assertions see the effect.
        $this->withoutDefer();
    }
}
