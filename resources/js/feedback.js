/**
 * Umpan balik non-visual untuk proses scan.
 *
 * Operator gudang memegang scanner sambil melihat barang, bukan layar. Karena
 * itu setiap hasil scan punya bunyi dan getarannya sendiri — dan bunyinya
 * harus bisa dibedakan tanpa melihat: resi terbaca berbeda dari barang
 * terbaca, dan keduanya jelas berbeda dari penolakan.
 *
 * Gelombang persegi dipilih, bukan sinus. Sinus terdengar lembut dan tenggelam
 * di antara suara gudang; persegi punya harmonik yang menembus kebisingan pada
 * volume yang sama.
 */

/** Nada per jenis kejadian, dibunyikan berurutan. */
const TONES = {
    // Resi diterima: dua nada naik — "terbuka".
    resi: [{ hz: 784, ms: 90 }, { hz: 1175, ms: 130 }],

    // Barang terbaca: satu ketuk tinggi dan pendek, supaya sepuluh barang
    // berturut-turut tidak terdengar seperti alarm.
    item: [{ hz: 1568, ms: 70 }],

    // Paket lengkap: tiga nada naik, jelas berbeda dari bunyi per barang.
    complete: [{ hz: 784, ms: 80 }, { hz: 1175, ms: 80 }, { hz: 1568, ms: 180 }],

    // Resi ditolak — sudah discan, dobel, atau stoknya kosong. Dua nada
    // sedang yang berulang: mengganggu, tetapi tidak sekasar bunyi galat.
    rejected: [{ hz: 587, ms: 110 }, { hz: 0, ms: 60 }, { hz: 587, ms: 110 }],

    // Galat: dua nada turun dan rendah.
    error: [{ hz: 262, ms: 140 }, { hz: 175, ms: 220 }],
};

/** Pola getar per jenis kejadian, dalam milidetik. */
const BUZZ = {
    resi: [60],
    item: [35],
    complete: [50, 70, 50],
    rejected: [90, 70, 90],
    error: [120, 80, 120, 80, 120],
};

/** Cukup keras untuk terdengar di gudang, masih di bawah ambang menyakitkan. */
const VOLUME = 0.45;

let context = null;

/**
 * Peramban ponsel menolak membunyikan apa pun sebelum ada sentuhan pengguna.
 * Pemindaian lewat kamera bukan sentuhan, jadi konteks audio harus disiapkan
 * lebih dulu saat tombol kameranya ditekan — kalau tidak, seluruh bunyi hilang
 * tanpa satu pun pesan galat.
 */
export function unlock() {
    try {
        const Context = window.AudioContext || window.webkitAudioContext;
        if (! Context) return;

        context ??= new Context();

        if (context.state === 'suspended') context.resume();
    } catch {
        // Audio diblokir; umpan balik visual dan getar tetap ada.
    }
}

export function beep(kind) {
    const tones = TONES[kind] ?? TONES.item;

    try {
        unlock();
        if (! context) return;

        let at = context.currentTime;

        for (const { hz, ms } of tones) {
            const seconds = ms / 1000;

            // Nada berfrekuensi nol dipakai sebagai jeda antar ketukan.
            if (hz > 0) {
                const oscillator = context.createOscillator();
                const gain = context.createGain();

                oscillator.type = 'square';
                oscillator.frequency.value = hz;

                // Naik-turun singkat supaya tidak ada bunyi "klik" di ujungnya.
                gain.gain.setValueAtTime(0.0001, at);
                gain.gain.exponentialRampToValueAtTime(VOLUME, at + 0.012);
                gain.gain.setValueAtTime(VOLUME, at + seconds - 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, at + seconds);

                oscillator.connect(gain).connect(context.destination);
                oscillator.start(at);
                oscillator.stop(at + seconds);
            }

            at += seconds;
        }
    } catch {
        // Audio diblokir browser: umpan balik visual tetap ada.
    }
}

export function vibrate(kind) {
    navigator.vibrate?.(BUZZ[kind] ?? BUZZ.item);
}

/**
 * @param {'resi'|'item'|'complete'|'rejected'|'error'} kind
 */
export function announce(kind) {
    beep(kind);
    vibrate(kind);
}

export function timestamp() {
    return new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}
