/**
 * Navigasi AJAX.
 *
 * Setiap link internal dan form dikirim lewat fetch, lalu hanya bagian konten
 * halaman yang ditukar. Server mengirim potongan halaman (bukan dokumen penuh)
 * ketika menerima header X-Page-Fragment.
 */

const FRAGMENT_HEADER = 'X-Page-Fragment';
const DIM_DELAY = 250;

let request = null;
let dimTimer = null;

/* -------------------------------------------------------------- indikator */

const progress = {
    node: null,
    timer: null,
    value: 0,

    element() {
        return (this.node ??= document.getElementById('page-progress'));
    },

    start() {
        const bar = this.element();
        if (!bar) return;

        clearInterval(this.timer);
        this.value = 10;
        bar.style.opacity = '1';
        bar.style.width = '10%';

        // Merangkak maju supaya terasa hidup tanpa pernah menyentuh 100%.
        this.timer = setInterval(() => {
            this.value = Math.min(this.value + Math.max(0.5, (92 - this.value) / 14), 92);
            bar.style.width = `${this.value}%`;
        }, 160);
    },

    done() {
        const bar = this.element();
        if (!bar) return;

        clearInterval(this.timer);
        bar.style.width = '100%';

        setTimeout(() => {
            bar.style.opacity = '0';
            setTimeout(() => (bar.style.width = '0%'), 250);
        }, 200);
    },
};

function startLoading(source) {
    progress.start();

    if (source) {
        source.dataset.busy = 'true';
        if (source.tagName === 'BUTTON') source.disabled = true;
    }

    clearTimeout(dimTimer);
    dimTimer = setTimeout(() => document.documentElement.setAttribute('data-navigating', 'true'), DIM_DELAY);
}

function stopLoading(source) {
    progress.done();

    if (source) {
        delete source.dataset.busy;
        if (source.tagName === 'BUTTON') source.disabled = false;
    }

    clearTimeout(dimTimer);
    document.documentElement.removeAttribute('data-navigating');
}

/* ------------------------------------------------------------------ utils */

function content() {
    return document.getElementById('page-content');
}

function isInternal(url) {
    try {
        return new URL(url, location.href).origin === location.origin;
    } catch {
        return false;
    }
}

/** Simpan posisi kursor agar pencarian langsung tidak kehilangan fokus. */
function captureFocus() {
    const active = document.activeElement;
    if (!active || !content()?.contains(active)) return null;

    const state = { id: active.id, name: active.getAttribute('name'), start: null, end: null };

    try {
        state.start = active.selectionStart;
        state.end = active.selectionEnd;
    } catch {
        // Beberapa tipe input tidak mendukung selection range.
    }

    return state;
}

function restoreFocus(state) {
    if (!state) return;

    const scope = content();
    const target =
        (state.id && scope.querySelector(`#${CSS.escape(state.id)}`)) ||
        (state.name && scope.querySelector(`[name="${CSS.escape(state.name)}"]`));

    if (!target) return;

    target.focus({ preventScroll: true });

    try {
        if (state.start !== null) target.setSelectionRange(state.start, state.end);
    } catch {
        // Abaikan input yang tidak mendukung setSelectionRange.
    }
}

/* ------------------------------------------------------------------- swap */

function swap(html, { scroll }) {
    const incoming = new DOMParser().parseFromString(html, 'text/html');
    const fragment = incoming.getElementById('page-fragment');
    const region = content();

    // Bukan potongan halaman (mis. sesi habis lalu diarahkan ke login).
    if (!fragment || !region) return false;

    const focus = captureFocus();

    document.title = fragment.dataset.title ?? document.title;

    const heading = document.getElementById('page-heading');
    if (heading && fragment.dataset.heading) heading.textContent = fragment.dataset.heading;

    region.innerHTML = fragment.querySelector('[data-fragment="content"]').innerHTML;
    region.classList.remove('animate-fade-in');
    void region.offsetWidth; // paksa restart animasi
    region.classList.add('animate-fade-in');

    const nav = fragment.querySelector('[data-fragment="nav"]');
    if (nav) {
        document.querySelectorAll('[data-sidebar-nav]').forEach((el) => {
            el.outerHTML = nav.content.firstElementChild.outerHTML;
        });
    }

    // Bilah navigasi ponsel ikut ditukar supaya tab aktifnya tidak basi.
    const bottomNav = fragment.querySelector('[data-fragment="bottom-nav"]');
    if (bottomNav) {
        document.querySelectorAll('[data-bottom-nav]').forEach((el) => {
            el.outerHTML = bottomNav.content.firstElementChild.outerHTML;
        });
    }

    const flash = fragment.querySelector('[data-fragment="flash"]');
    const flashSlot = document.getElementById('flash-slot');
    if (flash && flashSlot) flashSlot.innerHTML = flash.innerHTML;

    if (scroll) {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } else {
        restoreFocus(focus);
    }

    window.dispatchEvent(new CustomEvent('page-navigated'));

    return true;
}

