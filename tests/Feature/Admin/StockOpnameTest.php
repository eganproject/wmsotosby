<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\Role;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stok opname: sesi hitung fisik yang berujung pada penyesuaian saldo.
 */
class StockOpnameTest extends TestCase
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

        $this->oli = $this->makeProduct('FLT-OLI-STD', 'Filter Oli Standar', stock: 10, category: 'Filter');
        $this->rem = $this->makeProduct('KMP-REM-DPN', 'Kampas Rem Depan', stock: 4, category: 'Rem');
    }

    /* --------------------------------------------------- membuka sesi ---- */

    public function test_opening_a_session_snapshots_the_products_in_scope(): void
    {
        $this->actingAs($this->admin)->post(route('admin.opnames.store'), [
            'date' => now()->toDateString(),
            'scope' => StockOpname::SCOPE_ALL,
        ])->assertRedirect();

        $opname = StockOpname::with('items')->firstOrFail();

        $this->assertCount(2, $opname->items);
        $this->assertSame(10, $opname->items->firstWhere('product_id', $this->oli->id)->system_quantity);
        // Belum dihitung disimpan sebagai NULL, bukan nol.
        $this->assertNull($opname->items->first()->counted_quantity);
        $this->assertTrue($opname->isDraft());
    }

    public function test_a_session_can_cover_one_category_only(): void
    {
        $this->actingAs($this->admin)->post(route('admin.opnames.store'), [
            'date' => now()->toDateString(),
            'scope' => StockOpname::SCOPE_CATEGORY,
            'scope_value' => 'Filter',
        ])->assertRedirect();

        $opname = StockOpname::with('items')->firstOrFail();

        $this->assertCount(1, $opname->items);
        $this->assertSame($this->oli->id, $opname->items->first()->product_id);
        $this->assertSame('Kategori Filter', $opname->scopeLabel());
    }

    public function test_a_scope_without_products_is_refused(): void
    {
        $this->actingAs($this->admin)->post(route('admin.opnames.store'), [
            'date' => now()->toDateString(),
            'scope' => StockOpname::SCOPE_CATEGORY,
            'scope_value' => 'Tidak Ada',
        ])->assertSessionHas('error');

        $this->assertDatabaseCount('stock_opnames', 0);
    }

    public function test_a_narrowed_scope_needs_its_value(): void
    {
        $this->actingAs($this->admin)->post(route('admin.opnames.store'), [
            'date' => now()->toDateString(),
            'scope' => StockOpname::SCOPE_LOCATION,
        ])->assertSessionHasErrors('scope_value');
    }

    /* --------------------------------------------------- menghitung ------ */

    public function test_counts_are_saved_per_row(): void
    {
        $opname = $this->openSession();
        $items = $opname->items->keyBy('product_id');

        $this->actingAs($this->admin)->post(route('admin.opnames.count', $opname), [
            'counts' => [$items[$this->oli->id]->id => 8],
        ])->assertSessionHas('success');

        $item = StockOpnameItem::findOrFail($items[$this->oli->id]->id);

        $this->assertSame(8, $item->counted_quantity);
        $this->assertSame(-2, $item->difference());
        $this->assertNotNull($item->counted_at);
        $this->assertSame($this->admin->id, $item->counted_by);

        // Baris yang tidak dikirim tidak tersentuh.
        $this->assertNull(StockOpnameItem::findOrFail($items[$this->rem->id]->id)->counted_quantity);
    }

    public function test_an_empty_field_clears_the_count_instead_of_writing_zero(): void
    {
        $opname = $this->openSession();
        $item = $opname->items->firstWhere('product_id', $this->oli->id);

        $this->actingAs($this->admin)->post(route('admin.opnames.count', $opname), ['counts' => [$item->id => 8]]);
        $this->actingAs($this->admin)->post(route('admin.opnames.count', $opname), ['counts' => [$item->id => null]]);

        $item->refresh();

        $this->assertNull($item->counted_quantity);
        $this->assertNull($item->counted_at);
    }

    public function test_counting_zero_is_a_finding_not_an_empty_field(): void
    {
        $opname = $this->openSession();
        $item = $opname->items->firstWhere('product_id', $this->oli->id);

        $this->actingAs($this->admin)->post(route('admin.opnames.count', $opname), ['counts' => [$item->id => 0]]);

        $item->refresh();

        $this->assertTrue($item->isCounted());
        $this->assertSame(0, $item->counted_quantity);
        $this->assertSame(-10, $item->difference());
    }

    /* --------------------------------------------------- penerapan ------- */

    public function test_approving_the_session_moves_the_stock(): void
    {
        $opname = $this->openSession();
        $this->recordCounts($opname, [$this->oli->id => 8, $this->rem->id => 6]);

        $this->actingAs($this->admin)->post(route('admin.opnames.submit', $opname))
            ->assertSessionHas('success');

        $this->assertTrue($opname->refresh()->isPosted());
        $this->assertSame(8, $this->oli->refresh()->stock);
        $this->assertSame(6, $this->rem->refresh()->stock);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->oli->id, 'type' => 'out', 'quantity' => 2, 'balance_after' => 8,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->rem->id, 'type' => 'in', 'quantity' => 2, 'balance_after' => 6,
        ]);
    }

    public function test_uncounted_rows_never_touch_the_stock(): void
    {
        $opname = $this->openSession();
        $this->recordCounts($opname, [$this->oli->id => 8]);

        $this->actingAs($this->admin)->post(route('admin.opnames.submit', $opname));

        // Kampas rem tidak dihitung, jadi saldonya dibiarkan.
        $this->assertSame(4, $this->rem->refresh()->stock);
        $this->assertDatabaseMissing('stock_movements', ['product_id' => $this->rem->id]);
    }

    public function test_a_session_without_any_variance_is_refused(): void
    {
        $opname = $this->openSession();
        $this->recordCounts($opname, [$this->oli->id => 10]);

        $this->actingAs($this->admin)->post(route('admin.opnames.submit', $opname))
            ->assertSessionHas('error');

        $this->assertTrue($opname->refresh()->isDraft());
    }

    public function test_a_session_with_nothing_counted_is_refused(): void
    {
        $opname = $this->openSession();

        $this->actingAs($this->admin)->post(route('admin.opnames.submit', $opname))
            ->assertSessionHas('error');

        $this->assertTrue($opname->refresh()->isDraft());
    }

    /**
     * Saldo bisa bergerak antara penghitungan dan persetujuan. Yang menang
     * adalah angka hasil hitung fisik, dan selisih yang dibukukan dicatat apa
     * adanya supaya jejaknya tetap jujur.
     */
    public function test_the_difference_is_recomputed_against_the_stock_at_approval(): void
    {
        $opname = $this->openSession();
        $this->recordCounts($opname, [$this->oli->id => 8]);

        // Dokumen lain memakai 3 unit setelah rak dihitung.
        $this->oli->forceFill(['stock' => 7])->save();

        $this->actingAs($this->admin)->post(route('admin.opnames.submit', $opname));

        $item = $opname->refresh()->items->firstWhere('product_id', $this->oli->id);

        $this->assertSame(8, $this->oli->refresh()->stock);
        // Baris menyebut selisih -2, tapi yang dibukukan +1 terhadap saldo saat disetujui.
        $this->assertSame(-2, $item->difference());
        $this->assertSame(1, $item->applied_difference);
        $this->assertTrue($item->wasAppliedDifferently());
    }

    public function test_a_counter_without_approval_rights_submits_for_approval(): void
    {
        $opname = $this->openSession();
        $this->recordCounts($opname, [$this->oli->id => 8]);

        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff-gudang')->value('id')]);

        $this->actingAs($staff)->post(route('admin.opnames.submit', $opname))
            ->assertSessionHas('success');

        $this->assertTrue($opname->refresh()->isPending());
        $this->assertSame(10, $this->oli->refresh()->stock);
    }

    public function test_a_locked_session_can_no_longer_be_counted(): void
    {
        $opname = $this->openSession();
        $this->recordCounts($opname, [$this->oli->id => 8]);
        $this->actingAs($this->admin)->post(route('admin.opnames.submit', $opname));

        $item = $opname->refresh()->items->firstWhere('product_id', $this->rem->id);

        $this->actingAs($this->admin)->post(route('admin.opnames.count', $opname), ['counts' => [$item->id => 99]])
            ->assertSessionHas('error');

        $this->assertNull($item->refresh()->counted_quantity);
    }

    /* --------------------------------------------------- halaman --------- */

    public function test_the_pages_render(): void
    {
        $opname = $this->openSession();

        $this->actingAs($this->admin)->get(route('admin.opnames.index'))->assertOk()->assertSee($opname->code);
        $this->actingAs($this->admin)->get(route('admin.opnames.create'))->assertOk()->assertSee('Cakupan');

        $this->actingAs($this->admin)->get(route('admin.opnames.show', $opname))
            ->assertOk()
            ->assertSee('Progres Hitung')
            ->assertSee('FLT-OLI-STD')
            ->assertSee('Scan barcode atau ketik SKU untuk melompat', false);
    }

    public function test_the_counting_list_can_be_narrowed_to_uncounted_rows(): void
    {
        $opname = $this->openSession();
        $this->recordCounts($opname, [$this->oli->id => 8]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.opnames.show', ['opname' => $opname->id, 'filter' => 'uncounted']))
            ->assertOk();

        $this->assertSame(1, $response->viewData('items')->total());
        $response->assertSee('KMP-REM-DPN')->assertDontSee('FLT-OLI-STD');
    }

    public function test_a_pending_session_reaches_the_approval_inbox(): void
    {
        $opname = $this->openSession();
        $this->recordCounts($opname, [$this->oli->id => 8]);

        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff-gudang')->value('id')]);
        $this->actingAs($staff)->post(route('admin.opnames.submit', $opname));

        $this->actingAs($this->admin)->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertSee('Stok Opname')
            ->assertSee($opname->code);

        $this->actingAs($this->admin)
            ->post(route('admin.approvals.approve', ['opname', $opname->id]))
            ->assertSessionHas('success');

        $this->assertTrue($opname->refresh()->isPosted());
        $this->assertSame(8, $this->oli->refresh()->stock);
    }

    public function test_the_menu_groups_opname_with_adjustments(): void
    {
        $this->actingAs($this->admin)->get(route('admin.adjustments.index'))
            ->assertOk()
            ->assertSee('Stok Opname')
            ->assertSee(route('admin.opnames.index'));
    }

    /* --------------------------------------------------- banyak petugas -- */

    /**
     * Dua petugas menghitung rak yang berbeda dalam satu sesi. Keduanya harus
     * tersimpan, lengkap dengan siapa menghitung apa.
     */
    public function test_two_accounts_can_count_the_same_session(): void
    {
        $opname = $this->openSession();
        $items = $opname->items->keyBy('product_id');

        $budi = User::factory()->create([
            'name' => 'Budi', 'role_id' => Role::where('slug', 'staff-gudang')->value('id'),
        ]);
        $sari = User::factory()->create([
            'name' => 'Sari', 'role_id' => Role::where('slug', 'staff-gudang')->value('id'),
        ]);

        $this->actingAs($budi)->post(route('admin.opnames.count', $opname), [
            'counts' => [$items[$this->oli->id]->id => 8],
            'baseline' => [$items[$this->oli->id]->id => null],
        ])->assertSessionHas('success');

        $this->actingAs($sari)->post(route('admin.opnames.count', $opname), [
            'counts' => [$items[$this->rem->id]->id => 5],
            'baseline' => [$items[$this->rem->id]->id => null],
        ])->assertSessionHas('success');

        $opname->load('items.counter');

        $this->assertSame(8, $opname->items->firstWhere('product_id', $this->oli->id)->counted_quantity);
        $this->assertSame(5, $opname->items->firstWhere('product_id', $this->rem->id)->counted_quantity);
        $this->assertSame($budi->id, $opname->items->firstWhere('product_id', $this->oli->id)->counted_by);
        $this->assertSame($sari->id, $opname->items->firstWhere('product_id', $this->rem->id)->counted_by);

        $contributors = $opname->contributors();

        $this->assertCount(2, $contributors);
        $this->assertEqualsCanonicalizing(['Budi', 'Sari'], $contributors->pluck('name')->all());
    }

    /**
     * Halaman yang sudah usang tidak boleh menimpa hitungan rekan lain: baris
     * yang berubah di database dilewati dan dilaporkan.
     */
    public function test_a_stale_page_can_not_overwrite_someone_elses_count(): void
    {
        $opname = $this->openSession();
        $item = $opname->items->firstWhere('product_id', $this->oli->id);

        $budi = User::factory()->create(['role_id' => Role::where('slug', 'staff-gudang')->value('id')]);

        // Budi menghitung lebih dulu.
        $this->actingAs($budi)->post(route('admin.opnames.count', $opname), [
            'counts' => [$item->id => 8],
            'baseline' => [$item->id => null],
        ]);

        // Sari mengirim dari halaman yang dimuat sebelum Budi menyimpan.
        $this->actingAs($this->admin)->post(route('admin.opnames.count', $opname), [
            'counts' => [$item->id => null],
            'baseline' => [$item->id => null],
        ])->assertSessionHas('error');

        $item->refresh();

        $this->assertSame(8, $item->counted_quantity);
        $this->assertSame($budi->id, $item->counted_by);
    }

    public function test_recounting_a_row_on_purpose_still_works(): void
    {
        $opname = $this->openSession();
        $item = $opname->items->firstWhere('product_id', $this->oli->id);

        $this->actingAs($this->admin)->post(route('admin.opnames.count', $opname), [
            'counts' => [$item->id => 8], 'baseline' => [$item->id => null],
        ]);

        // Halaman dimuat ulang, jadi nilai awalnya ikut terbarui.
        $this->actingAs($this->admin)->post(route('admin.opnames.count', $opname), [
            'counts' => [$item->id => 9], 'baseline' => [$item->id => 8],
        ])->assertSessionHas('success');

        $this->assertSame(9, $item->refresh()->counted_quantity);
    }

    /* --------------------------------------------------- akurasi --------- */

    public function test_accuracy_is_measured_per_sku_and_per_unit(): void
    {
        $opname = $this->openSession();
        // Oli tercatat 10 ditemukan 8 (meleset 2), kampas rem tercatat 4 tepat.
        $this->recordCounts($opname, [$this->oli->id => 8, $this->rem->id => 4]);

        $opname->load('items');

        // 1 dari 2 SKU sesuai catatan.
        $this->assertSame(50, $opname->accuracyPercentage());
        // Tercatat 14 unit, meleset 2 unit.
        $this->assertSame(14, $opname->countedSystemUnits());
        $this->assertSame(12, $opname->countedUnits());
        $this->assertSame(2, $opname->absoluteVariance());
        $this->assertSame(86, $opname->unitAccuracyPercentage());
    }

    public function test_uncounted_rows_do_not_dilute_the_accuracy(): void
    {
        $opname = $this->openSession();
        $this->recordCounts($opname, [$this->oli->id => 10]);

        $opname->load('items');

        // Hanya satu baris dihitung dan hasilnya tepat.
        $this->assertSame(100, $opname->accuracyPercentage());
        $this->assertSame(100, $opname->unitAccuracyPercentage());
    }

    public function test_the_detail_page_reports_the_accuracy_and_the_counters(): void
    {
        $opname = $this->openSession();
        $items = $opname->items->keyBy('product_id');

        $budi = User::factory()->create([
            'name' => 'Budi', 'role_id' => Role::where('slug', 'staff-gudang')->value('id'),
        ]);

        $this->actingAs($budi)->post(route('admin.opnames.count', $opname), [
            'counts' => [$items[$this->oli->id]->id => 8],
            'baseline' => [$items[$this->oli->id]->id => null],
        ]);

        $this->actingAs($this->admin)->get(route('admin.opnames.show', $opname))
            ->assertOk()
            ->assertSee('Akurasi Catatan')
            ->assertSee('Akurasi per unit')
            ->assertSee('Petugas yang Menghitung')
            ->assertSee('Budi')
            ->assertSee('1 SKU dihitung');
    }

    /* --------------------------------------------------- helpers --------- */

    protected function makeProduct(string $sku, string $name, int $stock, string $category): Product
    {
        $product = Product::create([
            'sku' => $sku, 'name' => $name, 'unit' => 'pcs', 'min_stock' => 0, 'category' => $category,
        ]);

        $product->forceFill(['stock' => $stock])->save();

        return $product;
    }

    protected function openSession(): StockOpname
    {
        $this->actingAs($this->admin)->post(route('admin.opnames.store'), [
            'date' => now()->toDateString(),
            'scope' => StockOpname::SCOPE_ALL,
        ]);

        return StockOpname::with('items')->latest('id')->firstOrFail();
    }

    /**
     * @param  array<int, int>  $quantities  product_id => hasil hitung
     */
    protected function recordCounts(StockOpname $opname, array $quantities): void
    {
        $items = $opname->items->keyBy('product_id');

        $counts = [];

        foreach ($quantities as $productId => $quantity) {
            $counts[$items[$productId]->id] = $quantity;
        }

        $this->actingAs($this->admin)->post(route('admin.opnames.count', $opname), ['counts' => $counts]);

        $opname->load('items');
    }
}
