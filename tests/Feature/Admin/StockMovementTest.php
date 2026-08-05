<?php

namespace Tests\Feature\Admin;

use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Halaman mutasi stok: seluruh pergerakan barang dari semua dokumen.
 */
class StockMovementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $oli;

    protected Product $rem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();

        $this->oli = Product::create([
            'sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli Standar', 'unit' => 'pcs', 'min_stock' => 2,
        ]);

        $this->rem = Product::create([
            'sku' => 'KMP-REM-DPN', 'name' => 'Kampas Rem Depan', 'unit' => 'set', 'min_stock' => 1,
        ]);
    }

    /* --------------------------------------------------- halaman --------- */

    public function test_the_page_lists_every_movement_with_its_running_balance(): void
    {
        $this->receive($this->oli, 10);
        $this->ship($this->oli, 4);

        $this->actingAs($this->admin)->get(route('admin.movements.index'))
            ->assertOk()
            ->assertSee('Mutasi Stok')
            ->assertSee('Filter Oli Standar')
            ->assertSee('FLT-OLI-STD')
            ->assertSee('Barang Masuk')
            ->assertSee('Barang Keluar')
            // Saldo setelah masuk 10 lalu keluar 4.
            ->assertSee('10')
            ->assertSee('6');
    }

    public function test_the_summary_counts_incoming_and_outgoing_separately(): void
    {
        $this->receive($this->oli, 10);
        $this->receive($this->rem, 5);
        $this->ship($this->oli, 4);

        $response = $this->actingAs($this->admin)->get(route('admin.movements.index'))->assertOk();

        $summary = $response->viewData('summary');

        $this->assertSame(15, $summary['incoming']);
        $this->assertSame(4, $summary['outgoing']);
        $this->assertSame(11, $summary['net']);
        $this->assertSame(3, $summary['entries']);
        $this->assertSame(2, $summary['products']);
    }

    public function test_an_empty_warehouse_shows_an_explanation(): void
    {
        $this->actingAs($this->admin)->get(route('admin.movements.index'))
            ->assertOk()
            ->assertSee('Belum ada mutasi stok');
    }

    /* --------------------------------------------------- filter ---------- */

    public function test_movements_can_be_filtered_to_one_product(): void
    {
        $this->receive($this->oli, 10);
        $this->receive($this->rem, 5);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.movements.index', ['product_id' => $this->oli->id]))
            ->assertOk();

        $this->assertSame(1, $response->viewData('movements')->total());
        $this->assertSame(10, $response->viewData('summary')['incoming']);
    }

    public function test_movements_can_be_filtered_by_direction(): void
    {
        $this->receive($this->oli, 10);
        $this->ship($this->oli, 4);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.movements.index', ['type' => 'out']))
            ->assertOk();

        $this->assertSame(1, $response->viewData('movements')->total());
        $this->assertSame(0, $response->viewData('summary')['incoming']);
        $this->assertSame(4, $response->viewData('summary')['outgoing']);
    }

    public function test_movements_can_be_filtered_by_source_document(): void
    {
        $this->receive($this->oli, 10);
        $this->ship($this->oli, 4);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.movements.index', ['source' => 'outbound']))
            ->assertOk();

        $this->assertSame(1, $response->viewData('movements')->total());
        $this->assertSame('Barang Keluar', $response->viewData('movements')->first()->sourceLabel());
    }

    public function test_searching_matches_the_product_and_the_description(): void
    {
        $this->receive($this->oli, 10);
        $this->receive($this->rem, 5);

        $bySku = $this->actingAs($this->admin)
            ->get(route('admin.movements.index', ['search' => 'KMP-REM']))
            ->assertOk();

        $this->assertSame(1, $bySku->viewData('movements')->total());

        $byDescription = $this->actingAs($this->admin)
            ->get(route('admin.movements.index', ['search' => 'Barang masuk']))
            ->assertOk();

        $this->assertSame(2, $byDescription->viewData('movements')->total());
    }

    public function test_a_date_range_narrows_the_period(): void
    {
        $this->receive($this->oli, 10);

        // Mutasi lama, di luar rentang yang diminta.
        StockMovement::where('product_id', $this->oli->id)
            ->update(['created_at' => now()->subMonth()]);

        $this->receive($this->rem, 5);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.movements.index', ['from' => now()->toDateString()]))
            ->assertOk();

        $this->assertSame(1, $response->viewData('movements')->total());
        $this->assertSame(5, $response->viewData('summary')['incoming']);
    }

    /* --------------------------------------------------- keterlacakan ---- */

    public function test_every_movement_points_back_to_its_document(): void
    {
        $inbound = $this->receive($this->oli, 10);

        $movement = StockMovement::firstOrFail();

        $this->assertSame('Barang Masuk', $movement->sourceLabel());
        $this->assertSame(route('admin.inbounds.show', $inbound), $movement->sourceUrl());

        $this->actingAs($this->admin)->get(route('admin.movements.index'))
            ->assertOk()
            ->assertSee(route('admin.inbounds.show', $inbound));
    }

    /* --------------------------------------------------- export ---------- */

    public function test_the_export_follows_the_active_filter(): void
    {
        $this->receive($this->oli, 10);
        $this->ship($this->oli, 4);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.movements.export', ['type' => 'out']));

        $response->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertStringContainsString('mutasi-stok-', $response->headers->get('content-disposition'));
        $this->assertNotEmpty($response->streamedContent());
    }

    /* --------------------------------------------------- hak akses ------- */

    public function test_the_page_needs_its_own_permission(): void
    {
        $role = Role::create(['name' => 'Tanpa Mutasi', 'slug' => 'tanpa-mutasi']);
        $role->permissions()->sync(Permission::whereIn('slug', ['dashboard.view', 'products.view'])->pluck('id'));

        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get(route('admin.movements.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.movements.export'))->assertForbidden();

        // Menunya pun tidak ditawarkan.
        $this->actingAs($user)->get(route('admin.products.index'))
            ->assertOk()
            ->assertDontSee(route('admin.movements.index'));
    }

    public function test_warehouse_staff_can_read_the_movements(): void
    {
        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff-gudang')->value('id')]);

        $this->actingAs($staff)->get(route('admin.movements.index'))->assertOk();
    }

    /* --------------------------------------------------- helpers --------- */

    protected function receive(Product $product, int $quantity): Inbound
    {
        $inbound = Inbound::create([
            'code' => Inbound::nextCode(),
            'date' => now(),
            'status' => Inbound::STATUS_DRAFT,
        ]);

        $inbound->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));

        return $inbound;
    }

    protected function ship(Product $product, int $quantity): Outbound
    {
        $outbound = Outbound::create([
            'code' => Outbound::nextCode(),
            'date' => now(),
            'type' => Outbound::TYPE_REGULAR,
            'recipient' => 'Bengkel Jaya',
            'status' => Outbound::STATUS_DRAFT,
        ]);

        $outbound->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        $this->actingAs($this->admin)->post(route('admin.outbounds.submit', $outbound));

        return $outbound;
    }
}
