/**
 * Editor baris barang untuk dokumen barang masuk, keluar, dan retur.
 *
 * Baris disimpan di state Alpine lalu dirender sebagai input bernama
 * items[i][product_id] dan items[i][quantity], jadi form tetap dikirim
 * sebagai form biasa tanpa endpoint tambahan.
 */
let sequence = 0;

export default function lineItems(initial = [], products = []) {
    return {
        products,
        rows: initial.length ? initial.map(normalize) : [blankRow()],

        init() {
            // Baris hasil pencarian resi di data import menggantikan isi editor.
            this.onResiItems = (event) => this.replace(event.detail);
            window.addEventListener('resi-items-found', this.onResiItems);
        },

        destroy() {
            window.removeEventListener('resi-items-found', this.onResiItems);
        },

        replace(rows) {
            this.rows = (rows ?? []).length ? rows.map(normalize) : [blankRow()];
            this.syncControls();
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

        product(row) {
            return this.products.find((item) => String(item.id) === String(row.product_id));
        },

        /** Sisa stok setelah baris ini diperhitungkan (dipakai form barang keluar). */
        remaining(row) {
            const product = this.product(row);
            if (! product) return null;

            return product.stock - Number(row.quantity || 0);
        },

        isOverStock(row) {
            const remaining = this.remaining(row);

            return remaining !== null && remaining < 0;
        },

        /**
         * Barang yang sama boleh muncul di beberapa baris — server menjumlahkan
         * keduanya — tetapi operator tetap diberi tahu.
         */
        isDuplicate(row) {
            if (! row.product_id) return false;

            return this.rows.filter((other) => String(other.product_id) === String(row.product_id)).length > 1;
        },

        get totalQuantity() {
            return this.sum(() => true);
        },

        get filledRows() {
            return this.rows.filter((row) => row.product_id).length;
        },

        get hasStockProblem() {
            return this.rows.some((row) => this.isOverStock(row));
        },

        /* ---------------------------------------- mode penyesuaian stok */

        /** Saldo tercatat menurut master barang. */
        systemOf(row) {
            return this.product(row)?.stock ?? 0;
        },

        /** Selisih hasil hitung fisik terhadap saldo tercatat. */
        differenceOf(row) {
            if (! row.product_id) return 0;

            return Number(row.actual || 0) - this.systemOf(row);
        },

        get increaseQuantity() {
            return this.rows.reduce((total, row) => total + Math.max(0, this.differenceOf(row)), 0);
        },

        get decreaseQuantity() {
            return this.rows.reduce((total, row) => total + Math.max(0, -this.differenceOf(row)), 0);
        },

        get changedRows() {
            return this.rows.filter((row) => row.product_id && this.differenceOf(row) !== 0).length;
        },

        /**
         * Dipakai form retur. Operator mengisi jumlah rusak dan hilang;
         * sisanya terhadap jumlah pada resi berarti layak jual.
         */
        goodOf(row) {
            return Math.max(0, Number(row.quantity || 0) - Number(row.damaged || 0) - Number(row.missing || 0));
        },

        isOverChecked(row) {
            return Number(row.damaged || 0) + Number(row.missing || 0) > Number(row.quantity || 0);
        },

        get goodQuantity() {
            return this.rows.filter((row) => row.product_id).reduce((total, row) => total + this.goodOf(row), 0);
        },

        get damagedQuantity() {
            return this.total((row) => row.damaged);
        },

        get missingQuantity() {
            return this.total((row) => row.missing);
        },

        total(pick) {
            return this.rows
                .filter((row) => row.product_id)
                .reduce((sum, row) => sum + Number(pick(row) || 0), 0);
        },

        sum(predicate) {
            return this.rows
                .filter((row) => row.product_id && predicate(row))
                .reduce((total, row) => total + Number(row.quantity || 0), 0);
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
        key: `row-${++sequence}`,
        product_id: String(row.product_id ?? ''),
        quantity: Number(row.quantity ?? 1),
        damaged: Number(row.damaged ?? 0),
        missing: Number(row.missing ?? 0),
        actual: Number(row.actual ?? 0),
        note: row.note ?? '',
    };
}
