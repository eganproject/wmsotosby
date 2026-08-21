<?php

namespace Tests\Feature\Admin;

use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockOpname;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stasiun hitung: menghitung dengan memanggil barangnya, bukan mencarinya di
 * daftar. Yang diuji di sini tiga hal yang membuat alurnya bisa dipercaya —
 * kodenya menemukan barang yang benar, saldo tercatat tidak pernah ikut
 * terkirim, dan pekerjaan rekan sesama petugas tidak tertimpa.
 */
class StockOpnameStationTest extends TestCase
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

        $this->oli = $this->makeProduct('FLT-OLI-STD', 'Filter Oli Standar', stock: 10, category: 'Filter', attributes: [
            'barcode' => '8991234567890',
            'location' => 'A-01',
        ]);

        $this->rem = $this->makeProduct('KMP-REM-DPN', 'Kampas Rem Depan', stock: 4, category: 'Rem', attributes: [
            'location' => 'B-02',
        ]);
    }

    /* ------------------------------------------------------ memanggil ---- */

    public function test_a_sku_calls_up_its_item_without_revealing_the_recorded_stock(): void
    {
        $opname = $this->openSession();

        $response = $this->actingAs($this->admin)
            ->postJson(route('admin.opnames.station.lookup', $opname), ['code' => 'FLT-OLI-STD'])
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('item.sku', 'FLT-OLI-STD')
            ->assertJsonPath('item.location', 'A-01')
            ->assertJsonPath('item.counted', null);

        // Inti hitung buta: angka sistem tidak pernah meninggalkan server.
        $this->assertArrayNotHasKey('system_quantity', $response->json('item'));
        $this->assertStringNotContainsString('system_quantity', $response->getContent());
    }

    public function test_a_barcode_finds_the_item_too(): void
    {
        $opname = $this->openSession();

        $this->actingAs($this->admin)
            ->postJson(route('admin.opnames.station.lookup', $opname), ['code' => '8991234567890'])
            ->assertOk()
            ->assertJsonPath('item.sku', 'FLT-OLI-STD');
    }

    public function test_an_unknown_code_is_refused_with_the_code_it_was_given(): void
    {
        $opname = $this->openSession();

        $this->actingAs($this->admin)
            ->postJson(route('admin.opnames.station.lookup', $opname), ['code' => 'TIDAK-ADA'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    /* --------------------------------------------------- ketik cepat ----- */

    public function test_the_quantity_can_be_typed_along_with_the_sku(): void
    {
        $opname = $this->openSession();

        $this->actingAs($this->admin)
            ->postJson(route('admin.opnames.station.lookup', $opname), ['code' => 'FLT-OLI-STD 8 r2'])
            ->assertOk()
            ->assertJsonPath('item.sku', 'FLT-OLI-STD')
            ->assertJsonPath('quick.counted', 8)
            ->assertJsonPath('quick.damaged', 2);
    }

    /**
     * SKU boleh mengandung spasi dan berakhir dengan angka. Kode utuh harus
     * menang lebih dulu, kalau tidak "FLT OLI 001" terbaca sebagai kode "FLT
     * OLI" berjumlah 001.
     */
    public function test_a_sku_that_ends_in_digits_is_not_mistaken_for_a_quantity(): void
    {
        $this->makeProduct('FLT OLI 001', 'Filter Oli Nomor Satu', stock: 3, category: 'Filter');

        $opname = $this->openSession();

        $this->actingAs($this->admin)
            ->postJson(route('admin.opnames.station.lookup', $opname), ['code' => 'FLT OLI 001'])
            ->assertOk()
            ->assertJsonPath('item.sku', 'FLT OLI 001')
            ->assertJsonPath('quick.counted', null);
    }

    /* ------------------------------------------------------ menyimpan ---- */

    public function test_counting_saves_the_row_and_reports_the_progress(): void
    {
        $opname = $this->openSession();

        $this->actingAs($this->admin)
            ->postJson(route('admin.opnames.station.count', $opname), [
                'product_id' => $this->oli->id,
                'counted' => 8,
                'damaged' => 1,
                'baseline' => null,
            ])
            ->assertOk()
            ->assertJsonPath('saved', true)
            ->assertJsonPath('progress.counted', 1)
            ->assertJsonPath('progress.mine', 1)
            ->assertJsonPath('progress.total', 2);

        $item = $opname->items()->where('product_id', $this->oli->id)->firstOrFail();

        $this->assertSame(8, $item->counted_quantity);
        $this->assertSame(1, $item->damaged_quantity);
        $this->assertSame($this->admin->id, $item->counted_by);
    }

    /**
     * Scan ulang mengganti hitungannya. Tidak boleh ada dua baris untuk satu
     * SKU, dan angkanya tidak boleh menumpuk.
     */
    public function test_scanning_the_same_sku_again_replaces_the_number(): void
    {
        $opname = $this->openSession();

        $this->actingAs($this->admin)->postJson(route('admin.opnames.station.count', $opname), [
            'product_id' => $this->oli->id, 'counted' => 8, 'baseline' => null,
        ])->assertOk();

        $this->actingAs($this->admin)->postJson(route('admin.opnames.station.count', $opname), [
            'product_id' => $this->oli->id, 'counted' => 9, 'baseline' => 8,
        ])->assertOk();

        $this->assertSame(1, $opname->items()->where('product_id', $this->oli->id)->count());
        $this->assertSame(9, $opname->items()->where('product_id', $this->oli->id)->value('counted_quantity'));
    }

    public function test_an_empty_count_clears_the_row_instead_of_writing_zero(): void
    {
        $opname = $this->openSession();

        $this->actingAs($this->admin)->postJson(route('admin.opnames.station.count', $opname), [
            'product_id' => $this->oli->id, 'counted' => 8, 'damaged' => 3, 'baseline' => null,
        ])->assertOk();

        $this->actingAs($this->admin)->postJson(route('admin.opnames.station.count', $opname), [
            'product_id' => $this->oli->id, 'counted' => null, 'baseline' => 8,
        ])->assertOk();

        $item = $opname->items()->where('product_id', $this->oli->id)->firstOrFail();

        $this->assertNull($item->counted_quantity);
        // Temuan rusak berasal dari pemeriksaan rak yang sama, jadi ikut batal.
        $this->assertSame(0, $item->damaged_quantity);
        $this->assertNull($item->counted_by);
    }

    /* --------------------------------------------------- banyak petugas -- */

    public function test_a_row_counted_by_a_colleague_is_reported_instead_of_overwritten(): void
    {
        $opname = $this->openSession();
        $budi = $this->makeStaff('Budi');

        $this->actingAs($budi)->postJson(route('admin.opnames.station.count', $opname), [
            'product_id' => $this->oli->id, 'counted' => 8, 'baseline' => null,
        ])->assertOk();

        // Sari membuka kartunya sebelum Budi menyimpan, jadi nilai awalnya usang.
        $this->actingAs($this->makeStaff('Sari'))
            ->postJson(route('admin.opnames.station.count', $opname), [
                'product_id' => $this->oli->id, 'counted' => 5, 'baseline' => null,
            ])
            ->assertStatus(409)
            ->assertJsonPath('conflict', true)
            ->assertJsonPath('item.counted', 8)
            ->assertJsonPath('item.counted_by', 'Budi');

        $item = $opname->items()->where('product_id', $this->oli->id)->firstOrFail();

        $this->assertSame(8, $item->counted_quantity);
        $this->assertSame($budi->id, $item->counted_by);
    }

    public function test_the_colleagues_count_can_be_overwritten_on_purpose(): void
    {
        $opname = $this->openSession();
        $budi = $this->makeStaff('Budi');
        $sari = $this->makeStaff('Sari');

        $this->actingAs($budi)->postJson(route('admin.opnames.station.count', $opname), [
            'product_id' => $this->oli->id, 'counted' => 8, 'baseline' => null,
        ])->assertOk();

        $this->actingAs($sari)->postJson(route('admin.opnames.station.count', $opname), [
            'product_id' => $this->oli->id, 'counted' => 5, 'baseline' => null, 'force' => true,
        ])->assertOk();

        $item = $opname->items()->where('product_id', $this->oli->id)->firstOrFail();

        $this->assertSame(5, $item->counted_quantity);
        $this->assertSame($sari->id, $item->counted_by);
    }

    public function test_the_progress_separates_my_rows_from_the_teams(): void
    {
        $opname = $this->openSession();

        $this->actingAs($this->makeStaff('Budi'))->postJson(route('admin.opnames.station.count', $opname), [
            'product_id' => $this->oli->id, 'counted' => 8, 'baseline' => null,
        ])->assertOk();

        $this->actingAs($this->makeStaff('Sari'))
            ->postJson(route('admin.opnames.station.count', $opname), [
                'product_id' => $this->rem->id, 'counted' => 4, 'baseline' => null,
            ])
            ->assertOk()
            ->assertJsonPath('progress.counted', 2)
            ->assertJsonPath('progress.mine', 1)
            ->assertJsonPath('progress.remaining', 0)
            ->assertJsonPath('progress.counters.0.counted', 1);
    }

    /**
     * Layar yang sedang terbuka menyegarkan dirinya sendiri, jadi angka tim
     * tidak membeku sejak halaman dimuat.
     */
    public function test_the_progress_endpoint_reports_the_team_as_it_works(): void
    {
        $opname = $this->openSession();

        $this->actingAs($this->makeStaff('Budi'))->postJson(route('admin.opnames.station.count', $opname), [
            'product_id' => $this->oli->id, 'counted' => 8, 'baseline' => null,
        ])->assertOk();

        $this->actingAs($this->makeStaff('Sari'))
            ->getJson(route('admin.opnames.station.progress', $opname))
            ->assertOk()
            ->assertJsonPath('progress.counted', 1)
            ->assertJsonPath('progress.mine', 0)
            ->assertJsonPath('progress.counters.0.name', 'Budi')
            ->assertJsonPath('item', null);
    }

    /**
     * Barang yang sedang dipegang ikut ditanyakan, supaya "baru saja dihitung
     * rekan" ketahuan selagi petugas masih berdiri di depan raknya.
     */
    public function test_the_progress_endpoint_reports_the_item_in_hand(): void
    {
        $opname = $this->openSession();

        $this->actingAs($this->makeStaff('Budi'))->postJson(route('admin.opnames.station.count', $opname), [
            'product_id' => $this->oli->id, 'counted' => 8, 'baseline' => null,
        ])->assertOk();

        $response = $this->actingAs($this->makeStaff('Sari'))
            ->getJson(route('admin.opnames.station.progress', ['opname' => $opname->id, 'product_id' => $this->oli->id]))
            ->assertOk()
            ->assertJsonPath('item.counted', 8)
            ->assertJsonPath('item.counted_by', 'Budi')
            ->assertJsonPath('item.counted_by_me', false);

        // Hitung buta berlaku juga di jalur penyegaran.
        $this->assertStringNotContainsString('system_quantity', $response->getContent());
    }

    public function test_watching_the_progress_only_needs_view_rights(): void
    {
        $opname = $this->openSession();

        $this->actingAs($this->makeWatcher())
            ->getJson(route('admin.opnames.station.progress', $opname))
            ->assertOk();

        // Memantau bukan mengisi: menghitung tetap butuh izinnya sendiri.
        $this->actingAs($this->makeWatcher())
            ->postJson(route('admin.opnames.station.count', $opname), [
                'product_id' => $this->oli->id, 'counted' => 8, 'baseline' => null,
            ])
            ->assertForbidden();
    }

    /* ------------------------------------------------- di luar cakupan --- */

    public function test_a_product_outside_the_scope_is_offered_before_it_is_added(): void
    {
        $opname = $this->openScopedSession('Filter');

        $this->actingAs($this->admin)
            ->postJson(route('admin.opnames.station.lookup', $opname), ['code' => 'KMP-REM-DPN'])
            ->assertOk()
            ->assertJsonPath('status', 'out_of_scope')
            ->assertJsonPath('item.item_id', null);

        // Tanpa persetujuan tegas, barisnya tidak pernah ikut terbentuk.
        $this->actingAs($this->admin)
            ->postJson(route('admin.opnames.station.count', $opname), [
                'product_id' => $this->rem->id, 'counted' => 3, 'baseline' => null,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertSame(1, $opname->items()->count());
    }

    public function test_an_adopted_row_compares_against_the_stock_at_that_moment(): void
    {
        $opname = $this->openScopedSession('Filter');

        $this->actingAs($this->admin)
            ->postJson(route('admin.opnames.station.count', $opname), [
                'product_id' => $this->rem->id, 'counted' => 3, 'baseline' => null, 'adopt' => true,
            ])
            ->assertOk()
            ->assertJsonPath('progress.total', 2);

        $item = $opname->items()->where('product_id', $this->rem->id)->firstOrFail();

        $this->assertSame(4, $item->system_quantity);
        $this->assertSame(3, $item->counted_quantity);
        $this->assertSame(-1, $item->difference());
    }

    public function test_a_bundle_is_never_counted_on_the_shelf(): void
    {
        $paket = $this->makeProduct('PKT-SERVIS', 'Paket Servis', stock: 0, category: 'Paket', attributes: [
            'type' => Product::TYPE_BUNDLE,
        ]);

        $opname = $this->openSession();

        $this->actingAs($this->admin)
            ->postJson(route('admin.opnames.station.lookup', $opname), ['code' => 'PKT-SERVIS'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertSame(0, $opname->items()->where('product_id', $paket->id)->count());
    }

    /* ------------------------------------------------------- penjagaan --- */

    public function test_a_locked_session_can_no_longer_be_counted(): void
    {
        $opname = $this->openSession();

        $this->actingAs($this->admin)->postJson(route('admin.opnames.station.count', $opname), [
            'product_id' => $this->oli->id, 'counted' => 8, 'baseline' => null,
        ])->assertOk();

        $this->actingAs($this->admin)->post(route('admin.opnames.submit', $opname));

        $this->assertTrue($opname->refresh()->isPosted());

        $this->actingAs($this->admin)
            ->postJson(route('admin.opnames.station.lookup', $opname), ['code' => 'FLT-OLI-STD'])
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->get(route('admin.opnames.station', $opname))
            ->assertRedirect(route('admin.opnames.show', $opname));
    }

    public function test_the_station_page_renders_for_a_counter(): void
    {
        $opname = $this->openSession();

        $this->actingAs($this->makeStaff('Budi'))
            ->get(route('admin.opnames.station', $opname))
            ->assertOk()
            ->assertSee('Stasiun Hitung')
            ->assertSee($opname->code)
            // Saldo tercatat tidak pernah ikut dirender ke halamannya.
            ->assertDontSee('Tercatat 10');
    }

    /* ------------------------------------------------------ hitung buta -- */

    /**
     * Petugas yang menghitung tidak melihat saldo tercatat maupun selisihnya —
     * termasuk lewat saringan "berselisih", yang menunjukkan angka sistem
     * tanpa pernah menampilkannya.
     */
    public function test_the_counting_list_hides_the_recorded_stock_from_the_counter(): void
    {
        $opname = $this->openSession();

        $this->actingAs($this->makeStaff('Budi'))
            ->get(route('admin.opnames.show', $opname))
            ->assertOk()
            ->assertDontSee('Tercatat 10')
            ->assertDontSee('Berselisih')
            ->assertDontSee('Akurasi Catatan')
            ->assertSee('Rak A-01')
            ->assertSee('Belum');
    }

    public function test_the_variance_filter_is_ignored_for_a_counter(): void
    {
        $opname = $this->openSession();

        // Oli meleset, kampas rem tepat.
        $this->actingAs($this->admin)->postJson(route('admin.opnames.station.count', $opname), [
            'product_id' => $this->oli->id, 'counted' => 8, 'baseline' => null,
        ])->assertOk();
        $this->actingAs($this->admin)->postJson(route('admin.opnames.station.count', $opname), [
            'product_id' => $this->rem->id, 'counted' => 4, 'baseline' => null,
        ])->assertOk();

        // Saringan diketik sendiri di alamatnya; hasilnya tetap seluruh baris.
        $this->actingAs($this->makeStaff('Budi'))
            ->get(route('admin.opnames.show', ['opname' => $opname->id, 'filter' => 'variance']))
            ->assertOk()
            ->assertSee('FLT-OLI-STD')
            ->assertSee('KMP-REM-DPN');
    }

    public function test_an_approver_still_sees_the_variance(): void
    {
        $opname = $this->openSession();

        $this->actingAs($this->admin)->postJson(route('admin.opnames.station.count', $opname), [
            'product_id' => $this->oli->id, 'counted' => 8, 'baseline' => null,
        ])->assertOk();

        $this->actingAs($this->admin)
            ->get(route('admin.opnames.show', $opname))
            ->assertOk()
            ->assertSee('Berselisih')
            ->assertSee('Akurasi Catatan');
    }

    /* --------------------------------------------------------- helpers --- */

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeProduct(string $sku, string $name, int $stock, string $category, array $attributes = []): Product
    {
        $product = Product::create([
            'sku' => $sku, 'name' => $name, 'unit' => 'pcs', 'min_stock' => 0, 'category' => $category,
        ] + $attributes);

        $product->forceFill(['stock' => $stock])->save();

        return $product;
    }

    /** Boleh melihat sesi opname, tetapi tidak boleh mengisi hitungannya. */
    protected function makeWatcher(): User
    {
        $role = Role::firstOrCreate(
            ['slug' => 'pengawas-opname'],
            [
                'name' => 'Pengawas Opname',
                'description' => 'Hanya memantau jalannya sesi.',
                'is_super_admin' => false,
            ],
        );

        $role->permissions()->sync(
            Permission::whereIn('slug', ['dashboard.view', 'opnames.view'])->pluck('id'),
        );

        return User::factory()->create(['role_id' => $role->id]);
    }

    protected function makeStaff(string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'role_id' => Role::where('slug', 'staff-gudang')->value('id'),
        ]);
    }

    protected function openSession(): StockOpname
    {
        return $this->openScopedSession(null);
    }

    protected function openScopedSession(?string $category): StockOpname
    {
        $this->actingAs($this->admin)->post(route('admin.opnames.store'), [
            'date' => now()->toDateString(),
            'scope' => $category ? StockOpname::SCOPE_CATEGORY : StockOpname::SCOPE_ALL,
            'scope_value' => $category,
        ]);

        return StockOpname::with('items')->latest('id')->firstOrFail();
    }
}
