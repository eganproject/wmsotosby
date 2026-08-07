<?php

namespace Tests\Feature\Admin;

use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menandai resi batal dari status yang sudah telanjur tersimpan.
 *
 * Perintah ini menyentuh data produksi dan akibatnya tidak sepele — paket yang
 * ditandai tidak bisa lagi discan maupun dikirim. Karena itu yang paling perlu
 * dijaga bukan bahwa ia menulis dengan benar, melainkan bahwa ia tidak menulis
 * apa pun sampai diminta.
 */
class FlagCancelledWaybillsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_nothing_until_it_is_told_to(): void
    {
        $order = $this->makeOrder('SPXID111', 'Dibatalkan');

        $this->artisan('waybills:flag-cancelled')
            ->expectsOutputToContain('Belum ada yang diubah')
            ->assertSuccessful();

        $this->assertFalse($order->refresh()->isCancelled());
    }

    public function test_it_flags_the_waybills_whose_status_says_cancelled(): void
    {
        $cancelled = $this->makeOrder('SPXID111', 'Dibatalkan');
        $requested = $this->makeOrder('SPXID222', 'Cancellation Requested');
        $healthy = $this->makeOrder('SPXID333', 'Siap Dikirim');
        $blank = $this->makeOrder('SPXID444', null);

        $this->artisan('waybills:flag-cancelled', ['--apply' => true])
            ->expectsOutputToContain('2 resi ditandai batal')
            ->assertSuccessful();

        $this->assertTrue($cancelled->refresh()->isCancelled());
        $this->assertTrue($requested->refresh()->isCancelled());
        $this->assertFalse($healthy->refresh()->isCancelled());
        $this->assertFalse($blank->refresh()->isCancelled());
    }

    /**
     * Batal dari data dicatat tanpa nama, persis seperti yang datang lewat
     * berkas import — karena memang bukan orang yang memutuskannya.
     */
    public function test_the_flag_is_recorded_as_coming_from_the_data(): void
    {
        $order = $this->makeOrder('SPXID111', 'Dibatalkan');

        $this->artisan('waybills:flag-cancelled', ['--apply' => true])->assertSuccessful();

        $order->refresh();

        $this->assertTrue($order->isCancelled());
        $this->assertFalse($order->isCancelledByHand());
        $this->assertSame('Batal menurut data import (Dibatalkan)', $order->cancellationDetail());
    }

    /** Pembatalan yang sudah ditandai orang tidak boleh ditimpa. */
    public function test_it_leaves_an_existing_cancellation_alone(): void
    {
        $order = $this->makeOrder('SPXID111', 'Dibatalkan');
        $order->forceFill([
            'cancelled_at' => now()->subDay(),
            'cancelled_by' => \App\Models\User::factory()->create()->id,
            'cancellation_reason' => 'Pembeli chat minta batal.',
        ])->save();

        $this->artisan('waybills:flag-cancelled', ['--apply' => true])
            ->expectsOutputToContain('Tidak ada resi yang perlu ditandai')
            ->assertSuccessful();

        $this->assertSame('Pembeli chat minta batal.', $order->refresh()->cancellation_reason);
    }

    public function test_it_says_so_when_there_is_nothing_to_do(): void
    {
        $this->makeOrder('SPXID111', 'Siap Dikirim');

        $this->artisan('waybills:flag-cancelled')
            ->expectsOutputToContain('Tidak ada resi yang perlu ditandai')
            ->assertSuccessful();
    }

    public function test_it_survives_an_empty_database(): void
    {
        $this->artisan('waybills:flag-cancelled')->assertSuccessful();
    }

    protected function makeOrder(string $tracking, ?string $status): ShipmentOrder
    {
        $import = ShipmentImport::create([
            'filename' => 'ginee.csv', 'source' => 'ginee', 'row_count' => 1,
            'detected_columns' => ['tracking_number', 'sku'],
        ]);

        return $import->orders()->create([
            'tracking_number' => $tracking,
            'order_number' => 'INV-'.$tracking,
            'order_status' => $status,
        ]);
    }
}
