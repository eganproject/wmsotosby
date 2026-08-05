/**
 * Stasiun retur marketplace.
 *
 * Satu layar dipakai berulang tanpa berpindah halaman:
 *
 *   scan resi → periksa isi paket → terima → menunggu resi berikutnya
 *
 * Resi yang belum pernah diimport tidak menghentikan alur. Dokumennya
 * langsung dibentuk begitu barang pertama discan, lalu barang berikutnya
 * ditambahkan ke dokumen yang sama — tidak ada tahap perantara yang harus
 * ditekan lebih dulu.
 *
 * Pemeriksaan memakai tiga angka per baris — layak jual, rusak, dan hilang.
 * Yang diisi operator hanya rusak dan hilang; layak jual dihitung dari
 * sisanya terhadap jumlah yang tertulis pada resi.
 */
import { announce as signal, timestamp } from './feedback';

export default function returnStation(config) {
    return {
        urls: config.urls,
        canFinish: config.canFinish,

        stage: 'resi', // resi | collect | review | finishing
        code: '',
        busy: false,
        feedback: null,

        document: null,
        items: [],
        reason: '',

        /** Resi yang isinya diinput sendiri, bukan dari data import. */
        manualEntry: false,
        pendingTracking: '',

        completed: [],
        history: [],
        autoContinue: true,

        init() {
            this.focusInput();
        },

        get isResiStage() {
            return this.stage === 'resi';
        },

        /** Menunggu barang pertama discan; dokumennya belum ada. */
        get isCollectStage() {
            return this.stage === 'collect';
        },

        get isReviewStage() {
            return this.stage === 'review';
        },

        /* ------------------------------------------------------ tampilan */

        get step() {
            return { resi: 1, collect: 1, review: 2, finishing: 3 }[this.stage];
        },

        get title() {
            return {
                resi: 'Scan resi paket retur',
                collect: 'Scan barang di dalam paket',
                review: this.manualEntry ? 'Scan & periksa barang' : 'Periksa kondisi barang',
                finishing: 'Retur diterima',
            }[this.stage];
        },

        get hint() {
            return {
                resi: 'Arahkan scanner ke label resi pada paket yang dikembalikan.',
                collect: 'Resi tidak ada di data import. Scan barang pertama — dokumennya langsung dibuat.',
                review: this.manualEntry
                    ? 'Scan barang berikutnya, atau tekan Enter pada kolom kosong untuk menerima.'
                    : 'Isi jumlah rusak dan hilang bila ada. Sisanya dihitung layak jual.',
                finishing: this.autoContinue ? 'Bersiap untuk resi berikutnya…' : 'Tekan Lanjut untuk paket berikutnya.',
            }[this.stage];
        },

        get placeholder() {
            return {
                resi: 'Scan atau ketik nomor resi retur…',
                collect: 'Scan barcode atau ketik SKU barang…',
                review: this.manualEntry
                    ? 'Scan barang lain, atau Enter untuk menerima…'
                    : 'Tekan Enter untuk menerima paket ini…',
                finishing: '',
            }[this.stage];
        },

        get actionLabel() {
            return { resi: 'Cari', collect: 'Tambah', review: this.manualEntry ? 'Tambah' : 'Terima', finishing: '' }[this.stage];
        },

        get canReceive() {
            return this.items.length > 0 && ! this.hasCheckProblem;
        },

        /* ------------------------------------------------ aksi cepat baris */

        markIntact(item) {
            item.damaged = 0;
            item.missing = 0;
        },

        markAllDamaged(item) {
            item.damaged = item.quantity;
            item.missing = 0;
        },

        markAllMissing(item) {
            item.missing = item.quantity;
            item.damaged = 0;
        },

        markEverythingIntact() {
            this.items.forEach((item) => this.markIntact(item));
        },

        /* --------------------------------------------------- perhitungan */

        goodOf(item) {
            return Math.max(0, item.quantity - Number(item.damaged || 0) - Number(item.missing || 0));
        },

        isOverChecked(item) {
            return Number(item.damaged || 0) + Number(item.missing || 0) > item.quantity;
        },

        get hasCheckProblem() {
            return this.items.some((item) => this.isOverChecked(item));
        },

        get totalUnits() {
            return this.items.reduce((total, item) => total + item.quantity, 0);
        },

        get goodUnits() {
            return this.items.reduce((total, item) => total + this.goodOf(item), 0);
        },

        get damagedUnits() {
            return this.items.reduce((total, item) => total + Number(item.damaged || 0), 0);
        },

        get missingUnits() {
            return this.items.reduce((total, item) => total + Number(item.missing || 0), 0);
        },

        get totalGood() {
            return this.completed.reduce((total, entry) => total + entry.good, 0);
        },

        get totalDamaged() {
            return this.completed.reduce((total, entry) => total + entry.damaged, 0);
        },

        get totalMissing() {
            return this.completed.reduce((total, entry) => total + entry.missing, 0);
        },

        /* ------------------------------------------------------- scanning */

        /**
         * Kolom input menyesuaikan tahap: memindai resi, memindai barang,
         * dan — saat kosong pada tahap periksa — menerima paketnya.
         */
        async submit() {
            if (this.busy || this.stage === 'finishing') return;

            const code = this.code.trim();

            if (! code) {
                if (this.isReviewStage) await this.finish();

                return;
            }

            this.busy = true;
            this.feedback = null;

            try {
                if (this.isResiStage) await this.startPackage(code);
                else await this.scanItem(code);
            } catch (error) {
                this.report('error', error.message, code);
            } finally {
                this.busy = false;
                this.code = '';
                this.focusInput();
            }
        },

        async startPackage(code) {
            const payload = await this.post(this.urls.start, { code });

            // Resi belum diimport: isinya diinput sendiri, mulai dari barang pertama.
            if (! payload.found) {
                this.manualEntry = true;
                this.pendingTracking = payload.tracking_number;
                this.stage = 'collect';
                this.report('error', payload.message, code);

                return;
            }

            this.manualEntry = false;
            this.adopt(payload);
            this.report('success', `${payload.return.code} — periksa ${payload.items.length} baris barang.`, code);
        },

        /**
         * Scan barang pada retur manual. Panggilan pertama sekaligus membuat
         * dokumennya, sisanya menambah ke dokumen yang sama.
         */
        async scanItem(code) {
            if (! this.manualEntry) {
                this.report('error', 'Isi paket sudah ditentukan resi. Kosongkan kolom lalu tekan Enter untuk menerima.', code);

                return;
            }

            const payload = this.document
                ? await this.post(this.urls.item, { code })
                : await this.post(this.urls.manual, { tracking_number: this.pendingTracking, code });

            // Hasil pemeriksaan yang sudah diketik tidak ikut tertimpa.
            this.adopt(payload, true);

            const scanned = payload.scanned;

            this.report('success', `${scanned.name} — ${scanned.quantity} ${scanned.unit}`, code);
        },

        async removeItem(item) {
            if (this.busy) return;

            this.busy = true;

            try {
                this.adopt(await this.request(item.remove_url, 'DELETE'), true);

                if (! this.items.length) {
                    this.report('success', 'Baris terakhir dihapus. Scan barang lagi untuk melanjutkan.', '');
                }
            } catch (error) {
                this.report('error', error.message, '');
            } finally {
                this.busy = false;
                this.focusInput();
            }
        },

        /** Pindah ke tahap periksa dengan isi dari server. */
        adopt(payload, preserve = false) {
            const previous = preserve ? new Map(this.items.map((item) => [item.id, item])) : new Map();

            this.items = payload.items.map((item) => {
                const old = previous.get(item.id);

                return {
                    ...item,
                    damaged: old ? old.damaged : (item.damaged ?? 0),
                    missing: old
                        ? old.missing
                        : Math.max(0, item.quantity - (item.good ?? item.quantity) - (item.damaged ?? 0)),
                };
            });

            this.document = payload.return;
            this.urls = { ...this.urls, ...payload.urls };
            this.pendingTracking = '';

            if (! preserve) this.reason = '';

            this.stage = 'review';
        },

        /* -------------------------------------------------- penyelesaian */

        async finish() {
            if (! this.items.length) {
                this.feedback = { type: 'error', message: 'Belum ada barang pada paket ini.' };
                signal('error');

                return;
            }

            if (this.hasCheckProblem) {
                this.feedback = { type: 'error', message: 'Rusak + hilang melebihi jumlah pada resi. Perbaiki dulu.' };
                signal('error');

                return;
            }

            if (! this.canFinish) {
                this.feedback = {
                    type: 'success',
                    message: 'Isi paket sudah dicatat. Minta petugas berwenang untuk menerimanya.',
                };

                return;
            }

            this.stage = 'finishing';
            this.busy = true;

            try {
                const payload = await this.post(this.urls.finish, {
                    reason: this.reason || null,
                    items: Object.fromEntries(this.items.map((item) => [
                        item.id,
                        {
                            good: this.goodOf(item),
                            damaged: Number(item.damaged || 0),
                            // Pada retur manual jumlah seharusnya ditetapkan
                            // operator; server mengabaikannya bila resinya
                            // berasal dari data import.
                            expected: Number(item.quantity || 0),
                        },
                    ])),
                });

                this.completed.unshift({
                    id: Date.now(),
                    code: payload.code,
                    tracking_number: payload.tracking_number,
                    good: payload.good,
                    damaged: payload.damaged,
                    missing: payload.missing,
                    at: timestamp(),
                });

                this.feedback = { type: 'success', message: payload.message };
                signal('success');

                this.autoContinue ? setTimeout(() => this.reset(), 1400) : (this.stage = 'review');
            } catch (error) {
                this.stage = 'review';
                this.report('error', error.message, '');
            } finally {
                this.busy = false;
                this.focusInput();
            }
        },

        reset() {
            this.stage = 'resi';
            this.document = null;
            this.items = [];
            this.manualEntry = false;
            this.pendingTracking = '';
            this.reason = '';
            this.code = '';
            this.focusInput();
        },

        /* ------------------------------------------------------- bantuan */

        post(url, body) {
            return this.request(url, 'POST', body);
        },

        async request(url, method, body = null) {
            const response = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: body === null ? null : JSON.stringify(body),
            });

            const payload = await response.json().catch(() => ({}));

            if (! response.ok) {
                throw new Error(payload.errors?.code?.[0] ?? payload.message ?? 'Permintaan gagal diproses.');
            }

            return payload;
        },

        report(type, message, code) {
            this.feedback = { type, message };

            if (code) {
                this.history.unshift({ id: Date.now(), type, message, code, at: timestamp() });
                this.history = this.history.slice(0, 6);
            }

            signal(type);
        },

        focusInput() {
            this.$nextTick(() => this.$refs.input?.focus());
        },
    };
}
