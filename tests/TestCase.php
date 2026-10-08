<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Fichiers dans un dossier jetable, et aucun appel réel à Internet.
        Storage::fake('local');
        Http::preventStrayRequests();
    }
}
