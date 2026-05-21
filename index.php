<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');
require_once($CFG->libdir . '/dmllib.php');
require_once(__DIR__ . '/lib.php');

require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/atasan_monitoring/index.php'));
$PAGE->set_title(get_string('monitoring_title', 'local_atasan_monitoring'));
$PAGE->set_heading(get_string('monitoring_title', 'local_atasan_monitoring'));

// 1. Tangkap Parameter Pencarian
$s_nik    = optional_param('s_nik', '', PARAM_TEXT);
$s_course = optional_param('s_course', 0, PARAM_INT); 
$s_status = optional_param('s_status', '', PARAM_ALPHA); 

echo $OUTPUT->header();

global $DB;

// Ambil daftar kursus yang berstatus PUBLISH (visible = 1) untuk Dropdown Filter Kursus
$all_courses = $DB->get_records_menu('course', array('visible' => 1), 'fullname ASC', 'id, fullname');

// 2. AMBIL DATA BAWAHAN SECARA REKURSIF
$bawahan_ids = get_semua_bawahan_ids($USER->username);

if (empty($bawahan_ids)) {
    echo $OUTPUT->notification(get_string('no_subordinates', 'local_atasan_monitoring'), 'info');
    echo $OUTPUT->footer();
    exit;
}

// Ambil data untuk dropdown select NIK Autocomplete
list($subsql, $subparams) = $DB->get_in_or_equal($bawahan_ids, SQL_PARAMS_NAMED, 'sub');
$user_sql = "SELECT id, username, firstname, lastname 
             FROM {user} 
             WHERE id $subsql AND deleted = 0 
             ORDER BY username ASC";
$subordinate_users = $DB->get_records_sql($user_sql, $subparams);

// 3. Form Pencarian dengan Desain Grid Sistem Berpasangan
echo '<div class="card mb-4"><div class="card-body">';
echo '<form action="' . $PAGE->url . '" method="get">';
echo '<div class="row align-items-end">'; // Menggunakan Grid Row Bootstrap

// Kolom 1: Autocomplete NIK / Nama Karyawan (Khusus Bawahan)
echo '<div class="col-md-3 mb-3">';
echo '<label for="id_s_nik" class="form-label font-weight-bold">NIK / Nama Karyawan</label>';
echo '<select name="s_nik" id="id_s_nik" class="form-control custom-select w-100">';
echo '<option value="">-- Semua Bawahan --</option>';

if (!empty($subordinate_users)) {
    foreach ($subordinate_users as $sub_user) {
        $display_label = $sub_user->username . ' - ' . $sub_user->firstname . ' ' . $sub_user->lastname;
        $selected = ($s_nik === $sub_user->username) ? 'selected' : '';
        echo '<option value="' . s($sub_user->username) . '" ' . $selected . '>' . s($display_label) . '</option>';
    }
}
echo '</select></div>';

// Kolom 2: Autocomplete Nama Kursus / Pelatihan
echo '<div class="col-md-4 mb-3">';
echo '<label for="id_s_course" class="form-label font-weight-bold">Nama Kursus / Pelatihan</label>';
echo '<select name="s_course" id="id_s_course" class="form-control custom-select w-100">';
echo '<option value="0">-- Semua Kursus di LMS --</option>';
if (!empty($all_courses)) {
    foreach ($all_courses as $cid => $cname) {
        if ($cid == SITEID) {
            continue;
        }
        $selected = ($s_course == $cid) ? 'selected' : '';
        echo '<option value="' . $cid . '" ' . $selected . '>' . s($cname) . '</option>';
    }
}
echo '</select></div>';

// Kolom 3: Dropdown Status Progres
echo '<div class="col-md-3 mb-3">';
echo '<label for="s_status" class="form-label font-weight-bold">Status Progres</label>';
echo '<select name="s_status" id="s_status" class="form-control custom-select w-100">';
echo '<option value="all" ' . ($s_status == 'all' ? 'selected' : '') . '>-- Semua Status --</option>';
echo '<option value="done" ' . ($s_status == 'done' ? 'selected' : '') . '>Selesai</option>';
echo '<option value="progress" ' . ($s_status == 'progress' ? 'selected' : '') . '>Sedang Berjalan</option>';
echo '</select></div>';

// Kolom 4: Tombol Filter & Reset
echo '<div class="col-md-2 mb-3 text-right">';
echo '<div class="d-flex justify-content-end">';
echo '<button type="submit" class="btn btn-primary mr-2 flex-grow-1">Filter</button>';
echo '<a href="' . $PAGE->url . '" class="btn btn-secondary flex-grow-1">Reset</a>';
echo '</div></div>';

echo '</div>'; // Tutup row
echo '</form></div></div>';

// 4. AKTIFKAN JAVASCRIPT AUTOCOMPLETE
$PAGE->requires->js_call_amd('core/form-autocomplete', 'enhance', array(
    '#id_s_nik',
    false,
    null,
    'Ketik NIK atau nama...',
    false,
    true
));

