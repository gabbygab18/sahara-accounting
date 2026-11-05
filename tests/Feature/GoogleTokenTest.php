<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\GoogleAccessRevoked;
use App\Services\GoogleSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('google.refresh_token', Crypt::encryptString('refresh'));
        Setting::set('google.account', 'owner@example.com');
    }

    public function test_a_rejected_access_token_is_replaced_and_the_request_retried()
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::sequence()->push(['access_token' => 'old'])->push(['access_token' => 'new']),
            'sheets.googleapis.com/*' => Http::sequence()->push([], 401)->push(['properties' => ['title' => 'STOCKS']]),
        ]);

        $this->assertSame('STOCKS', (new GoogleSheet('abc'))->title());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sheets.') && $r->hasHeader('Authorization', 'Bearer new'));
    }

    public function test_connecting_without_the_sheets_permission_is_refused()
    {
        Setting::set('google.refresh_token', null);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'scope' => 'openid https://www.googleapis.com/auth/userinfo.email'])]);

        try {
            GoogleSheet::connect('code');
            $this->fail('Expected the missing scope to be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('tick the box', $e->getMessage());
        }
        $this->assertFalse(GoogleSheet::signedIn());
    }

    public function test_a_revoked_refresh_token_asks_to_connect_again()
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->expectException(GoogleAccessRevoked::class);
        $this->expectExceptionMessage('owner@example.com has expired or was revoked. Press Connect Google again');
        (new GoogleSheet('abc'))->title();
    }
}