/* ------------------------------------------------------------------ visit */

async function visit(url, { method = 'GET', body = null, push = true, scroll = true, source = null } = {}) {
    request?.abort();
    request = new AbortController();

    startLoading(source);

    try {
        const response = await fetch(url, {
            method,
            body,
            signal: request.signal,
            credentials: 'same-origin',
            headers: {
                [FRAGMENT_HEADER]: '1',
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'text/html',
            },
        });

        // Sesi atau token kedaluwarsa: muat ulang penuh agar halaman kembali bersih.
        if (response.status === 419 || response.status === 401) {
            window.location.href = response.url;
            return;
        }

        const swapped = swap(await response.text(), { scroll });

        if (!swapped) {
            window.location.href = response.url;
            return;
        }

        if (push) {
            const target = response.url;
            if (target !== location.href) history.pushState({ ajax: true }, '', target);
            else history.replaceState({ ajax: true }, '', target);
        }
    } catch (error) {
        if (error.name === 'AbortError') return;

        // Jaringan bermasalah: serahkan ke navigasi browser biasa.
        window.location.href = url;
    } finally {
        request = null;
        stopLoading(source);
    }
}

/* --------------------------------------------------------------- handlers */

function interceptableLink(link) {
    if (!link || link.hasAttribute('download') || link.dataset.noAjax !== undefined) return false;
    if (link.target && link.target !== '_self') return false;

    const href = link.getAttribute('href');
    if (!href || href.startsWith('#') || /^(mailto|tel|javascript):/i.test(href)) return false;

    return isInternal(link.href);
}

document.addEventListener('click', (event) => {
    // Halaman tamu (login) tidak punya area swap, biarkan browser menanganinya.
    if (!content()) return;
    if (event.defaultPrevented || event.button !== 0) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    const link = event.target.closest('a');
    if (!interceptableLink(link)) return;

    event.preventDefault();
    visit(link.href, { source: link });
});

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!content()) return;
    if (event.defaultPrevented || form.dataset.noAjax !== undefined) return;
    if (!isInternal(form.action)) return;

    event.preventDefault();

    const source = event.submitter;
    const method = (form.method || 'get').toUpperCase();

    if (method === 'GET') {
        const query = new URLSearchParams(new FormData(form));

        // Buang filter kosong supaya URL tetap rapi.
        [...query.entries()].forEach(([key, value]) => value === '' && query.delete(key));

        const url = new URL(form.action, location.href);
        url.search = query.toString();

        visit(url.toString(), { source, scroll: false });
        return;
    }

    const body = new FormData(form);
    if (source?.name) body.append(source.name, source.value);

    visit(form.action, { method: 'POST', body, source });
});

/** Pencarian & filter langsung mengirim ulang tanpa menunggu tombol ditekan. */
let autoSubmitTimer = null;

document.addEventListener('input', (event) => {
    const form = event.target.closest('form[data-auto-submit]');
    if (!form || event.target.type === 'submit') return;

    // Input bantu flatpickr tidak bernama, jadi tidak mengubah hasil filter.
    if (!event.target.name || event.target.dataset.altInput) return;

    clearTimeout(autoSubmitTimer);
    autoSubmitTimer = setTimeout(() => form.requestSubmit(), 400);
});

document.addEventListener('change', (event) => {
    const form = event.target.closest('form[data-auto-submit]');
    if (!form || event.target.tagName !== 'SELECT') return;

    clearTimeout(autoSubmitTimer);
    form.requestSubmit();
});

window.addEventListener('popstate', () => {
    visit(location.href, { push: false });
});

// Dipakai komponen lain (mis. intake marketplace) untuk berpindah halaman
// tanpa memuat ulang dokumen.
window.wmsNavigate = (url) => visit(url);

// Tandai entri riwayat pertama agar tombol kembali tetap konsisten.
history.replaceState({ ajax: true }, '', location.href);
