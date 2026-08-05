/**
 * Pencarian nomor resi ke data import Ginee.
 *
 * Dipakai pada form barang keluar marketplace dan penerimaan retur: begitu
 * nomor resi diketik, isi pesanannya ditarik dari data import supaya baris
 * barang tidak perlu diketik ulang.
 */
export default function resiLookup(config) {
    return {
        url: config.url,
        state: 'idle', // idle | searching | found | missing | error
        order: null,
        message: '',

        /** Dipanggil saat input resi berubah, dengan jeda agar tidak berisik. */
        schedule(code) {
            clearTimeout(this.timer);

            const value = (code ?? '').trim();

            if (value.length < 5) {
                this.reset();
                return;
            }

            this.timer = setTimeout(() => this.search(value), 500);
        },

        async search(code) {
            this.state = 'searching';

            try {
                const response = await fetch(`${this.url}?resi=${encodeURIComponent(code)}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });

                if (response.status === 404) {
                    this.order = null;
                    this.state = 'missing';
                    return;
                }

                if (! response.ok) throw new Error('Gagal menghubungi server.');

                this.order = await response.json();
                this.state = 'found';
            } catch (error) {
                this.order = null;
                this.state = 'error';
                this.message = error.message;
            }
        },

        reset() {
            this.state = 'idle';
            this.order = null;
        },

        get unmatchedSkus() {
            return (this.order?.items ?? []).filter((item) => ! item.matched).map((item) => item.sku);
        },

        /**
         * Salin isi pesanan ke editor baris barang.
         *
         * Editor baris adalah komponen Alpine terpisah, jadi datanya dikirim
         * lewat event pada window.
         */
        apply() {
            if (! this.order?.matched) return;

            window.dispatchEvent(new CustomEvent('resi-items-found', {
                detail: this.order.items.map((item) => ({
                    product_id: String(item.product_id),
                    quantity: item.quantity,
                    damaged: 0,
                    missing: 0,
                    note: item.sku,
                })),
            }));
        },
    };
}
