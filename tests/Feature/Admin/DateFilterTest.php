<?php

namespace Tests\Feature\Admin;

use App\Models\DamagedDisposal;
use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Product;
use App\Models\ReturnReceipt;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\StockAdjustment;
use App\Models\StockOpname;
use App\Models\User;
use App\Support\DateRange;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Saringan rentang tanggal pada seluruh daftar dokumen.
 *
 * Yang gampang salah bukan penulisan query-nya melainkan penanganan tepinya:
 * batasnya harus inklusif di kedua ujung, sisi yang kosong tidak boleh ikut
 * membatasi, dan masukan yang tidak terbaca harus diabaikan alih-alih
 * menjatuhkan halaman.
 */
class DateFilterTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();

        $this->product = Product::create([
            'sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli Standar', 'unit' => 'pcs', 'min_stock' => 0,
        ]);
    }

    /* --------------------------------------------------- tiap halaman ---- */

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pages(): array
    {
        return [
            'barang masuk' => ['inbound', 'admin.inbounds.index'],
            'barang keluar' => ['outbound', 'admin.outbounds.index'],
            'penerimaan retur' => ['return', 'admin.returns.index'],
            'penyesuaian stok' => ['adjustment', 'admin.adjustments.index'],
            'stok opname' => ['opname', 'admin.opnames.index'],
            'barang rusak' => ['disposal', 'admin.disposals.index'],
            'data import' => ['order', 'admin.imports.index'],
            'status resi' => ['order', 'admin.imports.status'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_a_list_can_be_narrowed_to_a_date_range(string $kind, string $route): void
    {
        $old = $this->makeDocument($kind, Carbon::parse('2026-07-01'), 'LAMA');
        $recent = $this->makeDocument($kind, Carbon::parse('2026-08-05'), 'BARU');

        $this->actingAs($this->admin)
            ->get(route($route, ['from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertSee($recent)
            ->assertDontSee($old);

        // Rentang penuh harus diminta terang-terangan.
        $this->actingAs($this->admin)->get(route($route, ['range' => DateRange::ALL]))
            ->assertOk()
            ->assertSee($recent)
            ->assertSee($old);
    }

    /**
     * Daftar terbuka pada hari berjalan.
     *
     * Pekerjaan gudang hampir selalu tentang hari ini, dan daftar yang membuka
     * seluruh riwayat memaksa orang menyaring ulang setiap kali.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_a_list_opens_on_the_current_day(string $kind, string $route): void
    {
        $today = $this->makeDocument($kind, Carbon::today(), 'HARIINI');
        $old = $this->makeDocument($kind, Carbon::today()->subMonth(), 'LAMA');

        $this->actingAs($this->admin)->get(route($route))
            ->assertOk()
            ->assertSee($today)
            ->assertDontSee($old);
    }

    /**
     * Mengosongkan tanggal tidak lagi berarti "semua": kosong berarti hari ini.
     * Tanpa penanda tersendiri, daftar tidak akan pernah bisa dibuka penuh.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_the_full_range_needs_its_own_marker(string $kind, string $route): void
    {
        $old = $this->makeDocument($kind, Carbon::today()->subMonth(), 'LAMA');

        $this->actingAs($this->admin)->get(route($route, ['from' => '', 'to' => '']))
            ->assertOk()
            ->assertDontSee($old);

        $this->actingAs($this->admin)->get(route($route, ['range' => DateRange::ALL]))
            ->assertOk()
            ->assertSee($old);
    }

    /**
     * Rentangnya inklusif di kedua ujung. Orang yang menulis 1–31 Agustus
     * bermaksud memasukkan tanggal 1 dan 31 itu sendiri.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_both_ends_of_the_range_are_included(string $kind, string $route): void
    {
        $first = $this->makeDocument($kind, Carbon::parse('2026-08-01'), 'AWAL');
        $last = $this->makeDocument($kind, Carbon::parse('2026-08-31'), 'AKHIR');

        $this->actingAs($this->admin)
            ->get(route($route, ['from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertSee($first)
            ->assertSee($last);
    }

    /** Satu sisi saja berarti tak berbatas di sisi lainnya. */
    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_one_sided_ranges_only_bind_on_that_side(string $kind, string $route): void
    {
        $old = $this->makeDocument($kind, Carbon::parse('2026-07-01'), 'LAMA');
        $recent = $this->makeDocument($kind, Carbon::parse('2026-08-05'), 'BARU');

        $this->actingAs($this->admin)->get(route($route, ['from' => '2026-08-01']))
            ->assertOk()->assertSee($recent)->assertDontSee($old);

        $this->actingAs($this->admin)->get(route($route, ['to' => '2026-07-31']))
            ->assertOk()->assertSee($old)->assertDontSee($recent);
    }

    /**
     * Alamat yang disalin tempel sering rusak sebagian. Daftar yang tetap
     * tampil apa adanya lebih berguna daripada halaman galat.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_an_unreadable_date_is_ignored_instead_of_breaking_the_page(string $kind, string $route): void
    {
        $today = $this->makeDocument($kind, Carbon::today(), 'HARIINI');

        // Diabaikan berarti jatuh ke bawaannya, bukan menjatuhkan halaman.
        $this->actingAs($this->admin)
            ->get(route($route, ['from' => 'kemarin', 'to' => '31-08-2026']))
            ->assertOk()
            ->assertSee($today);
    }

    /**
     * Satu kolom yang terlihat, dua parameter yang terkirim.
     *
     * Nilainya tetap dikirim sebagai from dan to lewat kolom tersembunyi
     * sehingga controller, tautan pintasan, maupun alamat yang pernah disalin
     * orang tetap berlaku — yang berubah hanya tampilannya.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_the_page_offers_one_field_that_still_sends_both_bounds(string $kind, string $route): void
    {
        $html = $this->actingAs($this->admin)->get(route($route))
            ->assertOk()
            ->assertSee('data-date-range', false)
            ->assertSee('Hari ini')
            ->assertSee('Bulan ini')
            ->getContent();

        $this->assertSame(1, substr_count($html, 'data-date-range-input'), 'Hanya boleh ada satu kolom tanggal.');

        foreach (['from', 'to'] as $bound) {
            $this->assertStringContainsString(
                "<input type=\"hidden\" name=\"{$bound}\"",
                $html,
                "Batas {$bound} harus tetap terkirim.",
            );
        }

        // Kolom tanggal kembar yang lama tidak boleh tersisa di mana pun.
        $this->assertStringNotContainsString('type="date" name="from"', $html);
        $this->assertStringNotContainsString('type="date" name="to"', $html);
    }

    /**
     * Rentang yang sedang aktif harus terbaca sejak halaman dimuat, bukan
     * setelah JavaScript sempat berjalan — kolom kosong padahal daftarnya
     * tersaring adalah kebohongan yang mahal.
     */
    public function test_the_active_range_is_readable_without_javascript(): void
    {
        $this->makeDocument('outbound', Carbon::parse('2026-08-05'), 'BARU');

        $this->actingAs($this->admin)
            ->get(route('admin.outbounds.index', ['from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertSee('01/08/2026 – 31/08/2026');
    }

    /**
     * Rentang sehari ditulis satu kali saja.
     *
     * flatpickr membuang tanggal kembar pada mode rentang, jadi teks yang
     * dirender server harus melakukan hal yang sama — kalau tidak, tulisannya
     * berubah sendiri begitu JavaScript selesai dimuat.
     */
    public function test_a_single_day_range_is_written_once(): void
    {
        $this->makeDocument('outbound', Carbon::parse('2026-08-05'), 'BARU');

        $response = $this->actingAs($this->admin)
            ->get(route('admin.outbounds.index', ['from' => '2026-08-05', 'to' => '2026-08-05']))
            ->assertOk();

        $this->assertStringContainsString('value="05/08/2026"', $response->getContent());
        $this->assertStringNotContainsString('05/08/2026 – 05/08/2026', $response->getContent());
    }

    /**
     * Rentang bertepi terbuka tidak bisa digambarkan kalender, jadi tepinya
     * disebut dengan kata — kalau tidak, "01/08/2026" terbaca seperti saringan
     * satu hari padahal daftarnya memuat semua yang sesudahnya.
     */
    public function test_an_open_ended_range_says_which_end_is_missing(): void
    {
        $this->makeDocument('outbound', Carbon::parse('2026-08-05'), 'BARU');

        $this->actingAs($this->admin)
            ->get(route('admin.outbounds.index', ['from' => '2026-08-01']))
            ->assertOk()
            ->assertSee('01/08/2026', false)
            ->assertSee('Tanpa batas akhir');

        $this->actingAs($this->admin)
            ->get(route('admin.outbounds.index', ['to' => '2026-08-31']))
            ->assertOk()
            ->assertSee('31/08/2026', false)
            ->assertSee('Tanpa batas awal');
    }

    /** Tanggal yang tidak terbaca tidak boleh menjatuhkan kolomnya. */
    public function test_an_unreadable_date_leaves_the_field_empty(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.outbounds.index', ['from' => 'kemarin', 'to' => '31-08-2026']))
            ->assertOk()
            ->assertSee('placeholder="Semua tanggal"', false);
    }

    /* --------------------------------------------------- kasus khusus ---- */

    /**
     * Eksport Ginee tidak selalu menyertakan kolom tanggal. Pesanan tanpa
     * tanggal akan lenyap seluruhnya begitu saringan dinyalakan — jawaban yang
     * salah tanpa satu pun petunjuk — jadi yang kosong memakai waktu berkasnya
     * masuk ke sistem.
     */
    public function test_a_waybill_without_an_order_date_falls_back_to_when_it_arrived(): void
    {
        $this->travelTo(Carbon::parse('2026-08-05 10:00'));
        $order = $this->makeOrder(null, 'TANPATANGGAL');
        $this->travelBack();

        $this->assertSame(1, ShipmentOrder::dateBetween(new DateRange('2026-08-01', '2026-08-31'))->count());
        $this->assertSame(0, ShipmentOrder::dateBetween(new DateRange('2026-07-01', '2026-07-31'))->count());

        $this->actingAs($this->admin)
            ->get(route('admin.imports.status', ['from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertSee($order->tracking_number);
    }

    /**
     * Resi diurutkan menurut tanggal yang sama dengan yang dipakai saringannya.
     *
     * Sebelumnya urutannya menurut nomor baris, yang berarti urutan masuknya
     * berkas import — bukan urutan pesanannya. Menyaring menurut satu tanggal
     * lalu mengurutkan menurut hal lain membuat halaman pertama tidak berisi
     * yang paling baru.
     */
    public function test_waybills_are_ordered_by_the_date_the_filter_uses(): void
    {
        // Sengaja dimasukkan terbalik: yang tertua justru punya id terbesar.
        $this->makeOrder(Carbon::parse('2026-08-01'), 'SPX-TENGAH');
        $this->makeOrder(Carbon::parse('2026-08-06'), 'SPX-BARU');
        $this->makeOrder(Carbon::parse('2026-07-20'), 'SPX-LAMA');

        foreach (['admin.imports.index', 'admin.imports.status'] as $route) {
            $html = $this->actingAs($this->admin)
                ->get(route($route, ['range' => DateRange::ALL]))
                ->assertOk()
                ->getContent();

            $order = collect(['SPX-BARU', 'SPX-TENGAH', 'SPX-LAMA'])
                ->sortBy(fn (string $needle) => strpos($html, $needle))
                ->values()
                ->all();

            $this->assertSame(['SPX-BARU', 'SPX-TENGAH', 'SPX-LAMA'], $order, "Urutan salah pada {$route}.");
        }
    }

    /**
     * Pintasan periode adalah tautan biasa, jadi saringan lain yang sedang
     * aktif harus ikut terbawa — kalau tidak, memilih "hari ini" diam-diam
     * membuang pencarian yang sudah diketik.
     */
    public function test_a_shortcut_carries_the_other_filters_along(): void
    {
        $this->makeDocument('outbound', Carbon::today(), 'BARU');

        $response = $this->actingAs($this->admin)
            ->get(route('admin.outbounds.index', ['search' => 'BARU', 'status' => 'draft']))
            ->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('search=BARU', $html);
        $this->assertStringContainsString('status=draft', $html);
        // Nomor halaman sengaja dibuang: hasil yang menyusut membuat halaman
        // ketiga sering kosong.
        $this->assertStringNotContainsString('page=', $html);
    }

    /* --------------------------------------------------- helpers --------- */

    /**
     * @return string Penanda yang pasti tampil di halamannya.
     */
    protected function makeDocument(string $kind, Carbon $date, string $marker): string
    {
        return match ($kind) {
            'inbound' => tap(Inbound::create([
                'code' => 'IN-'.$marker, 'date' => $date, 'status' => Inbound::STATUS_DRAFT,
            ]), fn ($doc) => $doc->items()->create(['product_id' => $this->product->id, 'quantity' => 1]))->code,

            'outbound' => tap(Outbound::create([
                'code' => 'OUT-'.$marker, 'date' => $date, 'type' => Outbound::TYPE_REGULAR,
                'recipient' => 'Bengkel', 'status' => Outbound::STATUS_DRAFT,
            ]), fn ($doc) => $doc->items()->create(['product_id' => $this->product->id, 'quantity' => 1]))->code,

            'return' => tap(ReturnReceipt::create([
                'code' => 'RET-'.$marker, 'date' => $date, 'type' => ReturnReceipt::TYPE_REGULAR,
                'sender' => 'Pembeli', 'status' => ReturnReceipt::STATUS_DRAFT,
            ]), fn ($doc) => $doc->items()->create([
                'product_id' => $this->product->id, 'quantity' => 1, 'good_quantity' => 1, 'damaged_quantity' => 0,
            ]))->code,

            'adjustment' => tap(StockAdjustment::create([
                'code' => 'ADJ-'.$marker, 'date' => $date, 'reason' => 'rusak',
                'status' => StockAdjustment::STATUS_DRAFT,
            ]), fn ($doc) => $doc->items()->create([
                'product_id' => $this->product->id, 'actual_quantity' => 1,
            ]))->code,

            'opname' => StockOpname::create([
                'code' => 'OPN-'.$marker, 'date' => $date, 'scope' => 'all',
                'status' => StockOpname::STATUS_DRAFT,
            ])->code,

            'disposal' => tap(DamagedDisposal::create([
                'code' => 'DSP-'.$marker, 'date' => $date, 'action' => 'dibuang',
                'status' => DamagedDisposal::STATUS_DRAFT,
            ]), fn ($doc) => $doc->items()->create([
                'product_id' => $this->product->id, 'quantity' => 1,
            ]))->code,

            'order' => $this->makeOrder($date, 'SPX'.$marker)->tracking_number,
        };
    }

    protected function makeOrder(?Carbon $date, string $tracking): ShipmentOrder
    {
        $import = ShipmentImport::create([
            'filename' => 'ginee.csv', 'source' => 'ginee', 'row_count' => 1,
            'detected_columns' => ['tracking_number', 'sku'],
        ]);

        $order = $import->orders()->create([
            'tracking_number' => $tracking,
            'order_number' => 'INV-'.$tracking,
            'marketplace' => 'Shopee',
            'courier' => 'SPX',
            'order_date' => $date,
        ]);

        $order->items()->create([
            'sku' => $this->product->sku,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'quantity' => 1,
        ]);

        return $order;
    }
}
