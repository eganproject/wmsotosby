/**
 * Stasiun hitung stok opname.
 *
 * Satu kolom dipakai berulang tanpa berpindah halaman:
 *
 *   scan/ketik SKU → kartunya terbuka → ketik jumlah → Enter → SKU berikutnya
 *
 * Yang menentukan bentuk komponen ini: fokus tidak pernah berpindah kolom.
 * Barcode scanner berperilaku seperti papan ketik, jadi kolom angka yang
 * difokuskan otomatis akan menelan hasil scan berikutnya dan membuangnya —
 * kolom number menolak huruf. Karena itu jumlahnya diketik ke kolom yang sama
 * dengan kodenya, dan kolom di kartu hanya untuk yang memakai sentuhan.
 *
 * Saldo tercatat tidak pernah dikirim server ke sini. Yang dilihat petugas
 * hanya barang dan hitungannya sendiri.
 */
import { announce as signal, timestamp } from './feedback';
import { cameraState } from './scan-state';

/**
 * Angka murni: "12", atau "12 r2" bila dua di antaranya rusak.
 *
 * Dibatasi empat digit karena kolom yang sama juga menerima barcode. Barcode
 * terpendek yang dipakai marketplace ada delapan digit, jadi di bawah lima
 * digit tidak pernah ambigu. Hitungan yang lebih besar dari itu tetap bisa
 * diketik lewat kolom di kartunya.
 */
const QUANTITY_ONLY = /^(\d{1,4})(?:\s*[rR](\d{1,4}))?$/;

/** Jaring pengaman untuk hitungan besar yang terlanjur diketik ke kolom kode. */
const LARGE_QUANTITY = /^\d{1,6}$/;

/**
 * Selang penyegaran panel tim.
 *
 * Cukup rapat untuk terasa langsung saat rekan menghitung di lorong sebelah,
 * cukup jarang untuk tidak membebani server ketika sepuluh layar terbuka
 * sekaligus. Layar yang tersembunyi berhenti bertanya sama sekali.
 */
const LIVE_INTERVAL = 10000;

