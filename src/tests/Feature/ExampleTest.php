<?php

declare(strict_types=1);

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * §4's home page, not the old redirect to /catalogue — see
     * tests/Feature/Livewire/HomeTest.php for what it actually shows per
     * section; this is only the smoke check that the route itself answers.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertOk();
    }
}
