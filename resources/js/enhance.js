/**
 * Penyeragaman kontrol form.
 *
 * Seluruh input tanggal memakai flatpickr dan seluruh dropdown memakai
 * Tom Select (pengganti Select2 tanpa jQuery) sehingga bisa dicari.
 *
 * Kontrol dipasang lewat MutationObserver, bukan sekali saat DOMContentLoaded,
 * karena isi halaman ditukar oleh navigasi AJAX dan baris barang dirender
 * ulang oleh Alpine. Instance juga dibongkar saat elemennya dilepas agar
 * tidak menumpuk listener dan kalender yatim di <body>.
 */
import flatpickr from 'flatpickr';
import { Indonesian } from 'flatpickr/dist/l10n/id';
import TomSelect from 'tom-select';

flatpickr.localize(Indonesian);

const DATE_SELECTOR = 'input[type="date"]:not([data-enhanced])';

const RANGE_SELECTOR = '[data-date-range]:not([data-enhanced])';

// Kalender flatpickr merender <select> bulannya sendiri. Bila ikut dibungkus
// Tom Select, tombol pindah bulan berhenti bekerja — jadi harus dikecualikan.
const SELECT_SELECTOR = [
    'select:not([data-enhanced])',
    ':not([data-plain])',
    ':not(.flatpickr-monthDropdown-months)',
].join('');

/** Instance yang sedang hidup, dipakai untuk sinkronisasi dan pembongkaran. */
const instances = new Map();

/* --------------------------------------------------------------- tanggal */

function enhanceDate(input) {
    if (instances.has(input)) return;

    input.dataset.enhanced = 'true';

    const picker = flatpickr(input, {
        dateFormat: 'Y-m-d',
        altInput: true,
        altFormat: 'j F Y',
        allowInput: true,
        disableMobile: true,
        locale: Indonesian,
        onReady(selectedDates, dateStr, instance) {
            // Samakan tampilan dengan input lain di aplikasi.
            instance.altInput.className = input.className;
            // altInput tidak bernama, jadi tandai agar tidak memicu auto-submit.
            instance.altInput.dataset.altInput = 'true';
        },
    });

    instances.set(input, { type: 'date', destroy: () => picker.destroy() });
}

/* --------------------------------------------------------- rentang tanggal */

/**
 * Saringan rentang tanggal dalam satu kolom.
 *
 * Yang terlihat hanyalah cerminan; nilai sebenarnya tetap dikirim sebagai dua
 * parameter, from dan to, lewat kolom tersembunyi — sehingga seluruh controller,
 * tautan pintasan, dan alamat yang pernah disalin orang tetap berlaku.
 */
function enhanceDateRange(container) {
    if (instances.has(container)) return;

    const input = container.querySelector('[data-date-range-input]');
    const from = container.querySelector('[data-date-range-from]');
    const to = container.querySelector('[data-date-range-to]');

    if (! input || ! from || ! to) return;

    container.dataset.enhanced = 'true';

    const clear = container.querySelector('[data-date-range-clear]');
    const defaults = [from.value, to.value].filter(Boolean);

    const picker = flatpickr(input, {
        mode: 'range',
        dateFormat: 'Y-m-d',
        altInput: true,

        // Angka, bukan nama bulan: teks ini harus sama persis dengan yang
        // dirender server sebelum flatpickr mengambil alih, dan menyamakan nama
        // bulan berarti menaruh dua daftar bulan di dua tempat yang bisa
        // menyimpang — Carbon menulis "Agt", flatpickr menulis "Agu".
        altFormat: 'd/m/Y',

        allowInput: false,
        disableMobile: true,

        // Mode rentang menggabungkan kedua tanggal dengan rangeSeparator milik
        // locale, bukan dengan conjunction — jadi pemisahnya diatur di sini.
        locale: { ...Indonesian, rangeSeparator: ' – ' },

        defaultDate: defaults.length ? defaults : null,

        onReady(selectedDates, dateStr, instance) {
            instance.altInput.className = input.className;
            instance.altInput.placeholder = input.placeholder;
            // altInput tidak bernama, jadi tandai agar tidak memicu auto-submit.
            instance.altInput.dataset.altInput = 'true';
            instance.altInput.readOnly = true;
        },

        onChange(selectedDates, dateStr, instance) {
            // Rentang setengah jadi dibiarkan apa adanya.
            //
            // Klik pertama belum boleh menyaring — halamannya akan dimuat ulang
            // dan kalendernya keburu tertutup sebelum ujung kedua sempat
            // dipilih. Kolom tersembunyinya pun tidak disentuh, supaya
            // pemilihan yang ditinggalkan di tengah tidak diam-diam mengubah
            // saringan yang sedang berlaku.
            if (selectedDates.length === 1) return;

            const [start, end] = selectedDates;

            from.value = start ? instance.formatDate(start, 'Y-m-d') : '';
            to.value = end ? instance.formatDate(end, 'Y-m-d') : '';

            clear?.classList.toggle('hidden', selectedDates.length === 0);

            submitFilter(container);
        },
    });

    const reset = (event) => {
        event.preventDefault();

        // Tanpa false, clear() memancarkan onChange sendiri dan halamannya
        // dikirim ulang dua kali untuk satu ketukan.
        picker.clear(false);

        from.value = '';
        to.value = '';
        clear?.classList.add('hidden');

        submitFilter(container);
    };

    clear?.addEventListener('click', reset);

    instances.set(container, {
        type: 'range',
        destroy: () => {
            clear?.removeEventListener('click', reset);
            picker.destroy();
        },
    });
}

