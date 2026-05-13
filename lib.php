<?php
defined('MOODLE_INTERNAL') || die();

function get_semua_bawahan_ids($atasan_username) {
    global $DB;
    static $all_ids = [];

    // Query untuk mencari user yang custom field 'atasan_langsung' nya berisi username atasan
    $sql = "SELECT u.id, u.username 
            FROM {user} u
            JOIN {user_info_data} uid ON uid.userid = u.id
            JOIN {user_info_field} uif ON uif.id = uid.fieldid
            WHERE uif.shortname = 'atasan_langsung' AND uid.data = ? AND u.deleted = 0";
            
    $bawahan = $DB->get_records_sql($sql, [$atasan_username]);
    
    foreach ($bawahan as $b) {
        if (!in_array($b->id, $all_ids)) {
            $all_ids[] = $b->id;
            get_semua_bawahan_ids($b->username); // Rekursif ke bawah
        }
    }
    
    return $all_ids;
}