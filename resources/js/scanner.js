/**
 * Panel verifikasi scan untuk dokumen gudang.
 *
 * Dipakai dua tempat dengan tahapan berbeda:
 *  - Barang keluar marketplace: scan resi lalu scan barang.
 *  - Penerimaan retur: cukup scan resi (config.urls.item tidak diisi).
 *
 * Input selalu difokuskan ulang supaya barcode scanner (yang berperilaku
 * seperti keyboard lalu menekan Enter) bisa dipakai beruntun tanpa mouse.
 */
import { announce as signal, timestamp } from './feedback';
import { cameraState } from './scan-state';

export default function documentScanner(config) {
    return {
        urls: config.urls,
        progress: config.progress,
        history: [],
        code: '',
        busy: false,
        feedback: null, // { type: 'success' | 'error', message: string }

        init() {
            this.focusInput();
        },

        get stage() {
            if (! this.progress.resi_verified) return 'resi';

            return this.urls.item ? 'item' : 'done';
        },

        get isResiStage() {
            return this.stage === 'resi';
        },

        get isDone() {
            return this.stage === 'done';
        },

        itemProgress(id) {
            return this.progress.items.find((item) => item.id === id) ?? { scanned: 0, quantity: 0, completed: false };
        },

        async submit() {
            const code = this.code.trim();
            if (! code || this.busy || this.isDone) return;

            this.busy = true;
            this.feedback = null;

            try {
                const response = await fetch(this.isResiStage ? this.urls.resi : this.urls.item, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ code }),
                });

                const payload = await response.json().catch(() => ({}));

                if (! response.ok) {
                    // 422 membawa pesan validasi dari server.
                    throw new Error(payload.errors?.code?.[0] ?? payload.message ?? 'Scan gagal diproses.');
                }

                const wasResi = this.isResiStage;

                this.progress = payload.progress;
                this.announce('success', payload.message, code, wasResi ? 'resi' : 'item');
            } catch (error) {
                this.announce('error', error.message, code, this.isResiStage ? 'rejected' : 'error');
            } finally {
                this.busy = false;
                this.code = '';
                this.focusInput();
            }
        },

        announce(type, message, code, sound = null) {
            this.feedback = { type, message };
            this.history.unshift({ id: Date.now(), type, message, code, at: timestamp() });
            this.history = this.history.slice(0, 8);

            signal(sound ?? (type === 'success' ? 'item' : 'error'));
        },

        focusInput() {
            // Saat memindai lewat kamera, memfokuskan kolom teks akan
            // memunculkan papan ketik yang menutupi tampilan kamera.
            if (cameraState.open) return;

            this.$nextTick(() => this.$refs.input?.focus());
        },
    };
}
