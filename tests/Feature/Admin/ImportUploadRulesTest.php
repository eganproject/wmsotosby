<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Aturan penerimaan berkas import.
 *
 * Pemeriksaan memakai akhiran berkas, bukan tebakan isi. Aturan `mimes:`
 * bergantung pada ekstensi PHP `fileinfo`; bila ekstensi itu tidak aktif —
 * lazim di shared hosting — tebakannya selalu gagal dan berkas xlsx yang
 * sah pun ikut ditolak.
 */
class ImportUploadRulesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();
    }

    public static function importPages(): array
    {
        return [
            'import resi' => ['admin.imports.store'],
            'import barang' => ['admin.products.import.store'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('importPages')]
    public function test_a_spreadsheet_extension_passes_the_upload_rule(string $route): void
    {
        foreach (['xlsx', 'xls', 'csv'] as $extension) {
            $this->actingAs($this->admin)->post(route($route), [
                'file' => UploadedFile::fake()->create("ginee.{$extension}", 8),
            ]);

            // Isinya memang bukan spreadsheet sungguhan sehingga pembacanya
            // tetap menolak. Yang diuji di sini: penolakannya datang dari
            // pembaca, bukan lagi dari aturan unggah.
            $message = session('errors')?->first('file') ?? '';

            $this->assertStringNotContainsString('harus berakhiran', $message,
                "Berkas .{$extension} seharusnya lolos aturan unggah.");
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('importPages')]
    public function test_an_unrelated_extension_is_still_refused(string $route): void
    {
        $this->actingAs($this->admin)->post(route($route), [
            'file' => UploadedFile::fake()->create('laporan.pdf', 8),
        ])->assertSessionHasErrors('file');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('importPages')]
    public function test_the_refusal_says_what_is_accepted(string $route): void
    {
        $this->actingAs($this->admin)->post(route($route), [
            'file' => UploadedFile::fake()->create('laporan.pdf', 8),
        ]);

        $message = session('errors')->first('file');

        $this->assertStringContainsString('.xlsx', $message);
        $this->assertStringContainsString('.csv', $message);
    }

    /**
     * Penjagaan sebenarnya ada di pembaca spreadsheet: berkas berakhiran
     * benar tetapi isinya bukan spreadsheet tetap ditolak, dengan pesan yang
     * menjelaskan alih-alih galat mentah.
     */
    public function test_a_file_that_is_not_really_a_spreadsheet_is_refused_by_the_reader(): void
    {
        $this->actingAs($this->admin)->post(route('admin.imports.store'), [
            'file' => UploadedFile::fake()->createWithContent('ginee.xlsx', 'ini bukan spreadsheet'),
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('shipment_imports', 0);
    }

    /**
     * Aturannya tidak boleh diam-diam kembali memakai `mimes:`, karena di
     * server tanpa `fileinfo` itu menolak seluruh berkas.
     */
    public function test_the_rule_does_not_depend_on_content_sniffing(): void
    {
        foreach ([
            'app/Http/Controllers/Admin/ShipmentImportController.php',
            'app/Http/Controllers/Admin/ProductImportController.php',
        ] as $path) {
            $source = file_get_contents(base_path($path));

            $this->assertStringContainsString('extensions:xlsx,xls,csv,txt', $source);
            $this->assertStringNotContainsString('mimes:xlsx', $source);
        }
    }
}
