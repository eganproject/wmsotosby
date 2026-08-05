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

    if (root.matches?.(DATE_SELECTOR)) enhanceDate(root);
    else if (root.matches?.(SELECT_SELECTOR)) enhanceSelect(root);

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
