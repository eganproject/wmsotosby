<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AjaxNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();
    }

    public function test_normal_request_returns_the_full_document(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.users.index'));

        $response->assertOk()
            ->assertSee('<!DOCTYPE html>', false)
            ->assertSee('id="page-progress"', false);
    }

    public function test_ajax_request_returns_only_the_page_fragment(): void
    {
        $response = $this->actingAs($this->admin)
            ->withHeader('X-Page-Fragment', '1')
            ->get(route('admin.users.index'));

        $response->assertOk()
            ->assertDontSee('<!DOCTYPE html>', false)
            ->assertDontSee('id="page-progress"', false)
            ->assertSee('id="page-fragment"', false)
            ->assertSee('data-fragment="content"', false)
            ->assertSee('data-fragment="nav"', false)
            ->assertSee('data-fragment="flash"', false);
    }

    public function test_fragment_carries_the_page_title_for_the_browser_tab(): void
    {
        $response = $this->actingAs($this->admin)
            ->withHeader('X-Page-Fragment', '1')
            ->get(route('admin.roles.index'));

        $response->assertOk()->assertSee('data-title="Role — '.config('app.name').'"', false);
    }

    public function test_fragment_carries_the_flash_message_after_a_redirect(): void
    {
        $response = $this->actingAs($this->admin)
            ->withHeader('X-Page-Fragment', '1')
            ->withSession(['success' => 'Pengguna berhasil ditambahkan.'])
            ->get(route('admin.users.index'));

        $response->assertOk()->assertSee('Pengguna berhasil ditambahkan.');
    }

    public function test_search_filter_narrows_the_result_set(): void
    {
        User::factory()->create(['name' => 'Rina Wijaya']);
        User::factory()->create(['name' => 'Budi Santoso']);

        $response = $this->actingAs($this->admin)
            ->withHeader('X-Page-Fragment', '1')
            ->get(route('admin.users.index', ['search' => 'Rina']));

        $response->assertOk()
            ->assertSee('Rina Wijaya')
            ->assertDontSee('Budi Santoso');
    }
}
