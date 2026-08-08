/**
 * Pemilih barang untuk dokumen pengeluaran stok.
 *
 * Form sebelumnya menuliskan setiap barang bersaldo sebagai satu baris input,
 * sehingga operator harus menggulir seluruh isi gudang hanya untuk mengisi dua
 * atau tiga di antaranya — dan kotak jumlah yang kosong tidak bisa dibedakan
 * dari barang yang memang tidak ikut. Di sini barangnya dicari lebih dulu, dan
 * hanya yang benar-benar dipilih yang muncul sebagai baris.
 *
 * Nama kolomnya tetap quantities[id] persis seperti dulu, jadi yang berubah
 * hanya cara mengisinya, bukan bentuk data yang dikirim.
 */
export default function stockPicker(catalog = [], initial = {}) {
    return {
        catalog,

        /** Baris terpilih: [{ id, quantity }] */
        picked: [],

        term: '',
        highlight: 0,
        open: false,

        /** Baris yang baru saja disentuh, disorot sebentar agar mudah dilihat. */
        flashed: null,
        flashTimer: null,

        init() {
            // Isi ulang setelah validasi gagal: hanya barang yang masih bersaldo.
            this.picked = Object.entries(initial ?? {})
                .map(([id, quantity]) => ({ id: String(id), quantity: Number(quantity) || 0 }))
                .filter((row) => Boolean(this.product(row.id)));
        },

        destroy() {
            clearTimeout(this.flashTimer);
        },

        product(id) {
            return this.catalog.find((item) => String(item.id) === String(id));
        },

        availableOf(id) {
            return Number(this.product(id)?.available ?? 0);
        },

        isPicked(id) {
            return this.picked.some((row) => String(row.id) === String(id));
        },

        /* --------------------------------------------------- pencarian --- */

        get matches() {
            const term = this.term.trim().toLowerCase();

            if (! term) {
                return this.catalog.slice(0, 8);
            }

            return this.catalog
                .map((item) => ({ item, rank: rank(item, term) }))
                .filter((row) => row.rank !== null)
                .sort((a, b) => a.rank - b.rank)
                .slice(0, 8)
                .map((row) => row.item);
        },

        get noMatch() {
            return this.term.trim() !== '' && this.matches.length === 0;
        },

        move(step) {
            const count = this.matches.length;
            if (! count) return;

            this.open = true;
            this.highlight = (this.highlight + step + count) % count;
        },

        search() {
            this.open = true;
            this.highlight = 0;
        },

        /**
         * Enter di kotak pencarian — baik diketik sendiri maupun dikirim
         * pemindai barcode, yang selalu menutup ketikannya dengan Enter.
         */
        pickHighlighted() {
            const match = this.matches[this.highlight] ?? this.matches[0];

            if (match) {
                this.choose(match);
            }
        },

        /**
         * Barang yang sama tidak pernah menjadi dua baris: memilihnya kembali
         * membawa operator ke baris yang sudah ada, sehingga jumlahnya tidak
         * terpecah dan tidak ada yang perlu digabung diam-diam.
         */
        choose(product) {
            if (! this.isPicked(product.id)) {
                this.picked.push({ id: String(product.id), quantity: 1 });
            }

            this.term = '';
            this.highlight = 0;
            this.open = false;

            this.flash(product.id);
            this.$nextTick(() => this.focusQuantity(product.id));
        },

        remove(id) {
            this.picked = this.picked.filter((row) => String(row.id) !== String(id));
        },

        clear() {
            this.picked = [];
        },

        /* ------------------------------------------------------ jumlah --- */

        step(row, delta) {
            const next = Number(row.quantity || 0) + delta;

            row.quantity = Math.min(Math.max(next, 1), this.availableOf(row.id));
        },

        /** Selesai mengisi jumlah, kembali ke pencarian untuk barang berikutnya. */
        backToSearch() {
            this.$refs.search?.focus();
        },

        focusQuantity(id) {
            const input = this.$root.querySelector(`[data-quantity="${id}"]`);

            input?.focus();
            input?.select();
        },

        flash(id) {
            clearTimeout(this.flashTimer);

            this.flashed = String(id);
            this.flashTimer = setTimeout(() => (this.flashed = null), 1200);
        },

        isFlashed(id) {
            return this.flashed === String(id);
        },

        /* ---------------------------------------------------- ringkasan -- */

        isOverBalance(row) {
            return Number(row.quantity || 0) > this.availableOf(row.id);
        },

        isBlank(row) {
            return Number(row.quantity || 0) < 1;
        },

        get totalUnits() {
            return this.picked.reduce((total, row) => total + Math.max(0, Number(row.quantity || 0)), 0);
        },

        get overBalanceCount() {
            return this.picked.filter((row) => this.isOverBalance(row)).length;
        },

        get blankCount() {
            return this.picked.filter((row) => this.isBlank(row)).length;
        },

        get hasProblem() {
            return this.overBalanceCount > 0 || this.blankCount > 0;
        },
    };
}

/**
 * Urutan hasil pencarian. Kecocokan persis didahulukan supaya pemindai barcode
 * — yang langsung menekan Enter — selalu mendapat barang yang benar, bukan
 * barang lain yang kebetulan memuat potongan kodenya.
 *
 * @return {number|null} null berarti tidak cocok sama sekali.
 */
function rank(item, term) {
    const sku = String(item.sku ?? '').toLowerCase();
    const barcode = String(item.barcode ?? '').toLowerCase();
    const name = String(item.name ?? '').toLowerCase();

    if (sku === term || barcode === term) return 0;
    if (sku.startsWith(term)) return 1;
    if (name.startsWith(term)) return 2;
    if (sku.includes(term)) return 3;
    if (name.includes(term)) return 4;
    if (barcode.includes(term)) return 5;

    return null;
}
