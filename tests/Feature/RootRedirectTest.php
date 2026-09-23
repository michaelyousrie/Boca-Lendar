<?php

namespace Tests\Feature;

use Tests\TestCase;

class RootRedirectTest extends TestCase
{
    public function test_the_root_redirects_to_appointments(): void
    {
        $this->get('/')->assertRedirect('/appointments');
    }
}
