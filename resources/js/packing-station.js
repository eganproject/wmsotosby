/**
 * Stasiun packing pesanan marketplace.
 *
 * Satu layar dipakai berulang tanpa pernah berpindah halaman:
 *
 *   scan resi → scan barang → paket lengkap → kembali menunggu resi
 *
 * Stasiun ini berhenti sampai "isi paket terverifikasi". Pengirimannya —
 * yang memindahkan stok — dikerjakan di antrean Siap Dikirim, jadi operator
 * tidak pernah berhenti untuk mengurus dokumen. Kursor selalu dikembalikan ke
 * kolom input dan tahapannya berganti sendiri, jadi tidak ada klik antar paket.
 */
import { announce as signal, timestamp } from './feedback';
import { cameraState } from './scan-state';

export default function packingStation(config) {
    return {
        urls: { start: config.url },
        readyUrl: config.readyUrl,

        stage: 'resi', // resi | item | done
        code: '',
        busy: false,
        feedback: null,

        outbound: null,
        items: [],
        progress: null,

        /** Paket yang selesai diverifikasi pada sesi ini. */
        completed: [],
        history: [],

        /** Lanjut sendiri ke resi berikutnya begitu satu paket lengkap. */
        autoContinue: true,

        /**
         * Jumlah yang ditambahkan sekali scan.
         *
         * Pesanan borongan seratus pcs tidak masuk akal dipindai seratus
         * kali. Isi angkanya, scan sekali, lalu angkanya kembali ke satu
         * supaya scan berikutnya tidak ikut terkali tanpa sengaja.
         */
        bulk: 1,

        init() {
            this.focusInput();
        },

        get isResiStage() {
            return this.stage === 'resi';
        },

        get isItemStage() {
            return this.stage === 'item';
        },

        get remaining() {
            return this.progress ? this.progress.total - this.progress.scanned : 0;
        },

        /* ------------------------------------------------------ tampilan */

        /** Langkah keberapa, dipakai rail di atas layar. */
        get step() {
            return { resi: 1, item: 2, done: 3 }[this.stage];
        },

        get title() {
            return {
                resi: 'Scan resi pesanan',
                item: 'Scan barcode barang',
                done: 'Paket lengkap',
            }[this.stage];
        },

        get hint() {
            return {
                resi: 'Arahkan scanner ke label resi pada paket. Isi pesanan diambil dari data import Ginee.',
                item: `Sisa ${this.remaining} unit lagi. Paket ditutup sendiri saat unit terakhir discan.`,
                done: this.autoContinue
                    ? 'Masuk antrean siap kirim. Bersiap untuk resi berikutnya…'
                    : 'Masuk antrean siap kirim. Tekan Lanjut untuk paket berikutnya.',
            }[this.stage];
        },

        get placeholder() {
            return {
                resi: 'Scan atau ketik nomor resi…',
                item: 'Scan barcode atau ketik SKU barang…',
                done: '',
            }[this.stage];
        },

        get actionLabel() {
            return { resi: 'Cari', item: 'Proses', done: '' }[this.stage];
        },

        /** Unit pada paket yang sedang dikerjakan. */
        get totalUnits() {
            return this.progress ? this.progress.total : 0;
        },

        /** Unit yang sudah discan sepanjang sesi ini. */
        get sessionUnits() {
            return this.completed.reduce((total, entry) => total + entry.units, 0);
        },

        /** Gabungkan detail barang dengan progres scan terbarunya. */
        get lines() {
            return this.items.map((item) => ({
                ...item,
                ...(this.progress?.items.find((row) => row.id === item.id) ?? { scanned: 0, completed: false }),
                short: (item.stock ?? 0) < item.quantity,
            }));
        },

        /**
         * Barang yang stoknya tidak mencukupi.
         *
         * Paket seperti ini ditolak server sejak resi discan, jadi normalnya
         * kosong. Tetap ditandai kalau stok habis oleh paket lain di tengah
         * proses scan.
         */
        get shortages() {
            return this.lines.filter((line) => line.short);
        },

        /* ------------------------------------------------------- scanning */

        async submit() {
            const code = this.code.trim();
            if (! code || this.busy || this.stage === 'done') return;

            this.busy = true;
            this.feedback = null;

            try {
                this.isResiStage ? await this.startPackage(code) : await this.scanItem(code);
            } catch (error) {
                // Resi ditolak berbunyi lain dari barang yang salah discan:
                // yang pertama berarti "ambil paket lain", yang kedua berarti
                // "barang ini bukan isinya".
                this.report('error', error.message, code, this.isResiStage ? 'rejected' : 'error');
            } finally {
                this.busy = false;
                this.code = '';
                this.focusInput();
            }
        },

        async startPackage(code) {
            const payload = await this.post(this.urls.start, { code });

            this.outbound = payload.outbound;
            this.items = payload.items;
            this.progress = payload.progress;
            this.urls = { ...this.urls, ...payload.urls };
            this.stage = 'item';

            // Nomor dokumennya sudah tampil di kartu identitas paket, jadi
            // pesannya cukup menyebut yang belum diketahui: berapa unit.
            this.report('success', `Paket dibuka · ${payload.progress.total} unit`, code, 'resi');
        },

        async scanItem(code) {
            const quantity = Math.max(1, Number(this.bulk) || 1);

            const payload = await this.post(this.urls.item, { code, quantity });

            this.bulk = 1;
            this.progress = payload.progress;
            this.report('success', payload.message, code, 'item');

            // Unit terakhir menutup paket. Tidak ada tombol, tidak ada dialog:
            // paketnya masuk antrean siap kirim dan layar lanjut sendiri.
            if (this.progress.ready) this.completePackage();
        },

        /**
         * Tuntaskan sisa satu baris sekaligus.
         *
         * Dipakai saat barangnya datang dalam dus tersegel: isinya dihitung
         * dari label dus, bukan dibuka lalu dipindai satu per satu.
         */
        async fillLine(line) {
            const remaining = line.quantity - line.scanned;

            if (this.busy || remaining < 1) return;

            this.busy = true;
            this.feedback = null;

            try {
                const payload = await this.post(this.urls.item, {
                    code: line.sku,
                    quantity: remaining,
                });

                this.progress = payload.progress;
                this.report('success', payload.message, line.sku, 'item');

                if (this.progress.ready) this.completePackage();
            } catch (error) {
                this.report('error', error.message, line.sku, 'error');
            } finally {
                this.busy = false;
                this.focusInput();
            }
        },

        /* -------------------------------------------------- penyelesaian */

        completePackage() {
            this.stage = 'done';

            this.completed.unshift({
                id: Date.now(),
                code: this.outbound.code,
                tracking_number: this.outbound.tracking_number,
                units: this.progress.total,
                at: timestamp(),
            });

            this.feedback = {
                type: 'success',
                message: `Paket lengkap · ${this.progress.total} unit`,
            };

            // Bunyi paket lengkap sengaja paling menonjol: itu penanda
            // operator boleh beralih ke paket berikutnya.
            signal('complete');

            this.autoContinue ? this.scheduleNext() : null;
        },

        /** Beri sekejap waktu membaca hasilnya, lalu siap untuk paket berikutnya. */
        scheduleNext() {
            setTimeout(() => this.reset(), 1200);
        },

        reset() {
            this.stage = 'resi';
            this.outbound = null;
            this.items = [];
            this.progress = null;
            this.code = '';
            this.focusInput();
        },

        /* ------------------------------------------------------- bantuan */

        async post(url, body) {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });

            const payload = await response.json().catch(() => ({}));

            if (! response.ok) {
                throw new Error(payload.errors?.code?.[0] ?? payload.message ?? 'Permintaan gagal diproses.');
            }

            return payload;
        },

        /**
         * @param {string} sound Nada yang dibunyikan — lihat feedback.js.
         */
        report(type, message, code, sound = null) {
            this.feedback = { type, message };

            if (code) {
                this.history.unshift({ id: Date.now(), type, message, code, at: timestamp() });
                this.history = this.history.slice(0, 6);
            }

            signal(sound ?? (type === 'success' ? 'item' : 'error'));
        },

        focusInput() {
            // Saat memindai lewat kamera, memfokuskan kolom teks akan
            // memunculkan papan ketik yang menutupi tampilan kamera.
            if (cameraState.open) return;

            this.$nextTick(() => this.$refs.input?.focus());
        },
    };
}
