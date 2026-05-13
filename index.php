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
$s_course = optional_param('s_course', '', PARAM_TEXT);
$s_status = optional_param('s_status', '', PARAM_ALPHA); 

echo $OUTPUT->header();

// 2. Form Pencarian
echo '<div class="card mb-4"><div class="card-body">';
echo '<form action="' . $PAGE->url . '" method="get" class="form-inline">';
echo '<input type="text" name="s_nik" class="form-control mr-2 mb-2" placeholder="Cari NIK..." value="' . s($s_nik) . '">';
echo '<input type="text" name="s_course" class="form-control mr-2 mb-2" placeholder="Cari Kursus..." value="' . s($s_course) . '">';
echo '<select name="s_status" class="form-control mr-2 mb-2">
        <option value="all" ' . ($s_status == 'all' ? 'selected' : '') . '>-- Semua Status --</option>
        <option value="done" ' . ($s_status == 'done' ? 'selected' : '') . '>Selesai</option>
        <option value="progress" ' . ($s_status == 'progress' ? 'selected' : '') . '>Sedang Berjalan</option>
      </select>';
echo '<button type="submit" class="btn btn-primary mb-2">Filter</button>';
echo '<a href="' . $PAGE->url . '" class="btn btn-secondary ml-2 mb-2">Reset</a>';
echo '</form></div></div>';

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
        
        // Tambahkan kolom 'finalgrade'
        $this->define_columns(['nik', 'fullname', 'coursename', 'finalgrade', 'status']);
        $this->define_headers(['NIK', 'Nama Karyawan', 'Nama Kursus', 'Nilai Akhir', 'Status / Tgl Selesai']);
        
        $this->sortable(true, 'fullname', SORT_ASC);
        $this->collapsible(false);
        
        list($insql, $params) = $DB->get_in_or_equal($bawahan_ids, SQL_PARAMS_NAMED, 'bw');
        
        // Query untuk mengambil final grade dari tabel grade_grades
        $fields = "ue.id AS id, u.id AS userid, c.id AS courseid, u.username AS nik, u.firstname, u.lastname, 
                   c.fullname AS coursename, cp.timecompleted, gg.finalgrade";
        
        $from = "{user_enrolments} ue
                 JOIN {enrol} e ON e.id = ue.enrolid
                 JOIN {course} c ON c.id = e.courseid
                 JOIN {user} u ON u.id = ue.userid
                 LEFT JOIN {course_completions} cp ON (cp.course = c.id AND cp.userid = u.id)
                 LEFT JOIN {grade_items} gi ON (gi.courseid = c.id AND gi.itemtype = 'course')
                 LEFT JOIN {grade_grades} gg ON (gg.itemid = gi.id AND gg.userid = u.id)";
        
        $where = "u.id $insql AND u.deleted = 0";
        
        if (!empty($s_nik)) {
            $where .= " AND u.username LIKE :s_nik";
            $params['s_nik'] = '%' . $s_nik . '%';
        }
        if (!empty($s_course)) {
            $where .= " AND c.fullname LIKE :s_course";
            $params['s_course'] = '%' . $s_course . '%';
        }
        if ($s_status === 'done') {
            $where .= " AND cp.timecompleted IS NOT NULL";
        } else if ($s_status === 'progress') {
            $where .= " AND cp.timecompleted IS NULL";
        }
        
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

    // Format tampilan nilai
    public function col_finalgrade($values) {
        if (is_null($values->finalgrade)) {
            return '-';
        }
        // Membulatkan 2 angka di belakang koma
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