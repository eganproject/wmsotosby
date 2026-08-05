<?php

namespace Tests\Feature\Admin;

use App\Models\Inbound;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();
    }

    public function test_supplier_pages_are_rendered(): void
    {
        $supplier = Supplier::create(['code' => 'SUP-0001', 'name' => 'PT Sumber Otoparts']);

        foreach ([
            route('admin.suppliers.index'),
            route('admin.suppliers.create'),
            route('admin.suppliers.show', $supplier),
            route('admin.suppliers.edit', $supplier),
        ] as $page) {
            $this->actingAs($this->admin)->get($page)->assertOk();
        }
    }

    public function test_a_supplier_can_be_created_updated_and_deleted(): void
    {
        $this->actingAs($this->admin)->post(route('admin.suppliers.store'), [
            'name' => 'PT Sumber Otoparts',
            'contact_name' => 'Budi',
            'phone' => '081234567890',
            'email' => 'sales@sumber.test',
            'is_active' => '1',
        ])->assertRedirect(route('admin.suppliers.index'));

        $supplier = Supplier::firstOrFail();

        // Kode dibuat otomatis oleh sistem.
        $this->assertSame('SUP-0001', $supplier->code);
        $this->assertTrue($supplier->is_active);

        $this->actingAs($this->admin)->put(route('admin.suppliers.update', $supplier), [
            'name' => 'PT Sumber Otoparts Jaya',
        ])->assertRedirect(route('admin.suppliers.index'));

        $this->assertSame('PT Sumber Otoparts Jaya', $supplier->refresh()->name);
        $this->assertFalse($supplier->is_active);

        $this->actingAs($this->admin)->delete(route('admin.suppliers.destroy', $supplier))
            ->assertRedirect(route('admin.suppliers.index'));

        $this->assertNull($supplier->fresh());
    }

    public function test_supplier_codes_increment(): void
    {
        Supplier::create(['code' => Supplier::nextCode(), 'name' => 'Pemasok A']);
        Supplier::create(['code' => Supplier::nextCode(), 'name' => 'Pemasok B']);

        $this->assertSame(['SUP-0001', 'SUP-0002'], Supplier::orderBy('code')->pluck('code')->all());
    }

    public function test_a_supplier_used_by_a_document_can_not_be_deleted(): void
    {
        $supplier = Supplier::create(['code' => 'SUP-0001', 'name' => 'PT Sumber Otoparts']);

        Inbound::create([
            'code' => Inbound::nextCode(),
            'date' => now(),
            'supplier_id' => $supplier->id,
            'status' => Inbound::STATUS_DRAFT,
        ]);

        $this->actingAs($this->admin)->delete(route('admin.suppliers.destroy', $supplier))
            ->assertSessionHas('error');

        $this->assertNotNull($supplier->fresh());
    }

    public function test_an_inbound_document_stores_the_selected_supplier(): void
    {
        $supplier = Supplier::create(['code' => 'SUP-0001', 'name' => 'PT Sumber Otoparts']);
        $product = Product::create(['sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli', 'unit' => 'pcs']);

        $this->actingAs($this->admin)->post(route('admin.inbounds.store'), [
            'date' => now()->format('Y-m-d'),
            'supplier_id' => $supplier->id,
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ])->assertSessionHasNoErrors();

        $inbound = Inbound::latest('id')->firstOrFail();

        $this->assertSame($supplier->id, $inbound->supplier_id);
        $this->assertSame('PT Sumber Otoparts', $inbound->supplier->name);
    }

    public function test_an_unknown_supplier_is_rejected(): void
    {
        $product = Product::create(['sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli', 'unit' => 'pcs']);

        $this->actingAs($this->admin)->post(route('admin.inbounds.store'), [
            'date' => now()->format('Y-m-d'),
            'supplier_id' => 999,
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ])->assertSessionHasErrors('supplier_id');
    }
}
