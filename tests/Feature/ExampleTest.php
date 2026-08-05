<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root URL forwards visitors into the admin panel.
     */
    public function test_the_root_url_redirects_to_the_admin_panel(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/admin');
    }
}
