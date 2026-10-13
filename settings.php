<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_atasan_monitoring', get_string('pluginname', 'local_atasan_monitoring'));
    
    // PENGATURAN BARU: Shortname Custom Profile Field Atasan
    $settings->add(new admin_setting_configtext(
        'local_atasan_monitoring/profile_field_shortname',
        'Shortname Field Profile Atasan',
        'Masukkan shortname custom profile field yang digunakan untuk relasi atasan (Contoh: atasan_langsung, manager, supervisor).',
        'atasan_langsung', // Default value
        PARAM_RAW_TRIMMED
    ));

    // Pilihan metode pencocokan (Username atau Email)
    $settings->add(new admin_setting_configselect(
        'local_atasan_monitoring/matchby',
        'Metode Pencocokan Atasan',
        'Pilih apakah nilai pada profile field tersebut diisi dengan Username atau Email akun atasan.',
        'username',
        array(
            'username' => 'Username Atasan',
            'email'    => 'Email Atasan',
            'idnumber'    => 'idnumber Atasan'
        )
    ));

    // Batas Maksimal Kedalaman Rekursif (Max Depth)
    $settings->add(new admin_setting_configtext(
        'local_atasan_monitoring/max_depth',
        'Maksimal Level Rekursif Bawahan',
        'Batasi seberapa jauh hierarki bawahan yang ditarik (misal: 2 atau 3 level ke bawah). Kosongkan atau isi 0 jika ingin tanpa batas.',
        3,
        PARAM_INT
    ));
    
    $ADMIN->add('localplugins', $settings);
}