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

// Ambil daftar kursus yang berstatus PUBLISH (visible = 1) untuk Dropdown Filter
global $DB;
$all_courses = $DB->get_records_menu('course', array('visible' => 1), 'fullname ASC', 'id, fullname');

// 2. Form Pencarian dengan Fitur Autocomplete (Desain Grid yang Lebih Rapi)
echo '<div class="card mb-4"><div class="card-body">';
echo '<form action="' . $PAGE->url . '" method="get">';
echo '<div class="row align-items-end">'; // Menggunakan Grid Row Bootstrap

// Kolom 1: NIK
echo '<div class="col-md-3 mb-3">
        <label for="s_nik" class="form-label font-weight-bold">NIK Karyawan</label>
        <input type="text" name="s_nik" id="s_nik" class="form-control w-100" placeholder="Ketik NIK..." value="' . s($s_nik) . '">
      </div>';

// Kolom 2: Kursus (Diberi ruang lebih besar agar teks tidak terpotong)
echo '<div class="col-md-4 mb-3">
        <label for="id_s_course" class="form-label font-weight-bold">Nama Kursus / Pelatihan</label>
        <select name="s_course" id="id_s_course" class="form-control custom-select w-100">';
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

// Kolom 3: Status
echo '<div class="col-md-3 mb-3">
        <label for="s_status" class="form-label font-weight-bold">Status Progres</label>
        <select name="s_status" id="s_status" class="form-control custom-select w-100">
            <option value="all" ' . ($s_status == 'all' ? 'selected' : '') . '>-- Semua Status --</option>
            <option value="done" ' . ($s_status == 'done' ? 'selected' : '') . '>Selesai</option>
            <option value="progress" ' . ($s_status == 'progress' ? 'selected' : '') . '>Sedang Berjalan</option>
        </select>
      </div>';

// Kolom 4: Tombol Aksi (Disejajarkan secara proporsional)
echo '<div class="col-md-2 mb-3 text-right">
        <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-primary mr-2 flex-grow-1">Filter</button>
            <a href="' . $PAGE->url . '" class="btn btn-secondary flex-grow-1">Reset</a>
        </div>
      </div>';

echo '</div>'; // Tutup row
echo '</form></div></div>';

// SUNTIKAN JAVASCRIPT MOODLE (Mengubah select biasa menjadi Autocomplete / searchable select)
$PAGE->requires->js_call_amd('core/form-autocomplete', 'enhance', array(
    '#id_s_course', // Selector ID element select kita
    false,          // Menolak penginputan teks kustom di luar opsi (tags = false)
    null,           // No ajax url (karena datanya langsung dimuat dari local array php)
    'Ketik nama kursus...', // Placeholder teks pencarian
    false,          // Case sensitive = false
    true            // Show suggestions langsung saat diklik
));

// 3. Dapatkan ID bawahan (Recursive)
$bawahan_ids = get_semua_bawahan_ids($USER->username);

if (empty($bawahan_ids)) {
    echo $OUTPUT->notification(get_string('no_subordinates', 'local_atasan_monitoring'), 'info');
    echo $OUTPUT->footer();
    exit;
}

// 4. Definisi Class Tabel
class MonitoringTable extends table_sql {
    public function __construct($uniqueid, $bawahan_ids, $s_nik, $s_course, $s_status) {
        global $DB;
        parent::__construct($uniqueid);
        
        $this->define_columns(['nik', 'fullname', 'coursename', 'finalgrade', 'status']);
        $this->define_headers(['NIK', 'Nama Karyawan', 'Nama Kursus', 'Nilai Akhir', 'Status / Tgl Selesai']);
        
        $this->sortable(true, 'fullname', SORT_ASC);
        $this->collapsible(false);
        
        list($insql, $params) = $DB->get_in_or_equal($bawahan_ids, SQL_PARAMS_NAMED, 'bw');
        
        // 1. Ubah $fields agar menggunakan fungsi MAX() pada finalgrade dan timecompleted
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
                $where .= " AND u.username LIKE :s_nik";
                $params['s_nik'] = '%' . $s_nik . '%';
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

            // 2. Tambahkan GROUP BY di akhir variabel $where agar nama dan kursus yang sama digabung jadi satu
            $where .= " GROUP BY u.id, c.id, u.username, u.firstname, u.lastname, c.fullname";

            $this->set_sql($fields, $from, $where, $params);
    }

    public function col_nik($values) {
        $url = new moodle_url('/user/profile.php', array('id' => $values->userid));
        return html_writer::link($url, s($values->nik));
    }

    public function col_fullname($values) {
        return fullname($values);
    }

    public function col_coursename($values) {
        $url = new moodle_url('/course/view.php', array('id' => $values->courseid));
        return html_writer::link($url, s($values->coursename));
    }

    public function col_finalgrade($values) {
        if (is_null($values->finalgrade)) {
            return '-';
        }
        return format_float($values->finalgrade, 2);
    }

    public function col_status($values) {
        if (!empty($values->timecompleted)) {
            $date = userdate($values->timecompleted, '%d %b %Y');
            return '<span class="badge badge-success" style="background-color: #28a745; color: white; padding: 5px 10px;">Done</span>';
        }
        return '<span class="badge badge-warning" style="background-color: #ffc107; color: black; padding: 5px 10px;">On Progress</span>';
    }
}

// 5. Render Tabel
$table = new MonitoringTable('table-monitoring-v6', $bawahan_ids, $s_nik, $s_course, $s_status);
$table->define_baseurl($PAGE->url->out(false, array('s_nik' => $s_nik, 's_course' => $s_course, 's_status' => $s_status)));
$table->out(30, true); 

echo $OUTPUT->footer();