/**
 * Pembantu penghitungan stok opname pada tampilan daftar.
 *
 * Scanner dipakai untuk melompat ke barangnya, bukan untuk menambah angka:
 * pada opname yang dicari adalah "berapa isi rak ini", jadi jumlahnya diketik
 * sekali. Pencariannya di sisi klien terhadap baris yang sedang tampil,
 * sehingga tidak ada perjalanan ke server di antara dua rak — dan bila
 * barangnya tidak ada di halaman ini, pencariannya dilanjutkan ke server
 * alih-alih berhenti sebagai galat.
 *
 * Halaman ini bukan lagi jalur utama saat menghitung; stasiun hitung yang
 * memegang peran itu. Yang tersisa di sini adalah menyisir sisa dan
 * memperbaiki baris tertentu — karena itu tiap kolom disimpan begitu
 * ditinggalkan, bukan menunggu satu tombol simpan di ujung halaman.
 */
import { announce as signal } from './feedback';

/** Selang penyegaran, sama dengan stasiun hitung. */
const LIVE_INTERVAL = 10000;

export default function opnameCounter(config = {}) {
    return {
        url: config.url ?? null,
        searchUrl: config.searchUrl ?? null,
        progressUrl: config.progressUrl ?? null,

        code: '',
        highlighted: null,
        message: { type: 'info', text: '' },

        /** Hasil simpan otomatis per baris: { [itemId]: { type, message } } */
        state: {},

        timers: {},

        /**
         * Jumlah baris terhitung yang sudah tercermin di halaman ini —
         * termasuk yang baru saja disimpan sendiri. Selisihnya terhadap angka
         * server adalah pekerjaan rekan, dan itulah yang dikabarkan.
         */
        settled: Number(config.counted ?? 0),

        /** Kemajuan terkini dari server; null selama belum pernah ditarik. */
        fresh: null,

        polling: false,
        live: null,
        onVisibility: null,

        init() {
            this.startLive();
        },

        destroy() {
            Object.values(this.timers).forEach((timer) => clearTimeout(timer));
            this.stopLive();
        },

        /* ------------------------------------------------------ live ----- */

        startLive() {
            if (! this.progressUrl) return;

            this.live = setInterval(() => this.refresh(), LIVE_INTERVAL);

            this.onVisibility = () => {
                if (! document.hidden) this.refresh();
            };

            document.addEventListener('visibilitychange', this.onVisibility);
        },

        stopLive() {
            clearInterval(this.live);

            if (this.onVisibility) document.removeEventListener('visibilitychange', this.onVisibility);
        },

        /**
         * Baris di halaman ini dirender server, jadi yang bisa dikabarkan
         * bukan isinya melainkan bahwa isinya sudah berubah. Memuat ulang
         * sendiri tidak dilakukan: petugas bisa sedang mengetik angka, dan
         * halaman yang berganti di tengah ketikan lebih merugikan daripada
         * angka yang tertinggal sepuluh detik.
         */
        async refresh() {
            if (document.hidden || this.polling) return;

            this.polling = true;

            try {
                const response = await fetch(this.progressUrl, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });

                if (! response.ok) return;

                this.fresh = (await response.json()).progress;
            } catch {
                // Jaringan gudang putus-putus; penyegaran berikutnya mencoba lagi.
            } finally {
                this.polling = false;
            }
        },

        /** Berapa baris yang dikerjakan rekan sejak halaman ini dimuat. */
        get behind() {
            return this.fresh ? this.fresh.counted - this.settled : 0;
        },

        get liveNote() {
            if (! this.fresh) return '';

            const names = this.fresh.counters.map((counter) => counter.name).join(', ');

            return this.behind > 0
                ? `${this.behind} baris lagi dihitung rekan sejak halaman ini dibuka${names ? ` · ${names}` : ''}.`
                : `Hitungan sesi ini baru berubah di layar rekan${names ? ` · ${names}` : ''}.`;
        },

        /**
         * Kirim hanya baris yang benar-benar disentuh.
         *
         * Satu sesi biasa dikerjakan beberapa orang sekaligus. Bila seluruh
         * baris di halaman ikut terkirim, baris kosong yang sebenarnya sudah
         * dihitung rekan lain akan tertimpa menjadi kosong lagi.
         *
         * Kolom rusak ikut diperiksa, bukan hanya kolom bagus: baris yang hanya
         * diisi jumlah rusaknya tetap baris yang disentuh, dan tanpa penjagaan
         * ini temuannya terbuang diam-diam saat disimpan.
         */
        pruneUntouched() {
            this.$el.querySelectorAll('[data-count-input]').forEach((input) => {
                const damaged = this.damagedFor(input);

                if (! this.isTouched(input)) {
                    input.disabled = true;

                    if (damaged) damaged.disabled = true;

                    const baseline = input.form?.querySelector(`[name="baseline[${input.dataset.countInput}]"]`);

                    if (baseline) baseline.disabled = true;
                }
            });
        },

        /* ------------------------------------------------- simpan otomatis */

        /**
         * Simpan satu baris begitu kolomnya ditinggalkan.
         *
         * Sebelumnya seluruh halaman disimpan lewat satu tombol, sehingga
         * berpindah halaman sebelum menekannya membuang seluruh hitungan yang
         * sudah diketik — kehilangan yang tidak pernah diberitahukan.
         */
        async autosave(id) {
            const input = this.$el.querySelector(`[data-count-input="${id}"]`);

            if (! this.url || ! input || ! this.isTouched(input)) return;

            const damaged = this.damagedFor(input);

            try {
                const payload = await this.post({
                    product_id: Number(input.dataset.product),
                    counted: input.value === '' ? null : Number(input.value),
                    damaged: damaged && damaged.value !== '' ? Number(damaged.value) : 0,
                    // Nilai awal baris ini saat halaman dimuat — inilah yang
                    // membuat hitungan rekan tidak tertimpa diam-diam.
                    baseline: input.dataset.baseline === '' ? null : Number(input.dataset.baseline),
                });

                this.adopt(input, damaged, payload.item);
                this.flash(id, 'saved', 'Tersimpan.');
                signal('item');

                // Pekerjaan sendiri bukan kabar; yang dikabarkan hanya selisih
                // terhadap angka ini, yaitu apa yang dikerjakan rekan.
                this.settled = payload.progress.counted;
                this.fresh = payload.progress;
            } catch (error) {
                /*
                    Baris sudah berubah di database. Nilai awalnya diperbarui ke
                    angka rekan, jadi menyimpan sekali lagi berarti menimpanya
                    dengan sengaja — persetujuan yang diminta cukup dengan
                    mengulang, tanpa kotak dialog di tengah gudang.
                */
                if (error.conflict) {
                    if (error.progress) {
                        this.settled = error.progress.counted;
                        this.fresh = error.progress;
                    }

                    this.adopt(input, damaged, error.item, true);
                    this.flash(id, 'error', `${error.message} Simpan sekali lagi untuk menimpanya.`, 12000);
                    signal('rejected');

                    return;
                }

                this.flash(id, 'error', error.message, 12000);
                signal('error');
            }
        },

        /** Nilai awal baris disamakan dengan keadaan terakhir di database. */
        adopt(input, damaged, item, keepTyped = false) {
            input.dataset.baseline = item.counted ?? '';

            if (! keepTyped) input.value = item.counted ?? '';

            if (damaged) {
                damaged.dataset.baseline = item.damaged || '';

                if (! keepTyped) damaged.value = item.damaged || '';
            }
        },

        flash(id, type, message, timeout = 4000) {
            clearTimeout(this.timers[id]);

            this.state[id] = { type, message };

            this.timers[id] = setTimeout(() => {
                delete this.state[id];
            }, timeout);
        },

        damagedFor(input) {
            return input.form?.querySelector(`[data-damaged-input="${input.dataset.countInput}"]`) ?? null;
        },

        isTouched(input) {
            const damaged = this.damagedFor(input);

            return input.value !== input.dataset.baseline
                || (damaged && damaged.value !== damaged.dataset.baseline);
        },

        async post(body) {
            const response = await fetch(this.url, {
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
                        ?? payload.message ?? 'Baris ini gagal disimpan.',
                );
            }

            return payload;
        },

        /* ------------------------------------------------------- melompat */

        jump() {
            const code = this.code.trim().toUpperCase();

            if (! code) return;

            const row = [...this.$el.querySelectorAll('[data-sku]')].find(
                (element) => element.dataset.sku === code || element.dataset.barcode === code,
            );

            if (! row) {
                this.searchOnServer(code);

                return;
            }

            const input = row.querySelector('[data-count-input]');

            this.highlighted = Number(input?.dataset.countInput ?? 0);
            this.message = { type: 'info', text: `${code} — isi jumlah hasil hitungnya.` };

            row.scrollIntoView({ behavior: 'smooth', block: 'center' });

            signal('item');
            this.code = '';

            // Kursor pindah setelah gulirannya mulai, supaya barisnya terlihat.
            setTimeout(() => {
                input?.focus();
                input?.select();
            }, 150);
        },

        /**
         * Barang bisa berada di halaman ke sekian dari daftar yang panjang.
         * Sebelumnya pencarian berhenti di situ dan petugas harus mencarinya
         * sendiri; sekarang halamannya yang menyusul ke barangnya.
         */
        searchOnServer(code) {
            if (! this.searchUrl) {
                this.message = { type: 'error', text: `${code} tidak ada di halaman ini.` };
                signal('error');
                this.code = '';

                return;
            }

            this.message = { type: 'info', text: `${code} tidak ada di halaman ini — dicari ke seluruh sesi…` };

            const url = new URL(this.searchUrl, window.location.origin);

            url.searchParams.set('search', code);

            window.location.href = url.toString();
        },
    };
}
