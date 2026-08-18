/**
 * Baris paket bundling pada dokumen barang keluar.
 *
 * Paket dipesan sebagai barisnya sendiri lalu dipecah menjadi barang saat
 * dokumennya disimpan, jadi yang dikirim formulir hanya SKU paket dan
 * jumlahnya — sisanya dikerjakan server.
 *
 * Dimulai tanpa baris sama sekali, tidak seperti editor baris barang: dokumen
 * yang tidak memakai paket tidak seharusnya diberi baris kosong yang harus
 * diabaikan setiap kali.
 */
let sequence = 0;

export default function outboundBundles(initial = [], catalog = []) {
    return {
        catalog,
        rows: initial.map(normalize),

        add() {
            this.rows.push(blankRow());

            this.$nextTick(() => {
                this.syncControls();
                this.$refs.rows?.lastElementChild?.querySelector('.ts-control')?.click();
            });
        },

        remove(index) {
            this.rows.splice(index, 1);
            this.syncControls();
        },

        /**
         * Alpine menulis langsung ke select, sedangkan Tom Select menyimpan
         * tampilannya sendiri. Beri tahu agar keduanya kembali sejalan.
         */
        syncControls() {
            this.$nextTick(() => window.dispatchEvent(new CustomEvent('form:sync')));
        },

        bundle(row) {
            return this.catalog.find((item) => String(item.id) === String(row.bundle_id));
        },

        /**
         * Melebihi yang masih bisa dijanjikan.
         *
         * Angkanya sudah dikurangi pesanan lain yang belum diproses, jadi
         * batas ini menjawab pertanyaan yang sebenarnya: paket ini masih bisa
         * dijanjikan berapa lagi.
         */
        isOverAvailable(row) {
            const bundle = this.bundle(row);

            return !! bundle && Number(row.quantity || 0) > Number(bundle.available || 0);
        },

        isDuplicate(row) {
            if (! row.bundle_id) return false;

            return this.rows.filter((other) => String(other.bundle_id) === String(row.bundle_id)).length > 1;
        },

        get filledRows() {
            return this.rows.filter((row) => row.bundle_id).length;
        },

        get totalPackages() {
            return this.rows
                .filter((row) => row.bundle_id)
                .reduce((total, row) => total + Number(row.quantity || 0), 0);
        },

        /** Unit barang yang akan dihasilkan seluruh baris paket. */
        get totalUnits() {
            return this.rows
                .filter((row) => row.bundle_id)
                .reduce((total, row) => {
                    const bundle = this.bundle(row);

                    return total + (bundle ? Number(bundle.units || 0) * Number(row.quantity || 0) : 0);
                }, 0);
        },

        get hasProblem() {
            return this.rows.some((row) => this.isOverAvailable(row) || this.isDuplicate(row));
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
        key: `bundle-${++sequence}`,
        bundle_id: String(row.bundle_id ?? ''),
        quantity: Number(row.quantity ?? 1),
    };
}
