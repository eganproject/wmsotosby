/**
 * Keadaan pemindai yang dipakai bersama.
 *
 * Stasiun scan selalu mengembalikan kursor ke kolom kode setiap selesai
 * memindai — benar saat memakai scanner genggam, tetapi merusak saat
 * memakai kamera: memfokuskan kolom teks memunculkan papan ketik ponsel
 * yang menutupi tampilan kamera, sehingga operator harus menutupnya dulu
 * sebelum bisa memindai berikutnya.
 *
 * Satu penanda kecil ini yang membuat kedua cara pindai bisa hidup
 * berdampingan tanpa saling mengganggu.
 */
export const cameraState = {
    open: false,
};
