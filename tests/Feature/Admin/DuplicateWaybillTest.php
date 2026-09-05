<?php

namespace Tests\Feature\Admin;

use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\User;
use App\Support\DateRange;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Resi duplikat: satu nomor pesanan yang berangkat dengan lebih dari satu resi.
 *
 * Marketplace kadang mencetak ulang resi — pesanannya tetap satu, resinya jadi
 * dua. Resi yang lama tidak hilang dari sistem; ia tetap duduk di daftar
 * sebagai pekerjaan yang menunggu, dan selama tidak ada yang menyadarinya
 * pesanan itu bisa dipacking dan dikirim dua kali.
 */
class DuplicateWaybillTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected ShipmentImport $import;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();

        $this->import = ShipmentImport::create(['filename' => 'ginee.xlsx', 'source' => 'ginee']);
    }

    public function test_the_card_counts_waybills_that_share_an_order_number(): void
    {
        $this->makeOrder('SPXID111', 'INV-1');
        $this->makeOrder('SPXID222', 'INV-1');
        $this->makeOrder('SPXID333', 'INV-2');

        // Dua resi yang terlibat, bukan satu pasangan: yang dihitung adalah
        // resi yang harus diperiksa orang, dan keduanya harus diperiksa.
        $this->assertSame(2, $this->open()->viewData('duplicates'));
    }

    public function test_a_waybill_with_a_number_of_its_own_is_never_counted(): void
    {
        $this->makeOrder('SPXID111', 'INV-1');
        $this->makeOrder('SPXID222', 'INV-2');

        $this->assertSame(0, $this->open()->viewData('duplicates'));
    }

    /**
     * Nomor pesanan yang kosong bukan nomor pesanan yang sama.
     *
     * Sebagian eksport Ginee tidak memuat kolom itu sama sekali. Menganggap
     * dua kekosongan sebagai kecocokan akan menandai seluruh isi berkas
     * sebagai duplikat sekaligus — peringatan yang begitu ramai sampai tidak
     * ada lagi yang membacanya.
     */
    public function test_waybills_without_an_order_number_are_never_duplicates(): void
    {
        $this->makeOrder('SPXID111', null);
        $this->makeOrder('SPXID222', null);
        $this->makeOrder('SPXID333', '');
        $this->makeOrder('SPXID444', '');

        $this->assertSame(0, $this->open()->viewData('duplicates'));
    }

    public function test_the_list_can_be_narrowed_to_duplicates(): void
    {
        $this->makeOrder('SPXID111', 'INV-1');
        $this->makeOrder('SPXID222', 'INV-1');
        $this->makeOrder('SPXID333', 'INV-2');

        $response = $this->open(['duplicate' => 1]);

        $this->assertSame(2, $response->viewData('orders')->total());
        $this->assertTrue($response->viewData('onlyDuplicates'));

        $response->assertSee('SPXID111')->assertSee('SPXID222')->assertDontSee('SPXID333');
    }

    /**
     * Resi pengganti hampir selalu masuk pada hari yang berbeda dengan resi
     * aslinya. Kalau kembarnya hanya dicari di dalam rentang tanggal yang
     * sedang dipilih, justru pasangan yang paling perlu ditemukan itulah yang
     * lolos — dan halaman melapor bersih.
     */
    public function test_a_twin_uploaded_on_another_day_still_marks_the_new_one(): void
    {
        $this->makeOrder('SPXID111', 'INV-1', Carbon::today()->subWeek());
        $this->makeOrder('SPXID222', 'INV-1');

        // Halaman terbuka pada hari berjalan: hanya resi yang baru yang tampil.
        $response = $this->open();

        $this->assertSame(1, $response->viewData('duplicates'));
        $this->assertSame(1, $response->viewData('orders')->total());

        // Nomor kembarannya tetap disebut walau barisnya sendiri di luar rentang.
        $response->assertSee('SPXID222')->assertSee('SPXID111');

        $this->assertSame(2, $this->open(['range' => DateRange::ALL])->viewData('duplicates'));
    }

    public function test_the_row_names_the_other_waybill_of_the_same_order(): void
    {
        $this->makeOrder('SPXID111', 'INV-1');
        $this->makeOrder('SPXID222', 'INV-1');

        $twins = $this->open()->viewData('twins');

        $this->assertSame(['SPXID111', 'SPXID222'], $twins['INV-1']);
    }

    /**
     * Berbeda dengan tahap, penanda ini memang menyempitkan kartunya juga —
     * kartu dan daftar harus bercerita hal yang sama.
     */
    public function test_the_duplicate_filter_narrows_the_stage_cards(): void
    {
        $this->makeOrder('SPXID111', 'INV-1');
        $this->makeOrder('SPXID222', 'INV-1');
        $this->makeOrder('SPXID333', 'INV-2');

        $all = $this->open()->viewData('counts');
        $onlyDuplicates = $this->open(['duplicate' => 1])->viewData('counts');

        $this->assertSame(3, $all[ShipmentOrder::STAGE_AWAITING_QC]);
        $this->assertSame(2, $onlyDuplicates[ShipmentOrder::STAGE_AWAITING_QC]);

        // Kartunya sendiri tidak ikut menyusut: ia pemilihnya, bukan yang dipilih.
        $this->assertSame(2, $this->open(['duplicate' => 1])->viewData('duplicates'));
    }

    public function test_the_duplicate_filter_survives_a_search(): void
    {
        $this->makeOrder('SPXID111', 'INV-1');
        $this->makeOrder('SPXID222', 'INV-1');
        $this->makeOrder('JNE999', 'INV-2');

        $response = $this->open(['duplicate' => 1, 'search' => 'SPXID111']);

        $this->assertSame(1, $response->viewData('orders')->total());
        $this->assertTrue($response->viewData('onlyDuplicates'));
    }

    public function test_the_duplicate_filter_combines_with_a_stage(): void
    {
        $this->makeOrder('SPXID111', 'INV-1');
        $this->makeOrder('SPXID222', 'INV-1')
            ->forceFill(['cancelled_at' => now()])->save();

        $response = $this->open(['duplicate' => 1, 'stage' => ShipmentOrder::STAGE_CANCELLED]);

        $this->assertSame(1, $response->viewData('orders')->total());
        $this->assertSame('SPXID222', $response->viewData('orders')->first()->tracking_number);

        // SPXID111 tetap tertulis di halaman — sebagai kembaran pada baris
        // SPXID222, bukan sebagai baris tersendiri. Justru itu gunanya:
        // petugas perlu tahu resi mana yang batal berpasangan dengan resi mana.
        $response->assertSee('SPXID222')->assertSee('SPXID111');
    }

    /**
     * Menandai salah satu kembarnya batal adalah tindakan yang paling wajar
     * dilakukan dari daftar ini. Kepulangannya harus tetap ke daftar yang sama,
     * bukan ke seluruh resi — kalau tidak, memeriksa sepuluh pasangan berarti
     * sepuluh kali menyaring ulang.
     */
    public function test_cancelling_from_the_duplicate_list_returns_to_it(): void
    {
        $this->makeOrder('SPXID111', 'INV-1');
        $keep = $this->makeOrder('SPXID222', 'INV-1');

        $this->actingAs($this->admin)
            ->post(route('admin.imports.orders.cancel', $keep), [
                'cancellation_reason' => 'Resi cetak ulang',
                'duplicate' => 1,
            ])
            ->assertRedirect(route('admin.imports.status', ['duplicate' => 1]));
    }

    /**
     * Kartunya tetap ada meski nol.
     *
     * Pemeriksaan yang hanya muncul saat sedang bermasalah tidak akan pernah
     * ditemukan orang yang belum tahu ia ada — dan "nol duplikat" justru kabar
     * yang ingin dibaca sebelum kurir datang.
     */
    public function test_the_card_still_reports_when_nothing_is_duplicated(): void
    {
        $this->makeOrder('SPXID111', 'INV-1');

        $response = $this->open();

        $this->assertSame(0, $response->viewData('duplicates'));
        $response->assertSee('Resi Duplikat');
    }

    /**
     * Halaman yang belum berisi resi sama sekali pun tetap menampilkannya:
     * di sanalah orang pertama kali melihat fiturnya ada.
     */
    public function test_the_card_is_there_on_an_empty_page(): void
    {
        $this->open()->assertSee('Resi Duplikat');
    }

    public function test_the_card_appears_once_something_is_duplicated(): void
    {
        $this->makeOrder('SPXID111', 'INV-1');
        $this->makeOrder('SPXID222', 'INV-1');

        $this->open()->assertSee('Resi Duplikat');
    }

    /* --------------------------------------------------- helpers --------- */

    /**
     * @param  array<string, mixed>  $query
     */
    protected function open(array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->get(route('admin.imports.status', $query))
            ->assertOk();
    }

    protected function makeOrder(string $tracking, ?string $orderNumber, ?Carbon $uploadedAt = null): ShipmentOrder
    {
        $order = $this->import->orders()->create([
            'tracking_number' => $tracking,
            'order_number' => $orderNumber,
            'marketplace' => 'Shopee',
            'courier' => 'SPX Standard',
        ]);

        if ($uploadedAt) {
            $order->forceFill(['created_at' => $uploadedAt, 'updated_at' => $uploadedAt])->save();
        }

        return $order;
    }
}