$PAGE->requires->js_call_amd('core/form-autocomplete', 'enhance', array(
    '#id_s_course',
    false,
    null,
    'Ketik nama kursus...',
    false,
    true
));

// 5. INISIALISASI FLEXIBLE TABLE (Bebas Error Karena Kita Pasang Manual)
$table = new flexible_table('table-monitoring-v6');
$table->define_columns(['nik', 'nama_tim', 'coursename', 'finalgrade', 'status']);
$table->define_headers(['NIK', 'Nama Karyawan', 'Nama Kursus', 'Nilai Akhir', 'Status / Tgl Selesai']);

$table->define_baseurl($PAGE->url->out(false, array('s_nik' => $s_nik, 's_course' => $s_course, 's_status' => $s_status)));
$table->collapsible(false);
$table->setup();

// 6. BANGUN QUERY UTAMA ANDA YANG AKURAT (MENGGUNAKAN GROUP BY & MAX)
list($insql, $params) = $DB->get_in_or_equal($bawahan_ids, SQL_PARAMS_NAMED, 'bw');

$fields = "MAX(ue.id) AS id, u.id AS userid, c.id AS courseid, u.username AS nik, u.firstname, u.lastname, 
           c.fullname AS coursename, MAX(cp.timecompleted) AS timecompleted, MAX(gg.finalgrade) AS finalgrade";

$from = "{user_enrolments} ue
         JOIN {enrol} e ON e.id = ue.enrolid
         JOIN {course} c ON c.id = e.courseid
         JOIN {user} u ON u.id = ue.userid
         LEFT JOIN {course_completions} cp ON (cp.course = c.id AND cp.userid = u.id)
         LEFT JOIN {grade_items} gi ON (gi.courseid = c.id AND gi.itemtype = 'course')
         LEFT JOIN {grade_grades} gg ON (gg.itemid = gi.id AND gg.userid = u.id)";

$where = "u.id $insql AND u.deleted = 0 AND c.visible = 1";

if (!empty($s_nik)) {
    $where .= " AND u.username = :s_nik";
    $params['s_nik'] = $s_nik;
}
if (!empty($s_course) && $s_course > 0) {
    $where .= " AND c.id = :s_course";
    $params['s_course'] = $s_course;
}
if ($s_status === 'done') {
    $where .= " AND cp.timecompleted IS NOT NULL";
} else if ($s_status === 'progress') {
    $where .= " AND cp.timecompleted IS NULL";
}

$groupfields = " GROUP BY u.id, c.id, u.username, u.firstname, u.lastname, c.fullname";

// A. Hitung Total Records Secara Aman (Query Bersih Menggunakan COUNT DISTINCT)
$count_sql = "SELECT COUNT(DISTINCT CONCAT(u.id, '-', c.id)) FROM $from WHERE $where";
$total_rows = $DB->count_records_sql($count_sql, $params);
$total_rows = $total_rows ? (int)$total_rows : 0;

// Set ukuran halaman pagination (30 data per halaman)
$table->pagesize(30, $total_rows);

// B. Ambil Data Halaman Saat Ini dari Database
$final_sql = "SELECT $fields FROM $from WHERE $where $groupfields ORDER BY u.username ASC";
$records = $DB->get_records_sql($final_sql, $params, $table->get_page_start(), $table->get_page_size());

// 7. LOOPING DAN MASUKKAN DATA KE DALAM BARIS TABEL
if (!empty($records)) {
    foreach ($records as $values) {
        // Kolom NIK Link Profile
        $url_user = new moodle_url('/user/profile.php', array('id' => $values->userid));
        $row_nik = html_writer::link($url_user, s($values->nik));

        // Kolom Nama Karyawan (Memanggil Fungsi Bawaan Moodle secara Utuh)
        $row_nama = fullname($values);

        // Kolom Nama Kursus Link Course
        $url_course = new moodle_url('/course/view.php', array('id' => $values->courseid));
        $row_course = html_writer::link($url_course, s($values->coursename));

        // Kolom Nilai Akhir
        $row_grade = (is_null($values->finalgrade) || $values->finalgrade === '') ? '-' : format_float($values->finalgrade, 2);

        // Kolom Status
        if (!empty($values->timecompleted)) {
            $date = userdate($values->timecompleted, '%d %b %Y');
            $row_status = '<span class="badge badge-success" style="background-color: #28a745; color: white; padding: 5px 10px;">Done: ' . $date . '</span>';
        } else {
            $row_status = '<span class="badge badge-warning" style="background-color: #ffc107; color: black; padding: 5px 10px;">On Progress</span>';
        }

        // Tambahkan baris data ke tabel
        $table->add_data([$row_nik, $row_nama, $row_course, $row_grade, $row_status]);
    }
}

// 8. Cetak Tabel Ke Layar HTML
$table->print_html();

echo $OUTPUT->footer();