/**
 * Pengiriman paket dengan memindai resi.
 *
 * Dipakai di titik serah ke kurir: paket diangkat, resinya discan, layar
 * langsung siap untuk paket berikutnya. Tidak ada tombol yang harus ditekan
 * di antara dua paket, dan halaman tidak pernah dimuat ulang — kalau dimuat
 * ulang, kursor hilang dari kolom input dan operator harus menyentuh layar
 * dengan tangan yang sedang memegang paket.
 *
 * Baris yang sudah dikirim disembunyikan dari daftar di bawahnya, jadi yang
 * terlihat selalu sisa pekerjaan yang sebenarnya.
 */
import { announce as signal, timestamp } from './feedback';
import { cameraState } from './scan-state';

export default function dispatchStation(config) {
    return {
        url: config.url,

        code: '',
        busy: false,
        feedback: null,

        /** Sisa paket di antrean, dihitung ulang oleh server tiap scan. */
        remaining: config.remaining,

        /** Paket yang dikirim pada sesi ini, terbaru di atas. */
        sent: [],
        history: [],

        /** Id paket yang sudah dikirim dari layar ini. */
        sentIds: [],

        /**
         * Daftar dan centangnya ikut diurus komponen ini, bukan komponen
         * sendiri di dalam formnya.
         *
         * Keduanya harus tahu paket mana yang barusan discan — barisnya
         * disembunyikan sekaligus centangnya dilepas, supaya paket yang sudah
         * pergi tidak ikut terkirim lagi oleh tombol borongan. Memisahkannya
         * berarti satu lingkup harus membaca keadaan lingkup lain, dan itu
         * hanya bekerja pada ekspresi di atribut, tidak di dalam getter.
         */
        queued: config.queued ?? [],
        selected: [],

        init() {
            this.focusInput();
        },

        get sessionUnits() {
            return this.sent.reduce((total, entry) => total + entry.units, 0);
        },

        /** Paket yang masih benar-benar menunggu di layar ini. */
        get available() {
            return this.queued.filter((id) => ! this.sentIds.includes(id));
        },

        get allChosen() {
            return this.available.length > 0 && this.selected.length === this.available.length;
        },

        toggleAll() {
            this.selected = this.allChosen ? [] : [...this.available];
        },

        isSent(id) {
            return this.sentIds.includes(id);
        },

        async submit() {
            const code = this.code.trim();
            if (! code || this.busy) return;

            this.busy = true;
            this.feedback = null;

            try {
                const payload = await this.post({ code });

                this.remaining = payload.remaining;
                this.sentIds.push(payload.outbound.id);
                this.selected = this.selected.filter((id) => id !== payload.outbound.id);

                this.sent.unshift({
                    id: payload.outbound.id,
                    code: payload.outbound.code,
                    tracking_number: payload.outbound.tracking_number,
                    marketplace: payload.outbound.marketplace,
                    units: payload.outbound.units,
                    shipped: payload.shipped,
                    at: timestamp(),
                });

                // Bunyi paket lengkap dipakai ulang di sini dengan sengaja:
                // artinya sama bagi operator — satu paket selesai, ambil
                // yang berikutnya.
                this.report('success', payload.message, code, 'complete');
            } catch (error) {
                // Resi yang ditolak berbunyi lain dari galat jaringan: yang
                // pertama berarti "sisihkan paket ini", yang kedua berarti
                // "coba lagi".
                this.report('error', error.message, code, 'rejected');
            } finally {
                this.busy = false;
                this.code = '';
                this.focusInput();
            }
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

            signal(sound ?? (type === 'success' ? 'complete' : 'error'));
        },

        focusInput() {
            // Saat memindai lewat kamera, memfokuskan kolom teks akan
            // memunculkan papan ketik yang menutupi tampilan kamera.
            if (cameraState.open) return;

            this.$nextTick(() => this.$refs.input?.focus());
        },
    };
}
