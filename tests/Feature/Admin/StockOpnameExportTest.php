<?php

namespace Tests\Feature\Admin;

use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockOpname;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Export hasil stok opname: satu berkas, tiga lembar — ringkasan untuk yang
 * membaca sekilas, detail untuk arsip, dan selisih untuk yang menindaklanjuti.
 */
class StockOpnameExportTest extends TestCase
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
            'sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli Standar', 'unit' => 'pcs',
            'min_stock' => 0, 'category' => 'Filter', 'location' => 'A-01',
        ]);
        $this->oli->forceFill(['stock' => 10])->save();

        $this->rem = Product::create([
            'sku' => 'KMP-REM-DPN', 'name' => 'Kampas Rem Depan', 'unit' => 'set',
            'min_stock' => 0, 'category' => 'Rem', 'location' => 'B-02',
        ]);
        $this->rem->forceFill(['stock' => 4])->save();
    }

    /* ------------------------------------------------------ isi berkas --- */

    public function test_the_workbook_carries_a_report_tab_and_a_detail_tab(): void
    {
        $opname = $this->finishedSession();

        $book = $this->readExport($opname);

        $this->assertSame(
            ['Ringkasan', 'Detail SKU', 'Selisih'],
            $book->getSheetNames(),
        );
    }

    public function test_the_detail_tab_reports_the_count_the_system_and_the_difference(): void
    {
        $opname = $this->finishedSession();

        $rows = $this->rowsOf($this->readExport($opname), 'Detail SKU');

        $oli = $this->rowFor($rows, 'FLT-OLI-STD');

        $this->assertSame('Filter Oli Standar', $oli[1]);
        $this->assertSame('Filter', $oli[2]);
        $this->assertSame('A-01', $oli[3]);
        $this->assertSame('pcs', $oli[4]);
        // Sistem 10, dihitung 8, jadi kurang 2.
        $this->assertEquals(10, $oli[5]);
        $this->assertEquals(8, $oli[6]);
        $this->assertEquals(-2, $oli[7]);
        $this->assertEquals(1, $oli[8]);
        $this->assertSame('Kurang', $oli[10]);
        $this->assertSame('Super Admin', $oli[11]);

        // Judul kolomnya ikut diuji: berkas ini dibaca orang, bukan mesin.
        $header = $rows[3];

        $this->assertSame('SKU', $header[0]);
        $this->assertSame('Stok Sistem', $header[5]);
        $this->assertSame('Hasil Hitung', $header[6]);
        $this->assertSame('Selisih', $header[7]);
    }

    /**
     * Nol adalah hasil hitung yang sah dan "belum dihitung" bukan nol. Kolom
     * angkanya dibiarkan kosong supaya penjumlahan ulang di spreadsheet tidak
     * ikut menghitung rak yang tidak pernah diperiksa.
     */
    public function test_an_uncounted_row_leaves_its_numbers_empty(): void
    {
        $opname = $this->finishedSession(countTheBrakePads: false);

        $rows = $this->rowsOf($this->readExport($opname), 'Detail SKU');
        $rem = $this->rowFor($rows, 'KMP-REM-DPN');

        $this->assertEquals(4, $rem[5]);
        $this->assertNull($rem[6]);
        $this->assertNull($rem[7]);
        $this->assertSame('Belum dihitung', $rem[10]);
    }

    public function test_the_variance_tab_only_lists_what_missed(): void
    {
        $opname = $this->finishedSession();

        $rows = $this->rowsOf($this->readExport($opname), 'Selisih');
        $skus = array_filter(array_column(array_slice($rows, 4), 0));

        // Kampas rem dihitung tepat, jadi tidak perlu ditindaklanjuti.
        $this->assertSame(['FLT-OLI-STD'], array_values($skus));
    }

    public function test_the_report_tab_carries_the_session_and_its_accuracy(): void
    {
        $opname = $this->finishedSession();

        $rows = $this->rowsOf($this->readExport($opname), 'Ringkasan');
        $values = $this->labelled($rows);

        $this->assertSame($opname->code, $values['Nomor Dokumen']);
        $this->assertSame('Seluruh gudang', $values['Cakupan']);
        $this->assertSame('Selesai', $values['Status']);

        $this->assertEquals(2, $values['Total SKU']);
        $this->assertEquals(2, $values['Sudah Dihitung']);
        $this->assertEquals(1, $values['Berselisih']);
        $this->assertEquals(2, $values['Unit Kurang']);
        $this->assertEquals(0, $values['Unit Lebih']);
        // Satu dari dua SKU sudah sesuai catatan.
        $this->assertEquals(50, $values['Akurasi per SKU (%)']);

        // Rekap petugas ikut masuk: "siapa menghitung apa" harus terjawab.
        $this->assertContains('Super Admin', array_column(array_slice($rows, 4), 0));
    }

    /* ------------------------------------------------------- penjagaan --- */

    public function test_a_session_still_being_counted_can_not_be_exported(): void
    {
        $opname = $this->openSession();

        $this->actingAs($this->admin)
            ->get(route('admin.opnames.export', $opname))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_exporting_needs_its_own_permission(): void
    {
        $opname = $this->finishedSession();

        $role = Role::firstOrCreate(
            ['slug' => 'pengawas-opname'],
            ['name' => 'Pengawas Opname', 'description' => 'Hanya melihat.', 'is_super_admin' => false],
        );

        $role->permissions()->sync(
            Permission::whereIn('slug', ['dashboard.view', 'opnames.view'])->pluck('id'),
        );

        $this->actingAs(User::factory()->create(['role_id' => $role->id]))
            ->get(route('admin.opnames.export', $opname))
            ->assertForbidden();
    }

    public function test_the_pages_offer_the_download_once_the_session_is_closed(): void
    {
        $opname = $this->finishedSession();

        $this->actingAs($this->admin)
            ->get(route('admin.opnames.show', $opname))
            ->assertOk()
            ->assertSee(route('admin.opnames.export', $opname));

        $this->actingAs($this->admin)
            ->get(route('admin.opnames.index'))
            ->assertOk()
            ->assertSee(route('admin.opnames.export', $opname));
    }

    public function test_a_session_still_being_counted_offers_no_download(): void
    {
        $opname = $this->openSession();

        $this->actingAs($this->admin)
            ->get(route('admin.opnames.show', $opname))
            ->assertOk()
            ->assertDontSee(route('admin.opnames.export', $opname));
    }

    /* --------------------------------------------------------- helpers --- */

    protected function openSession(): StockOpname
    {
        $this->actingAs($this->admin)->post(route('admin.opnames.store'), [
            'date' => now()->toDateString(),
            'scope' => StockOpname::SCOPE_ALL,
        ]);

        return StockOpname::with('items')->latest('id')->firstOrFail();
    }

    /** Sesi yang sudah dihitung, diajukan, dan diterapkan. */
    protected function finishedSession(bool $countTheBrakePads = true): StockOpname
    {
        $opname = $this->openSession();
        $items = $opname->items->keyBy('product_id');

        // Oli tercatat 10, ditemukan 8 dan satu di antaranya rusak.
        $counts = [$items[$this->oli->id]->id => 8];
        $damaged = [$items[$this->oli->id]->id => 1];

        if ($countTheBrakePads) {
            $counts[$items[$this->rem->id]->id] = 4;
        }

        $this->actingAs($this->admin)->post(route('admin.opnames.count', $opname), [
            'counts' => $counts,
            'damaged' => $damaged,
        ]);

        $this->actingAs($this->admin)->post(route('admin.opnames.submit', $opname));

        return $opname->refresh();
    }

    protected function readExport(StockOpname $opname): Spreadsheet
    {
        $response = $this->actingAs($this->admin)->get(route('admin.opnames.export', $opname));

        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'opname').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        return IOFactory::load($path);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function rowsOf(Spreadsheet $book, string $sheet): array
    {
        return $book->getSheetByName($sheet)->toArray(null, true, false, false);
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<int, mixed>
     */
    protected function rowFor(array $rows, string $sku): array
    {
        foreach ($rows as $row) {
            if (($row[0] ?? null) === $sku) {
                return $row;
            }
        }

        $this->fail("Baris {$sku} tidak ada di berkas hasil export.");
    }

    /**
     * Lembar ringkasan berbentuk label di kolom A dan nilainya di kolom B.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function labelled(array $rows): array
    {
        $values = [];

        foreach ($rows as $row) {
            if (is_string($row[0] ?? null) && ($row[1] ?? null) !== null) {
                $values[$row[0]] = $row[1];
            }
        }

        return $values;
    }
}
