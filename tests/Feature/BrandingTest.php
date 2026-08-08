<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Identitas aplikasi pada halaman yang dilihat sebelum masuk.
 */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Halaman login tidak boleh menyebutkan kredensial apa pun.
     *
     * Alamat dan kata sandi contoh berguna saat aplikasi masih diuji, tetapi di
     * server yang sudah dipakai ia adalah undangan terbuka — dan akun itu memang
     * ada, dibuat oleh seeder.
     */
    public function test_the_login_page_never_shows_any_credentials(): void
    {
        $response = $this->get(route('login'))->assertOk();

        $response->assertDontSee('Akun demo');
        $response->assertDontSee('wmsotosby.test');
        $response->assertDontSee('password</span>', false);
    }

    public function test_the_login_page_carries_the_company_mark(): void
    {
        $this->get(route('login'))
            ->assertOk()
            // Titik jingga di antara kedua hurufnya.
            ->assertSee('#F5A623', false)
            ->assertSee('#1B2A6B', false);
    }

    /**
     * Favicon disebut terang-terangan, bukan mengandalkan tebakan peramban atas
     * favicon.ico bawaan Laravel yang masih tertinggal di public.
     */
    public function test_the_favicon_is_declared_and_present(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('logo.svg', false);

        $this->assertFileExists(public_path('logo.svg'));
    }
}