/**
 * Kirim ulang formnya bila memang halaman yang menyaring sendiri.
 *
 * Nilai ditulis langsung ke kolom tersembunyi, dan kolom tersembunyi tidak
 * pernah memancarkan event input — jadi auto-submit bawaan tidak akan
 * menyadarinya. Halaman yang mengandalkan tombol Terapkan dibiarkan apa adanya.
 */
function submitFilter(container) {
    const form = container.closest('form[data-auto-submit]');

    form?.requestSubmit();
}

/* ---------------------------------------------------------------- select */

function enhanceSelect(select) {
    if (instances.has(select)) return;

    // Penjagaan kedua: apa pun yang berada di dalam kalender dibiarkan apa adanya.
    if (select.closest('.flatpickr-calendar')) return;

    select.dataset.enhanced = 'true';

    const tom = new TomSelect(select, {
        create: false,
        allowEmptyOption: true,
        maxOptions: 500,
        // Kotak pencarian selalu tersedia, termasuk pada dropdown pendek.
        searchField: ['text', 'value'],
        // Ditempel ke <body> supaya tidak terpotong kartu ber-overflow-hidden.
        dropdownParent: 'body',
        placeholder: select.dataset.placeholder
            ?? select.querySelector('option[value=""]')?.textContent?.trim(),
        render: {
            no_results: () => '<div class="ts-empty">Tidak ada hasil</div>',
        },
    });

    instances.set(select, {
        type: 'select',
        // Dipakai saat Alpine mengubah nilai select secara langsung.
        sync: () => {
            const value = select.value ?? '';

            if (tom.getValue() !== value) {
                tom.setValue(value, true);
            }
        },
        refreshOptions: () => {
            tom.clearOptions();
            tom.sync();
        },
        destroy: () => tom.destroy(),
    });
}

/* ------------------------------------------------------------- siklus DOM */

function enhance(root = document) {
    if (! root.querySelectorAll) return;

    if (root.matches?.(RANGE_SELECTOR)) enhanceDateRange(root);
    else if (root.matches?.(DATE_SELECTOR)) enhanceDate(root);
    else if (root.matches?.(SELECT_SELECTOR)) enhanceSelect(root);

    // Rentang lebih dulu: kolom di dalamnya diurus flatpickr sendiri dan tidak
    // boleh ikut dipasangi kontrol kedua.
    root.querySelectorAll(RANGE_SELECTOR).forEach(enhanceDateRange);
    root.querySelectorAll(DATE_SELECTOR).forEach(enhanceDate);
    root.querySelectorAll(SELECT_SELECTOR).forEach(enhanceSelect);
}

/** Bongkar instance milik elemen yang sudah tidak ada di dokumen. */
function cleanup() {
    for (const [element, instance] of instances) {
        if (element.isConnected) continue;

        try {
            instance.destroy();
        } catch {
            // Elemen sudah hilang duluan, tidak ada yang perlu dibersihkan.
        }

        instances.delete(element);
    }
}

/**
 * Samakan tampilan dropdown dengan nilai select-nya.
 *
 * Alpine menulis langsung ke `select.value` tanpa memicu event apa pun, jadi
 * komponen yang mengubah nilai secara terprogram memberi tahu lewat event ini.
 */
function sync() {
    for (const instance of instances.values()) {
        instance.sync?.();
    }
}

const observer = new MutationObserver((mutations) => {
    let removed = false;

    for (const mutation of mutations) {
        for (const node of mutation.addedNodes) {
            if (node.nodeType === Node.ELEMENT_NODE) enhance(node);
        }

        if (mutation.removedNodes.length) removed = true;
    }

    if (removed) cleanup();
});

document.addEventListener('DOMContentLoaded', () => {
    enhance();
    observer.observe(document.body, { childList: true, subtree: true });
});

window.addEventListener('form:sync', sync);

// Setelah halaman ditukar, sisa instance dari halaman lama ikut dibersihkan.
window.addEventListener('page-navigated', () => {
    cleanup();
    enhance();
});
