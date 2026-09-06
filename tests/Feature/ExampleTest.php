<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    // "/" is the public building page now rather than a static welcome view,
    // so this smoke test needs a schema to query. What the page actually shows
    // is covered by Modules/PublicSite/tests.
    use RefreshDatabase;

    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
