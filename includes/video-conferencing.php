<?php
// AJAX handler for batch attendance
function ihd_track_batch_attendance_ajax() {
    check_ajax_referer('ihd_video_nonce', 'nonce');
    
    $batch_id = sanitize_text_field($_POST['batch_id']);
    $student_name = sanitize_text_field($_POST['student_name']);
    $action = sanitize_text_field($_POST['action']);
    $duration = intval($_POST['duration']);
    
    if ($action === 'join') {
        ihd_track_batch_attendance($batch_id, $student_name);
        wp_send_json_success('Attendance tracked');
    } elseif ($action === 'leave') {
        ihd_update_batch_attendance($batch_id, $student_name, $duration);
        wp_send_json_success('Attendance updated');
    }
}
add_action('wp_ajax_ihd_track_batch_attendance', 'ihd_track_batch_attendance_ajax');
add_action('wp_ajax_nopriv_ihd_track_batch_attendance', 'ihd_track_batch_attendance_ajax');

// Track student joining batch class
function ihd_track_batch_attendance($batch_id, $student_name) {
    global $wpdb;
    
    $participants_table = $wpdb->prefix . 'ihd_batch_participants';
    
    // Find student ID by name
    $student = get_posts(array(
        'post_type' => 'student',
        'title' => $student_name,
        'posts_per_page' => 1
    ));
    
    if (empty($student)) {
        return false;
    }
    
    $student_id = $student[0]->ID;
    
    // Check if already joined
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $participants_table WHERE batch_id = %s AND student_id = %d AND status = 'joined'",
        $batch_id, $student_id
    ));
    
    if (!$existing) {
        $wpdb->insert(
            $participants_table,
            array(
                'batch_id' => $batch_id,
                'student_id' => $student_id,
                'student_name' => $student_name,
                'join_time' => current_time('mysql'),
                'status' => 'joined'
            ),
            array('%s', '%d', '%s', '%s', '%s')
        );
    }
    
    return true;
}

// Update attendance when student leaves
function ihd_update_batch_attendance($batch_id, $student_name, $duration_minutes) {
    global $wpdb;
    
    $participants_table = $wpdb->prefix . 'ihd_batch_participants';
    
    $student = get_posts(array(
        'post_type' => 'student',
        'title' => $student_name,
        'posts_per_page' => 1
    ));
    
    if (empty($student)) {
        return false;
    }
    
    $student_id = $student[0]->ID;
    
    $wpdb->update(
        $participants_table,
        array(
            'leave_time' => current_time('mysql'),
            'attendance_minutes' => $duration_minutes,
            'status' => 'completed'
        ),
        array(
            'batch_id' => $batch_id,
            'student_id' => $student_id,
            'status' => 'joined'
        ),
        array('%s', '%d', '%s'),
        array('%s', '%d', '%s')
    );
    
    return true;
}