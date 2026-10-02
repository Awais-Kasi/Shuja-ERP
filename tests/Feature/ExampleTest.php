<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_a_guest_to_login()
    {
        // The app opens on the login page for guests (and the dashboard once signed in).
        $this->get(route('home'))->assertRedirect(route('login'));
    }
}
