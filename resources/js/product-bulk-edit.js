/**
 * Penyuntingan massal batas stok menipis pada daftar barang.
 *
 * Daftar barang berhalaman sepuluh baris, sedangkan yang perlu disamakan
 * batasnya sering tersebar di beberapa halaman. Pilihan karena itu disimpan di
 * sessionStorage dan dipulihkan setiap halaman berpindah — tanpa itu, memilih
 * lima barang lalu menekan "halaman berikutnya" berarti kehilangan semuanya.
 *
 * Karena barang terpilih bisa berada di halaman yang sedang tidak terlihat,
 * yang dikirim ke server bukan lagi kotak centang di layar melainkan kolom
 * tersembunyi yang dibangun dari daftar pilihan itu sendiri.
 */
const STORAGE_KEY = 'wms.products.bulk-selection';

export default function productBulkEdit(config) {
    return {
        ids: config.ids ?? [],
        total: config.total ?? 0,

        /**
         * Penanda saringan yang sedang aktif, tanpa nomor halaman.
         *
         * Pilihan hanya berlaku untuk saringan yang sama persis. Begitu
         * saringannya berganti, barang yang tercentang belum tentu masih
         * terlihat — dan pilihan yang tidak kelihatan adalah pilihan yang
         * berbahaya.
         */
        key: config.key ?? '',

        selected: [],
        allFiltered: false,

        init() {
            this.restore();

            this.$watch('selected', () => this.remember());
            this.$watch('allFiltered', () => this.remember());
        },

        /* ------------------------------------------------------ tampilan */

        /** Seluruh baris di halaman ini sudah tercentang. */
        get pageChosen() {
            return this.ids.length > 0 && this.ids.every((id) => this.selected.includes(id));
        },

        /** Barang terpilih yang sedang tidak terlihat di halaman ini. */
        get offPage() {
            return this.selected.filter((id) => ! this.ids.includes(id)).length;
        },

        get count() {
            return this.allFiltered ? this.total : this.selected.length;
        },

        /* ------------------------------------------------------- pilihan */

        togglePage() {
            this.allFiltered = false;

            // Hanya baris halaman ini yang disentuh; pilihan dari halaman lain
            // dibiarkan utuh.
            this.selected = this.pageChosen
                ? this.selected.filter((id) => ! this.ids.includes(id))
                : [...new Set([...this.selected, ...this.ids])];
        },

        chooseEverything() {
            this.selected = [...new Set([...this.selected, ...this.ids])];
            this.allFiltered = true;
        },

        clearAll() {
            this.selected = [];
            this.allFiltered = false;
        },

        /* --------------------------------------------------- penyimpanan */

        restore() {
            const stored = this.read();

            if (! stored || stored.key !== this.key) {
                this.forget();

                return;
            }

            this.selected = stored.selected ?? [];
            this.allFiltered = stored.allFiltered ?? false;
        },

        remember() {
            this.write({
                key: this.key,
                selected: this.selected,
                allFiltered: this.allFiltered,
            });
        },

        forget() {
            try {
                sessionStorage.removeItem(STORAGE_KEY);
            } catch {
                // Penyimpanan diblokir peramban; pilihan cukup berlaku sehalaman.
            }
        },

        read() {
            try {
                return JSON.parse(sessionStorage.getItem(STORAGE_KEY));
            } catch {
                return null;
            }
        },

        write(value) {
            try {
                sessionStorage.setItem(STORAGE_KEY, JSON.stringify(value));
            } catch {
                // Mode privat atau kuota penuh: biarkan, pilihan tetap jalan
                // selama halaman belum berpindah.
            }
        },
    };
}
