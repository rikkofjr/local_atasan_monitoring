<?php
defined('MOODLE_INTERNAL') || die();

function get_semua_bawahan_ids($identifier = null, $current_depth = 1) {
    global $DB, $USER;
    static $all_ids = [];

    // Ambil konfigurasi shortname custom profile field (default: atasan_langsung)
    $field_shortname = get_config('local_atasan_monitoring', 'profile_field_shortname');
    if (empty($field_shortname)) {
        $field_shortname = 'atasan_langsung';
    }

    // Ambil konfigurasi metode pencocokan (username / email)
    $matchby = get_config('local_atasan_monitoring', 'matchby');
    if (!$matchby) {
        $matchby = 'username';
    }

    // Ambil konfigurasi batas maksimal kedalaman rekursif
    $max_depth = (int) get_config('local_atasan_monitoring', 'max_depth');

    // Jika batas maksimal telah tercapai, hentikan rekursi lanjutan
    if ($max_depth > 0 && $current_depth > $max_depth) {
        return $all_ids;
    }

    // Jika dipanggil pertama kali tanpa parameter, ambil identifikasi user yang sedang login
    if ($identifier === null) {
        $identifier = ($matchby === 'email') ? $USER->email : $USER->username;
    }

    if (empty($identifier)) {
        return $all_ids;
    }

    // Query dinamis menggunakan shortname dari konfigurasi admin
    $sql = "SELECT u.id, u.username, u.email 
            FROM {user} u
            JOIN {user_info_data} uid ON uid.userid = u.id
            JOIN {user_info_field} uif ON uif.id = uid.fieldid
            WHERE uif.shortname = ? AND uid.data = ? AND u.deleted = 0";
            
    $bawahan = $DB->get_records_sql($sql, [$field_shortname, $identifier]);
    
    foreach ($bawahan as $b) {
        if (!in_array($b->id, $all_ids)) {
            $all_ids[] = $b->id;
            
            // Lanjutkan rekursi ke level berikutnya berdasarkan metode yang dipilih
            $next_identifier = ($matchby === 'email') ? $b->email : $b->username;
            if (!empty($next_identifier)) {
                get_semua_bawahan_ids($next_identifier, $current_depth + 1);
            }
        }
    }
    
    return $all_ids;
}