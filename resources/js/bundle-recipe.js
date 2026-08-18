/**
 * Editor isi paket bundling di master barang.
 *
 * Baris disimpan di state Alpine lalu dirender sebagai input bernama
 * components[i][component_id] dan components[i][quantity], jadi formulirnya
 * tetap dikirim sebagai form biasa tanpa endpoint tambahan — sama seperti
 * editor baris dokumen.
 */
let sequence = 0;

export default function bundleRecipe(initial = [], catalog = [], type = 'single') {
    return {
        type,
        catalog,
        rows: initial.length ? initial.map(normalize) : [blankRow()],

        get isBundle() {
            return this.type === 'bundle';
        },

        add() {
            this.rows.push(blankRow());

            this.$nextTick(() => {
                this.syncControls();
                this.$refs.rows?.lastElementChild?.querySelector('.ts-control')?.click();
            });
        },

        remove(index) {
            this.rows.splice(index, 1);
            if (! this.rows.length) this.rows.push(blankRow());

            this.syncControls();
        },

        /**
         * Alpine menulis langsung ke select, sedangkan Tom Select menyimpan
         * tampilannya sendiri. Beri tahu agar keduanya kembali sejalan.
         */
        syncControls() {
            this.$nextTick(() => window.dispatchEvent(new CustomEvent('form:sync')));
        },

        component(row) {
            return this.catalog.find((item) => String(item.id) === String(row.component_id));
        },

        /**
         * Berapa paket yang bisa dibentuk dari saldo komponen ini saja.
         *
         * Angka yang sama dihitung ulang di server saat ditampilkan; di sini
         * ia hanya membantu menyusun resep — terlihat sejak baris dipilih,
         * bukan setelah formulirnya disimpan.
         */
        setsFrom(row) {
            const component = this.component(row);
            const per = Number(row.quantity || 0);

            if (! component || per < 1) return null;

            return Math.floor(Number(component.stock || 0) / per);
        },

        /**
         * Ketersediaan paket = komponen yang paling sedikit menyediakannya.
         * Baris yang belum lengkap diabaikan supaya angkanya tidak berkedip
         * menjadi nol saat baris baru ditambahkan.
         */
        get availability() {
            const sets = this.rows.map((row) => this.setsFrom(row)).filter((value) => value !== null);

            return sets.length ? Math.min(...sets) : 0;
        },

        /** Baris pembatas — yang membuat paket tidak bisa dirakit lebih banyak. */
        isBottleneck(row) {
            const sets = this.setsFrom(row);

            return sets !== null && this.filledRows > 1 && sets === this.availability;
        },

        isDuplicate(row) {
            if (! row.component_id) return false;

            return this.rows.filter((other) => String(other.component_id) === String(row.component_id)).length > 1;
        },

        get filledRows() {
            return this.rows.filter((row) => row.component_id).length;
        },

        get totalUnits() {
            return this.rows
                .filter((row) => row.component_id)
                .reduce((total, row) => total + Number(row.quantity || 0), 0);
        },

        get hasDuplicate() {
            return this.rows.some((row) => this.isDuplicate(row));
        },
    };
}

function blankRow() {
    return normalize({});
}

function normalize(row) {
    return {
        // Kunci stabil supaya Alpine membuang elemen baris yang benar saat
        // dihapus, bukan menggeser isinya ke elemen tetangga.
        key: `component-${++sequence}`,
        component_id: String(row.component_id ?? ''),
        quantity: Number(row.quantity ?? 1),
    };
}