export default function opnameStation(config) {
    return {
        urls: config.urls,
        progress: config.progress,

        code: '',
        busy: false,
        feedback: null,

        /** Barang yang sedang dihitung; null berarti menunggu scan. */
        card: null,
        status: null, // 'ready' | 'out_of_scope'
        conflict: null,

        counted: '',
        damaged: '',

        history: [],

        /** Barang di kartu keburu dihitung rekan selagi kartunya terbuka. */
        taken: null,

        /** Sedang menarik pembaruan; mencegah dua permintaan menumpuk. */
        polling: false,

        timer: null,
        onVisibility: null,

        init() {
            this.focusInput();
            this.startLive();
        },

        destroy() {
            this.stopLive();
        },

        /* --------------------------------------------------------- live -- */

        startLive() {
            this.timer = setInterval(() => this.refresh(), LIVE_INTERVAL);

            /*
                Layar yang tersembunyi — ponsel di saku, tab lain di depan —
                tidak perlu ditanyakan sama sekali. Begitu kembali terlihat
                isinya disegarkan sekali, supaya petugas tidak menatap angka
                basi selama satu selang penuh.
            */
            this.onVisibility = () => {
                if (! document.hidden) this.refresh();
            };

            document.addEventListener('visibilitychange', this.onVisibility);
        },

        stopLive() {
            clearInterval(this.timer);

            if (this.onVisibility) document.removeEventListener('visibilitychange', this.onVisibility);
        },

        /**
         * Tarik kemajuan sesi terkini.
         *
         * Sengaja tidak menyentuh apa pun yang sedang diketik petugas: yang
         * diperbarui hanya angka tim, dan kabar bahwa barang di kartu berpindah
         * tangan. Nilai awal kartunya dibiarkan apa adanya supaya penyimpanan
         * tetap melewati pemeriksaan bentrokan, bukan menimpa tanpa sadar.
         */
        async refresh() {
            if (document.hidden || this.busy || this.polling) return;

            this.polling = true;

            try {
                const url = new URL(this.urls.progress, window.location.origin);

                if (this.isOpen) url.searchParams.set('product_id', this.card.product_id);

                const response = await fetch(url, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });

                if (! response.ok) return;

                const payload = await response.json();

                this.progress = payload.progress;
                this.watch(payload.item);
            } catch {
                // Jaringan gudang putus-putus; penyegaran berikutnya mencoba lagi.
            } finally {
                this.polling = false;
            }
        },

        /**
         * Barang yang sedang dipegang ternyata baru dihitung rekan.
         *
         * Dikabarkan sekarang, selagi petugas masih berdiri di depan raknya —
         * bukan nanti saat menyimpan, ketika hitungannya sudah terlanjur
         * diketik dan raknya sudah ditinggalkan.
         */
        watch(item) {
            if (! item || ! this.isOpen || item.product_id !== this.card.product_id) {
                this.taken = null;

                return;
            }

            if (item.counted === this.card.counted || item.counted_by_me) {
                this.taken = null;

                return;
            }

            const by = item.counted_by ?? 'petugas lain';
            const message = item.counted === null
                ? `${by} baru saja mengosongkan hitungan barang ini.`
                : `${by} baru saja menghitung barang ini ${item.counted}${item.counted_at ? ` pukul ${item.counted_at}` : ''}.`;

            // Sekali saja per perubahan: bunyi yang berulang tiap sepuluh detik
            // berubah dari peringatan menjadi kebisingan.
            if (this.taken !== message) signal('rejected');

            this.taken = message;
        },

        /* --------------------------------------------------- tampilan --- */

        get isOpen() {
            return this.card !== null;
        },

        /** Barang ada di katalog tetapi belum punya baris di sesi ini. */
        get isOutOfScope() {
            return this.status === 'out_of_scope';
        },

        get title() {
            if (! this.isOpen) return 'Scan atau ketik SKU barang';

            return this.isOutOfScope ? 'Barang di luar cakupan sesi' : `Berapa isi rak untuk ${this.card.sku}?`;
        },

        get hint() {
            if (this.conflict) return this.conflict;

            if (this.taken) return `${this.taken} Simpan tetap akan menanyakan lebih dulu.`;

            if (! this.isOpen) {
                return `${this.progress.remaining} SKU lagi. Ketik "SKU 12" untuk langsung menyimpan jumlahnya.`;
            }

            if (this.isOutOfScope) {
                return 'Isi jumlahnya, lalu barisnya ditambahkan ke sesi ini.';
            }

            if (this.card.counted === null) return 'Ketik jumlahnya lalu tekan Enter.';

            // Petugas yang menghitung bisa saja sudah dihapus akunnya.
            const by = this.card.counted_by_me ? 'Anda' : (this.card.counted_by ?? 'petugas lain');

            return `Sudah dihitung ${by} ${this.card.counted_at ?? ''} — angka baru menggantikannya.`;
        },

        get placeholder() {
            return this.isOpen ? 'Ketik jumlah lalu Enter…' : 'Scan barcode atau ketik SKU…';
        },

        get actionLabel() {
            return this.isOpen ? 'Simpan' : 'Cari';
        },

        /* ---------------------------------------------------- pemasukan -- */

        /**
         * Satu kolom, tiga arti — ditentukan oleh apa yang sedang terbuka.
         */
        async submit() {
            if (this.busy) return;

            const raw = this.code.trim();

            // Enter pada kolom kosong menyimpan apa yang sudah diketik di kartu.
            if (! raw) {
                if (this.isOpen) await this.save();

                return;
            }

            const quantity = this.isOpen ? raw.match(QUANTITY_ONLY) : null;

            if (quantity) {
                this.counted = quantity[1];
                this.damaged = quantity[2] ?? '';
                this.code = '';

                await this.save();

                return;
            }

            await this.lookup(raw);
        },

        async lookup(code) {
            this.busy = true;
            this.feedback = null;

            // Penyimpanan menunggu sampai kolomnya bebas: save() menolak
            // bekerja selama panggilan lain masih berjalan.
            let autosave = false;

            try {
                const payload = await this.post(this.urls.lookup, { code });

                this.progress = payload.progress;
                this.open(payload);

                // Jumlah yang ikut diketik ("SKU 12") langsung disimpan —
                // kecuali barang di luar cakupan, yang tidak pernah masuk
                // sesi tanpa ditekan sendiri.
                autosave = ! this.isOutOfScope
                    && payload.quick?.counted !== null
                    && payload.quick?.counted !== undefined;
            } catch (error) {
                // Hitungan lima digit ke atas terbaca sebagai kode, bukan
                // jumlah. Daripada menolaknya, dipakai sebagai jumlah — satu-
                // satunya arti yang masuk akal saat kartunya sedang terbuka.
                if (this.isOpen && LARGE_QUANTITY.test(code)) {
                    this.counted = code;
                    autosave = true;
                } else {
                    this.report('error', error.message, code, 'rejected');
                }
            } finally {
                this.busy = false;
                this.code = '';
                this.focusInput();
            }

            if (autosave) await this.save();
        },

        /** Kartu barang terbuka, siap diisi. */
        open(payload) {
            this.card = payload.item;
            this.status = payload.status;
            this.conflict = null;
            this.taken = null;

            this.counted = payload.item.counted ?? '';
            this.damaged = payload.item.damaged || '';

            const quick = payload.quick ?? {};

            if (quick.counted !== null && quick.counted !== undefined) {
                this.counted = quick.counted;
                this.damaged = quick.damaged || '';
            }

            signal('item');
        },

        /* ------------------------------------------------- penyimpanan --- */

        async save(options = {}) {
            if (! this.isOpen || this.busy) return;

            const force = options.force ?? false;
            const counted = this.counted === '' ? null : Math.max(0, Number(this.counted) || 0);
            const damaged = this.damaged === '' ? 0 : Math.max(0, Number(this.damaged) || 0);

            this.busy = true;
            this.feedback = null;

            const card = this.card;

            try {
                const payload = await this.post(this.urls.count, {
                    product_id: card.product_id,
                    counted,
                    damaged,
                    // Nilai yang dilihat petugas saat kartunya dibuka. Inilah
                    // yang membuat hitungan rekan tidak tertimpa diam-diam.
                    baseline: card.counted,
                    force,
                    adopt: this.isOutOfScope,
                });

                this.progress = payload.progress;
                this.remember(payload.item, counted, damaged);
                this.report('success', payload.message, card.sku, 'item');
                this.reset();
            } catch (error) {
                if (error.conflict) {
                    this.progress = error.progress ?? this.progress;
                    // Kartunya diperbarui ke keadaan terkini, jadi tombol
                    // timpa mengirim nilai awal yang benar.
                    this.card = error.item;
                    this.status = 'ready';
                    this.conflict = error.message;
                    // Kabar dari penyegaran digantikan pertanyaan yang lebih tegas.
                    this.taken = null;

                    signal('rejected');

                    return;
                }

                this.report('error', error.message, card.sku, 'error');
            } finally {
                this.busy = false;
                this.focusInput();
            }
        },

        /** Timpa hitungan rekan dengan sengaja. */
        overwrite() {
            this.conflict = null;

            return this.save({ force: true });
        },

        /** Pakai angka rekan; kartunya ditutup tanpa menyimpan apa pun. */
        keepTheirs() {
            this.report('success', `${this.card.sku} dibiarkan seperti hitungan rekan.`, this.card.sku, 'item');
            this.reset();
        },

        cancel() {
            this.reset();
            this.focusInput();
        },

        reset() {
            this.card = null;
            this.status = null;
            this.conflict = null;
            this.taken = null;
            this.counted = '';
            this.damaged = '';
            this.code = '';
        },

        /* ---------------------------------------------------- riwayat ---- */

        /**
         * Satu SKU satu baris riwayat: scan ulang mengganti hitungannya, jadi
         * daftar ini tidak boleh menyimpan dua angka untuk barang yang sama.
         */
        remember(item, counted, damaged) {
            this.history = [
                {
                    id: item.product_id,
                    sku: item.sku,
                    name: item.name,
                    counted,
                    damaged,
                    unit: item.unit,
                    at: timestamp(),
                },
                ...this.history.filter((entry) => entry.id !== item.product_id),
            ].slice(0, 10);
        },

        /** Salah ketik diperbaiki dengan memanggil ulang barangnya. */
        recount(entry) {
            if (this.busy) return;

            this.code = entry.sku;

            return this.submit();
        },

        /* ------------------------------------------------------ bantu ---- */

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

            // Baris sudah berubah di database: bukan kegagalan, melainkan
            // keadaan yang harus diputuskan petugas.
            if (response.status === 409) {
                const conflict = new Error(payload.message ?? 'Baris ini baru saja dihitung petugas lain.');

                conflict.conflict = true;
                conflict.item = payload.item;
                conflict.progress = payload.progress;

                throw conflict;
            }

            if (! response.ok) {
                const errors = payload.errors ?? {};

                throw new Error(
                    errors.code?.[0] ?? errors.counted?.[0] ?? errors.damaged?.[0]
                        ?? payload.message ?? 'Gagal diproses.',
                );
            }

            return payload;
        },

        report(type, message, code, sound = null) {
            this.feedback = { type, message, code };

            signal(sound ?? (type === 'success' ? 'item' : 'error'));
        },

        focusInput() {
            // Memfokuskan kolom teks saat kamera terbuka memunculkan papan
            // ketik yang menutupi bidikan.
            if (cameraState.open) return;

            this.$nextTick(() => this.$refs.input?.focus());
        },
    };
}
