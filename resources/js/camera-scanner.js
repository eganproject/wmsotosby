/**
 * Pemindai lewat kamera ponsel.
 *
 * Scanner genggam tetap jalur utama di stasiun — lebih cepat dan tidak
 * membuat tangan penuh. Kamera dipakai saat scanner tidak ada di tangan:
 * memeriksa satu paket di rak, atau petugas yang membawa ponsel saja.
 *
 * Dua hal yang menentukan bentuk kode ini:
 *
 * 1. Mesin pemindainya dimuat belakangan. Peramban Android modern sudah
 *    punya BarcodeDetector bawaan sehingga tidak perlu unduhan sama
 *    sekali; yang belum punya (Safari iOS) baru menarik ponyfill-nya saat
 *    tombol kamera ditekan. Bundel utama tidak ikut membesar.
 *
 * 2. Berkas wasm-nya disajikan dari domain sendiri, bukan CDN. Aplikasi
 *    gudang harus tetap bekerja saat jaringan luar tidak bisa dijangkau.
 */
import { unlock as unlockAudio } from './feedback';
import { cameraState } from './scan-state';

/** Format yang dipakai marketplace: resi 1D, dan QR pada label. */
const FORMATS = ['qr_code', 'code_128', 'code_39', 'ean_13', 'ean_8', 'itf', 'codabar', 'upc_a', 'upc_e'];

let detectorPromise = null;

async function createDetector() {
    if ('BarcodeDetector' in window) {
        return new window.BarcodeDetector({ formats: FORMATS });
    }

    const [{ BarcodeDetector, setZXingModuleOverrides }, wasmUrl] = await Promise.all([
        import('barcode-detector/pure'),
        import('zxing-wasm/reader/zxing_reader.wasm?url').then((module) => module.default),
    ]);

    setZXingModuleOverrides({
        locateFile: (path, prefix) => (path.endsWith('.wasm') ? wasmUrl : prefix + path),
    });

    return new BarcodeDetector({ formats: FORMATS });
}

function detector() {
    return (detectorPromise ??= createDetector());
}

export default function cameraScanner() {
    return {
        open: false,
        busy: false,
        error: '',
        torchOn: false,

        /** Kode terakhir beserta waktunya, untuk meredam pembacaan ganda. */
        last: { code: '', at: 0 },

        stream: null,
        loop: null,

        /**
         * Kamera hanya diizinkan pada halaman aman. Di HTTP biasa peramban
         * menolak diam-diam, jadi lebih baik dikatakan sejak awal.
         */
        get available() {
            return window.isSecureContext && !! navigator.mediaDevices?.getUserMedia;
        },

        async start() {
            if (! this.available) {
                this.error = window.isSecureContext
                    ? 'Peramban ini tidak mengizinkan akses kamera.'
                    : 'Kamera hanya bisa dipakai lewat HTTPS. Buka aplikasi dengan alamat https://';
                this.open = true;

                return;
            }

            this.open = true;
            this.busy = true;
            this.error = '';

            // Papan ketik yang terlanjur terbuka menutupi tampilan kamera.
            cameraState.open = true;
            document.activeElement?.blur?.();

            // Ketukan tombol ini satu-satunya sentuhan pengguna sebelum
            // pemindaian berjalan sendiri. Peramban ponsel hanya mengizinkan
            // audio dinyalakan dari sentuhan, jadi kuncinya dibuka di sini —
            // tanpa ini seluruh bunyi hasil scan hilang tanpa pesan apa pun.
            unlockAudio();

            try {
                this.stream = await navigator.mediaDevices.getUserMedia({
                    // Kamera belakang; kalau tidak ada, peramban memilih yang tersedia.
                    video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 } },
                    audio: false,
                });

                const video = this.$refs.video;
                video.srcObject = this.stream;
                await video.play();

                await this.scanLoop();
            } catch (error) {
                this.error = this.explain(error);
                this.stop({ keepOpen: true });
            } finally {
                this.busy = false;
            }
        },

        /**
         * Membaca bingkai demi bingkai. requestAnimationFrame mengikuti
         * kecepatan layar, jadi pembacaan berhenti sendiri saat halaman
         * disembunyikan — kamera tidak menguras baterai di saku.
         */
        async scanLoop() {
            const engine = await detector();
            const video = this.$refs.video;

            const tick = async () => {
                if (! this.open || ! video?.videoWidth) {
                    this.loop = requestAnimationFrame(tick);

                    return;
                }

                try {
                    const [found] = await engine.detect(video);

                    if (found?.rawValue) {
                        this.accept(found.rawValue);
                    }
                } catch {
                    // Bingkai gagal dibaca bukan kesalahan: lanjut ke bingkai berikutnya.
                }

                this.loop = requestAnimationFrame(tick);
            };

            this.loop = requestAnimationFrame(tick);
        },

        /**
         * Satu label terbaca berkali-kali dalam sedetik. Kode yang sama
         * diabaikan sejenak supaya tidak terkirim berulang, tetapi jedanya
         * pendek: barang kembar memang perlu discan beruntun, dan menunggu
         * lama di antara dua barang identik justru memperlambat QC.
         *
         * Kode berbeda selalu diteruskan tanpa jeda sama sekali.
         */
        accept(code) {
            const now = Date.now();

            if (code === this.last.code && now - this.last.at < 1500) {
                return;
            }

            this.last = { code, at: now };

            // Bunyi hasilnya dibunyikan stasiun setelah server menjawab —
            // di sana barulah diketahui resi, barang, atau penolakan. Di sini
            // cukup getar pendek sebagai tanda "kode tertangkap".
            navigator.vibrate?.(25);

            window.dispatchEvent(new CustomEvent('camera-scan', { detail: { code } }));
        },

        async toggleTorch() {
            const track = this.stream?.getVideoTracks?.()[0];

            if (! track?.getCapabilities?.().torch) {
                this.error = 'Lampu tidak tersedia pada kamera ini.';

                return;
            }

            this.torchOn = ! this.torchOn;

            try {
                await track.applyConstraints({ advanced: [{ torch: this.torchOn }] });
            } catch {
                this.torchOn = false;
            }
        },

        stop({ keepOpen = false } = {}) {
            if (this.loop) {
                cancelAnimationFrame(this.loop);
                this.loop = null;
            }

            this.stream?.getTracks().forEach((track) => track.stop());
            this.stream = null;
            this.torchOn = false;

            if (! keepOpen) {
                this.open = false;
                this.error = '';
                cameraState.open = false;
            }
        },

        explain(error) {
            return {
                NotAllowedError: 'Izin kamera ditolak. Aktifkan lewat pengaturan situs di peramban.',
                NotFoundError: 'Tidak ada kamera yang bisa dipakai pada perangkat ini.',
                NotReadableError: 'Kamera sedang dipakai aplikasi lain.',
            }[error?.name] ?? 'Kamera gagal dibuka. Coba lagi, atau gunakan scanner.';
        },
    };
}
