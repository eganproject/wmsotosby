<?php

namespace Tests\Feature\Admin;

use Tests\TestCase;

/**
 * Umpan balik bunyi dan getar saat memindai.
 *
 * Operator memegang barang dan melihat rak, bukan layar. Karena itu tiap
 * kejadian punya bunyinya sendiri dan harus bisa dibedakan tanpa melihat.
 */
class ScanFeedbackTest extends TestCase
{
    protected function feedback(): string
    {
        return file_get_contents(resource_path('js/feedback.js'));
    }

    public function test_every_scan_event_has_its_own_tone_and_buzz(): void
    {
        $script = $this->feedback();

        foreach (['resi', 'item', 'complete', 'rejected', 'error'] as $kind) {
            $this->assertMatchesRegularExpression("/^\s+{$kind}: \[\{ hz:/m", $script,
                "Nada untuk '{$kind}' belum terdaftar.");

            $this->assertMatchesRegularExpression("/^\s+{$kind}: \[\d+/m", $script,
                "Pola getar untuk '{$kind}' belum terdaftar.");
        }
    }

    /**
     * Nada resi dan nada barang tidak boleh sama — itu inti permintaannya:
     * operator harus tahu mana yang barusan terbaca tanpa melihat layar.
     */
    public function test_the_waybill_and_item_tones_are_different(): void
    {
        $script = $this->feedback();

        preg_match('/^\s+resi: (\[.+\]),$/m', $script, $resi);
        preg_match('/^\s+item: (\[.+\]),$/m', $script, $item);

        $this->assertNotEmpty($resi[1] ?? '');
        $this->assertNotEmpty($item[1] ?? '');
        $this->assertNotSame($resi[1], $item[1]);
    }

    public function test_the_volume_is_loud_enough_for_a_warehouse(): void
    {
        $script = $this->feedback();

        preg_match('/const VOLUME = ([\d.]+);/', $script, $matches);

        $this->assertGreaterThanOrEqual(0.3, (float) ($matches[1] ?? 0),
            'Volume terlalu pelan untuk dipakai di gudang.');

        // Gelombang persegi menembus kebisingan jauh lebih baik dari sinus.
        $this->assertStringContainsString("oscillator.type = 'square'", $script);
    }

    /**
     * Peramban ponsel menolak membunyikan apa pun sebelum ada sentuhan
     * pengguna. Pemindaian lewat kamera bukan sentuhan, jadi kuncinya harus
     * dibuka saat tombol kamera ditekan — kalau tidak, seluruh bunyi hilang
     * tanpa satu pun pesan galat.
     */
    public function test_audio_is_unlocked_by_the_camera_button(): void
    {
        $this->assertStringContainsString('export function unlock()', $this->feedback());
        $this->assertStringContainsString("context.state === 'suspended'", $this->feedback());

        $camera = file_get_contents(resource_path('js/camera-scanner.js'));

        $this->assertStringContainsString('unlockAudio()', $camera);
    }

    public function test_the_stations_ask_for_the_matching_tone(): void
    {
        $packing = file_get_contents(resource_path('js/packing-station.js'));

        // Resi, barang, dan paket lengkap masing-masing nadanya sendiri.
        $this->assertStringContainsString("'resi'", $packing);
        $this->assertStringContainsString("'item'", $packing);
        $this->assertStringContainsString("signal('complete')", $packing);

        // Resi yang ditolak berbunyi lain dari barang yang salah discan.
        $this->assertStringContainsString("this.isResiStage ? 'rejected' : 'error'", $packing);
    }
}
