/**
 * Pembantu penghitungan stok opname.
 *
 * Scanner dipakai untuk melompat ke barangnya, bukan untuk menambah angka:
 * pada opname yang dicari adalah "berapa isi rak ini", jadi jumlahnya diketik
 * sekali. Pencariannya di sisi klien terhadap baris yang sedang tampil,
 * sehingga tidak ada perjalanan ke server di antara dua rak.
 */
import { announce as signal } from './feedback';

export default function opnameCounter() {
    return {
        code: '',
        highlighted: null,
        message: { type: 'info', text: '' },

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
                const id = input.dataset.countInput;
                const damaged = input.form?.querySelector(`[data-damaged-input="${id}"]`);

                const untouched = input.value === input.dataset.baseline
                    && (! damaged || damaged.value === damaged.dataset.baseline);

                if (! untouched) return;

                input.disabled = true;

                if (damaged) damaged.disabled = true;

                const baseline = input.form?.querySelector(`[name="baseline[${id}]"]`);

                if (baseline) baseline.disabled = true;
            });
        },

        jump() {
            const code = this.code.trim().toUpperCase();

            if (! code) return;

            const row = [...this.$el.querySelectorAll('[data-sku]')].find(
                (element) => element.dataset.sku === code || element.dataset.barcode === code,
            );

            if (! row) {
                this.message = {
                    type: 'error',
                    text: `${code} tidak ada di halaman ini. Coba saringan "Semua" atau cari lewat kolom pencarian.`,
                };

                signal('error');
                this.code = '';

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
    };
}
