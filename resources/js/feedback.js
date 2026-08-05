/**
 * Umpan balik non-visual untuk proses scan.
 *
 * Operator gudang memegang scanner sambil melihat barang, bukan layar, jadi
 * setiap hasil scan juga ditandai dengan nada pendek dan getaran.
 */
let context = null;

export function beep(type) {
    try {
        const Context = window.AudioContext || window.webkitAudioContext;
        if (! Context) return;

        context ??= new Context();

        const oscillator = context.createOscillator();
        const gain = context.createGain();

        oscillator.type = 'sine';
        oscillator.frequency.value = type === 'success' ? 880 : 220;
        gain.gain.setValueAtTime(0.08, context.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.18);

        oscillator.connect(gain).connect(context.destination);
        oscillator.start();
        oscillator.stop(context.currentTime + 0.18);
    } catch {
        // Audio diblokir browser: umpan balik visual tetap ada.
    }
}

export function vibrate(type) {
    if (navigator.vibrate) navigator.vibrate(type === 'success' ? 40 : [60, 50, 60]);
}

export function announce(type) {
    beep(type);
    vibrate(type);
}

export function timestamp() {
    return new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}
