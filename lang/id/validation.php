<?php

/*
 * Hanya berisi aturan validasi yang dipakai aplikasi ini.
 * Aturan lain otomatis memakai terjemahan bawaan (bahasa Inggris).
 */
return [
    'boolean' => 'Kolom :attribute harus bernilai benar atau salah.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'current_password' => 'Kata sandi yang dimasukkan salah.',
    'email' => 'Kolom :attribute harus berupa alamat email yang valid.',
    'exists' => ':attribute yang dipilih tidak valid.',
    'integer' => 'Kolom :attribute harus berupa angka.',
    'lowercase' => 'Kolom :attribute harus menggunakan huruf kecil.',
    'max' => [
        'array' => 'Kolom :attribute maksimal berisi :max item.',
        'file' => 'Ukuran :attribute maksimal :max kilobita.',
        'numeric' => 'Nilai :attribute maksimal :max.',
        'string' => 'Kolom :attribute maksimal :max karakter.',
    ],
    'min' => [
        'array' => 'Kolom :attribute minimal berisi :min item.',
        'file' => 'Ukuran :attribute minimal :min kilobita.',
        'numeric' => 'Nilai :attribute minimal :min.',
        'string' => 'Kolom :attribute minimal :min karakter.',
    ],
    'numeric' => 'Kolom :attribute harus berupa angka.',
    'required' => 'Kolom :attribute wajib diisi.',
    'string' => 'Kolom :attribute harus berupa teks.',
    'unique' => ':attribute sudah digunakan.',

    'password' => [
        'letters' => 'Kolom :attribute harus mengandung minimal satu huruf.',
        'mixed' => 'Kolom :attribute harus mengandung huruf besar dan huruf kecil.',
        'numbers' => 'Kolom :attribute harus mengandung minimal satu angka.',
        'symbols' => 'Kolom :attribute harus mengandung minimal satu simbol.',
        'uncompromised' => ':attribute yang dimasukkan pernah bocor dalam kebocoran data. Silakan pilih yang lain.',
    ],

    'attributes' => [
        'name' => 'nama',
        'email' => 'email',
        'phone' => 'nomor telepon',
        'password' => 'kata sandi',
        'password_confirmation' => 'konfirmasi kata sandi',
        'current_password' => 'kata sandi saat ini',
        'role_id' => 'role',
        'slug' => 'slug',
        'description' => 'deskripsi',
        'permissions' => 'hak akses',
    ],
];
