<?php

namespace Tests\Feature;

use Tests\TestCase;

class PrivacyPageTest extends TestCase
{
    public function test_privacy_policy_is_public(): void
    {
        config(['app.support_email' => 'privacy@example.test']);

        $this->get('/privacy')
            ->assertOk()
            ->assertSee('Privacy Policy')
            ->assertSee('end-to-end encrypted')
            ->assertSee('mailto:privacy@example.test', false);
    }

    public function test_privacy_policy_is_in_robots_and_sitemap(): void
    {
        $this->get('/robots.txt')->assertSee('Allow: /privacy');
        $this->get('/sitemap.xml')->assertSee('/privacy</loc>', false);
    }
}
