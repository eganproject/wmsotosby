<?php

namespace Tests\Feature\Admin;

use App\Models\Inbound;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Penyuntingan massal batas stok menipis.
 *
 * Yang boleh diubah sekaligus hanya batasnya, bukan stoknya. Batas adalah
 * setelan kapan sebuah barang mulai disebut menipis; ia tidak pernah menambah
 * atau mengurangi saldo, dan itulah satu-satunya alasan penyuntingan massal
 * aman di sini. Tes ini menjaga batasan tersebut tetap berlaku.
 */
class ProductBulkEditTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();
    }

    /* --------------------------------------------------- perubahan ------- */

    public function test_the_threshold_of_the_selected_goods_is_changed(): void
    {
        $first = $this->makeProduct('FLT-1', minStock: 2);
        $second = $this->makeProduct('FLT-2', minStock: 3);
        $untouched = $this->makeProduct('FLT-3', minStock: 9);

        $this->actingAs($this->admin)
            ->patch(route('admin.products.bulk.min-stock'), [
                'scope' => 'selected',
                'ids' => [$first->id, $second->id],
                'min_stock' => 10,
            ])
            ->assertSessionHas('success', 'Batas stok menipis 2 barang diubah menjadi 10.');

        $this->assertSame(10, $first->refresh()->min_stock);
        $this->assertSame(10, $second->refresh()->min_stock);
        $this->assertSame(9, $untouched->refresh()->min_stock, 'Barang yang tidak dipilih tidak boleh ikut berubah.');
    }

    /**
     * Inti dari kenapa penyuntingan massal ini aman: saldo tidak tersentuh, dan
     * karenanya tidak ada mutasi stok yang terbentuk. Stok hanya boleh bergerak
     * lewat dokumen.
     */
    public function test_the_stock_itself_is_never_touched(): void
    {
        $product = $this->makeProduct('FLT-1', minStock: 2);
        $this->giveStock($product, 40);

        $movementsBefore = StockMovement::count();

        $this->actingAs($this->admin)->patch(route('admin.products.bulk.min-stock'), [
            'scope' => 'selected',
            'ids' => [$product->id],
            'min_stock' => 25,
        ])->assertSessionHas('success');

        $product->refresh();

        $this->assertSame(25, $product->min_stock);
        $this->assertSame(40, $product->stock, 'Saldo tidak boleh ikut berubah.');
        $this->assertSame($movementsBefore, StockMovement::count(), 'Tidak boleh ada mutasi stok baru.');
    }

    /**
     * Barang yang nilainya sudah sama sengaja tidak disentuh, supaya updated_at
     * tidak bergeser tanpa ada yang benar-benar berubah.
     */
    public function test_goods_that_already_match_are_left_alone(): void
    {
        $already = $this->makeProduct('FLT-1', minStock: 5);
        $changing = $this->makeProduct('FLT-2', minStock: 1);

        $stamp = $already->updated_at;

        $this->travel(1)->minute();

        $this->actingAs($this->admin)
            ->patch(route('admin.products.bulk.min-stock'), [
                'scope' => 'selected',
                'ids' => [$already->id, $changing->id],
                'min_stock' => 5,
            ])
            ->assertSessionHas('success', 'Batas stok menipis 1 barang diubah menjadi 5. 1 barang lainnya sudah bernilai sama.');

        $this->assertTrue($stamp->equalTo($already->refresh()->updated_at));

        $this->travelBack();
    }

    public function test_it_says_so_when_nothing_needed_changing(): void
    {
        $product = $this->makeProduct('FLT-1', minStock: 5);

        $this->actingAs($this->admin)
            ->patch(route('admin.products.bulk.min-stock'), [
                'scope' => 'selected',
                'ids' => [$product->id],
                'min_stock' => 5,
            ])
            ->assertSessionHas('success', 'Batas stok menipis 1 barang memang sudah 5. Tidak ada yang diubah.');
    }

    public function test_a_threshold_of_zero_is_allowed(): void
    {
        $product = $this->makeProduct('FLT-1', minStock: 5);

        $this->actingAs($this->admin)->patch(route('admin.products.bulk.min-stock'), [
            'scope' => 'selected',
            'ids' => [$product->id],
            'min_stock' => 0,
        ])->assertSessionHas('success');

        $this->assertSame(0, $product->refresh()->min_stock);
    }

    /* --------------------------------------------------- cakupan --------- */

    /**
     * Halaman memuat sepuluh baris sedangkan saringan bisa mengenai ratusan,
     * jadi harus ada jalan memilih seluruh hasil saringan — dan sasarannya
     * ditentukan ulang di server memakai saringan yang sama, bukan dipercaya
     * dari daftar id yang dikirim layar.
     */
    public function test_the_whole_filtered_set_can_be_changed_at_once(): void
    {
        foreach (range(1, 15) as $number) {
            $this->makeProduct('OLI-'.$number, minStock: 1, category: 'Oli');
        }

        $other = $this->makeProduct('FLT-1', minStock: 1, category: 'Filter');

        $this->actingAs($this->admin)
            ->patch(route('admin.products.bulk.min-stock'), [
                'scope' => 'filtered',
                'category' => 'Oli',
                // Layar hanya sempat mengirim id halaman pertama.
                'ids' => [Product::first()->id],
                'min_stock' => 8,
            ])
            ->assertSessionHas('success', 'Batas stok menipis 15 barang diubah menjadi 8.');

        $this->assertSame(15, Product::where('min_stock', 8)->count());
        $this->assertSame(1, $other->refresh()->min_stock, 'Kategori lain tidak boleh ikut terkena.');
    }

    public function test_the_low_stock_filter_selects_only_thin_goods(): void
    {
        $thin = $this->makeProduct('FLT-1', minStock: 5);
        $this->giveStock($thin, 3);

        $healthy = $this->makeProduct('FLT-2', minStock: 5);
        $this->giveStock($healthy, 50);

        $this->actingAs($this->admin)
            ->patch(route('admin.products.bulk.min-stock'), [
                'scope' => 'filtered',
                'stock' => 'low',
                'min_stock' => 12,
            ])
            ->assertSessionHas('success', 'Batas stok menipis 1 barang diubah menjadi 12.');

        $this->assertSame(12, $thin->refresh()->min_stock);
        $this->assertSame(5, $healthy->refresh()->min_stock);
    }

    /** Saringan yang sedang dipakai bertahan setelah penyuntingan. */
    public function test_the_active_filter_survives_the_edit(): void
    {
        $this->makeProduct('FLT-1', minStock: 1, category: 'Filter');

        $this->actingAs($this->admin)
            ->patch(route('admin.products.bulk.min-stock'), [
                'scope' => 'filtered',
                'category' => 'Filter',
                'search' => 'FLT',
                'min_stock' => 4,
            ])
            ->assertRedirect(route('admin.products.index', ['search' => 'FLT', 'category' => 'Filter']));
    }

    public function test_an_empty_result_changes_nothing(): void
    {
        $product = $this->makeProduct('FLT-1', minStock: 1);

        $this->actingAs($this->admin)
            ->patch(route('admin.products.bulk.min-stock'), [
                'scope' => 'filtered',
                'category' => 'Kategori Yang Tidak Ada',
                'min_stock' => 9,
            ])
            ->assertSessionHas('error');

        $this->assertSame(1, $product->refresh()->min_stock);
    }

    /* --------------------------------------------------- penjagaan ------- */

    public function test_the_threshold_is_required_and_must_be_a_whole_number(): void
    {
        $product = $this->makeProduct('FLT-1', minStock: 1);

        foreach ([null, -1, 'banyak', 1.5] as $value) {
            $this->actingAs($this->admin)
                ->patch(route('admin.products.bulk.min-stock'), [
                    'scope' => 'selected',
                    'ids' => [$product->id],
                    'min_stock' => $value,
                ])
                ->assertSessionHasErrors('min_stock');
        }

        $this->assertSame(1, $product->refresh()->min_stock);
    }

    public function test_selecting_nothing_is_refused(): void
    {
        $this->makeProduct('FLT-1', minStock: 1);

        $this->actingAs($this->admin)
            ->patch(route('admin.products.bulk.min-stock'), ['scope' => 'selected', 'min_stock' => 5])
            ->assertSessionHasErrors('ids');

        $this->assertSame(0, Product::where('min_stock', 5)->count());
    }

    public function test_it_needs_the_update_permission(): void
    {
        $product = $this->makeProduct('FLT-1', minStock: 1);

        $role = Role::create(['name' => 'Pengamat Barang', 'slug' => 'pengamat-barang']);
        $role->permissions()->sync(Permission::whereIn('slug', ['dashboard.view', 'products.view'])->pluck('id'));

        $viewer = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($viewer)->get(route('admin.products.index'))->assertOk();

        $this->actingAs($viewer)
            ->patch(route('admin.products.bulk.min-stock'), [
                'scope' => 'selected',
                'ids' => [$product->id],
                'min_stock' => 9,
            ])
            ->assertForbidden();

        $this->assertSame(1, $product->refresh()->min_stock);
    }

    /* --------------------------------------------------- halaman --------- */

    public function test_the_page_offers_the_checkboxes(): void
    {
        $product = $this->makeProduct('FLT-1', minStock: 1);

        $this->actingAs($this->admin)->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee('productBulkEdit(', false)
            ->assertSee('value="'.$product->id.'"', false)
            ->assertSee(route('admin.products.bulk.min-stock'))
            ->assertSee('Batas menipis')
            // Batasan yang paling penting disebut di layar, bukan hanya di kode.
            ->assertSee('stok tidak tersentuh');
    }

    /**
     * Barang terpilih dikirim dari daftar pilihan, bukan dari kotak centang.
     *
     * Sebagian pilihan bisa berada di halaman yang sedang tidak terlihat, dan
     * kotak centangnya pun tidak ada di dokumen — kalau yang dikirim kotak
     * centang, pilihan dari halaman lain hilang diam-diam saat disimpan.
     */
    public function test_the_selection_is_submitted_from_the_remembered_list(): void
    {
        $this->makeProduct('FLT-1', minStock: 1);

        $html = $this->actingAs($this->admin)->get(route('admin.products.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<template x-for="id in selected"', $html);
        $this->assertStringContainsString('name="ids[]" :value="id"', $html);
        $this->assertStringNotContainsString('type="checkbox" name="ids[]"', $html);
    }

    /**
     * Pilihan hanya berlaku untuk saringan yang sama persis, dan penandanya
     * tidak boleh ikut berubah hanya karena berpindah halaman.
     */
    public function test_the_selection_key_ignores_the_page_number(): void
    {
        foreach (range(1, 12) as $number) {
            $this->makeProduct('FLT-'.$number, minStock: 1, category: 'Filter');
        }

        $key = fn (array $query) => $this->keyFrom(
            $this->actingAs($this->admin)->get(route('admin.products.index', $query))->getContent(),
        );

        $first = $key(['category' => 'Filter']);
        $second = $key(['category' => 'Filter', 'page' => 2]);
        $other = $key(['category' => 'Filter', 'stock' => 'low']);

        $this->assertNotSame('', $first);
        $this->assertSame($first, $second, 'Berpindah halaman bukan berganti saringan.');
        $this->assertNotSame($first, $other, 'Saringan yang berbeda harus melupakan pilihan sebelumnya.');
    }

    /** Yang tidak boleh mengubah tidak diberi kotak centang sama sekali. */
    public function test_a_viewer_gets_no_checkboxes(): void
    {
        $this->makeProduct('FLT-1', minStock: 1);

        $role = Role::create(['name' => 'Pengamat Barang', 'slug' => 'pengamat-barang']);
        $role->permissions()->sync(Permission::whereIn('slug', ['dashboard.view', 'products.view'])->pluck('id'));

        $viewer = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($viewer)->get(route('admin.products.index'))
            ->assertOk()
            ->assertDontSee('type="checkbox"', false);
    }

    protected function keyFrom(string $html): string
    {
        preg_match("/key: '([^']*)'/", $html, $matches);

        return $matches[1] ?? '';
    }

    /* --------------------------------------------------- helpers --------- */

    protected function makeProduct(string $sku, int $minStock, ?string $category = null): Product
    {
        return Product::create([
            'sku' => $sku,
            'name' => 'Barang '.$sku,
            'unit' => 'pcs',
            'category' => $category,
            'min_stock' => $minStock,
        ]);
    }

    protected function giveStock(Product $product, int $quantity): void
    {
        $inbound = Inbound::create([
            'code' => Inbound::nextCode(), 'date' => now(),
            'status' => Inbound::STATUS_DRAFT,
        ]);
        $inbound->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));
    }
}
