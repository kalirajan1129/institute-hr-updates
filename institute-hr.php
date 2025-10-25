<?php
/**
 * Plugin Name: Institute HR Dashboard
 * Description: One-stop HR dashboard: manage Modules and Trainers (Add/Edit/Delete, Reset Password, Email notifications). Shortcode: [hr_dashboard]
 * Version: 1.9
 * Author: You
 */

if (!defined('ABSPATH')) exit;

// Include all necessary files
require_once plugin_dir_path(__FILE__) . 'includes/video-conferencing.php';
require_once plugin_dir_path(__FILE__) . 'includes/batch-management.php';
require_once plugin_dir_path(__FILE__) . 'includes/meeting-shortcodes.php';
require_once plugin_dir_path(__FILE__) . 'includes/dashboard-shortcodes.php';

// Set timezone to India
// Set timezone to India - FIXED for Mumbai/Tamil Nadu timing
// Enhanced timezone setup for Mumbai/Chennai (IST)
if (!function_exists('ihd_set_indian_timezone')) {
    function ihd_set_indian_timezone() {
        global $wpdb;
        
        // Force Asia/Kolkata timezone (covers both Mumbai and Chennai)
        date_default_timezone_set('Asia/Kolkata');
        
        // Update WordPress timezone settings
        update_option('timezone_string', 'Asia/Kolkata');
        update_option('gmt_offset', 5.5);
        
        // Set database timezone for current session
        $wpdb->query("SET time_zone = '+05:30';");
        
        error_log("IHD: Timezone set to Asia/Kolkata (IST) for Mumbai/Chennai timing");
        
        // Verify timezone is set correctly
        $current_tz = date_default_timezone_get();
        $wp_tz = get_option('timezone_string');
        
        if ($current_tz !== 'Asia/Kolkata' || $wp_tz !== 'Asia/Kolkata') {
            error_log("IHD: WARNING - Timezone not set correctly. PHP: $current_tz, WP: $wp_tz");
        }
    }
    add_action('init', 'ihd_set_indian_timezone', 1); // Higher priority
}

// Force timezone on admin pages as well
add_action('admin_init', 'ihd_set_indian_timezone');

// Debug function to check current timing
// Add this function to create the missing batch participants table
function ihd_create_missing_batch_participants_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'ihd_batch_participants';
    
    // Check if table exists
    if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        error_log("IHD: Creating missing table: $table_name");
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            batch_id varchar(100) NOT NULL,
            student_id mediumint(9) NOT NULL,
            student_name varchar(255) NOT NULL,
            join_time datetime DEFAULT CURRENT_TIMESTAMP,
            leave_time datetime NULL,
            attendance_minutes int DEFAULT 0,
            status varchar(20) DEFAULT 'joined',
            PRIMARY KEY (id),
            KEY batch_id (batch_id),
            KEY student_id (student_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        error_log("IHD: Table $table_name created successfully");
    } else {
        error_log("IHD: Table $table_name already exists");
    }
}

// Run this temporarily to create the table
add_action('init', 'ihd_create_missing_batch_participants_table');

// Enhanced timezone verification for Mumbai/Tamil Nadu
function ihd_verify_indian_timezone() {
    $current_php_tz = date_default_timezone_get();
    $current_wp_tz = get_option('timezone_string');
    $current_offset = get_option('gmt_offset');
    
    error_log("IHD: Timezone Status - PHP: $current_php_tz, WP: $current_wp_tz, Offset: $current_offset");
    
    // Force Asia/Kolkata timezone
    if ($current_wp_tz !== 'Asia/Kolkata') {
        update_option('timezone_string', 'Asia/Kolkata');
        update_option('gmt_offset', 5.5);
        error_log("IHD: Fixed WordPress timezone to Asia/Kolkata");
    }
    
    if ($current_php_tz !== 'Asia/Kolkata') {
        date_default_timezone_set('Asia/Kolkata');
        error_log("IHD: Fixed PHP timezone to Asia/Kolkata");
    }
    
    // Display current time for verification
    $current_time = current_time('mysql');
    $php_time = date('Y-m-d H:i:s');
    error_log("IHD: Current Time - WP: $current_time, PHP: $php_time");
}
add_action('init', 'ihd_verify_indian_timezone');

function ihd_get_current_display_time() {
    return current_time('mysql'); // Get local time for display
}
// Validate progress data before saving
function ihd_validate_progress_data($student_id, $completion_percentage, $notes, $class_minutes) {
    $errors = array();
    
    // Validate student exists
    $student = get_post($student_id);
    if (!$student || $student->post_type !== 'student') {
        $errors[] = "Invalid student ID: $student_id";
    }
    
    // Validate completion percentage
    if ($completion_percentage < 0 || $completion_percentage > 100) {
        $errors[] = "Invalid completion percentage: $completion_percentage";
    }
    
    // Validate class minutes
    if ($class_minutes < 0 || $class_minutes > 1440) { // Max 24 hours
        $errors[] = "Invalid class minutes: $class_minutes";
    }
    
    // Validate notes length
    if (strlen($notes) > 1000) {
        $errors[] = "Notes too long: " . strlen($notes) . " characters";
    }
    
    if (!empty($errors)) {
        error_log("IHD: DATA VALIDATION FAILED - " . implode(', ', $errors));
        return false;
    }
    
    return true;
}

// Enhanced safe progress tracking
function ihd_safe_track_daily_progress($student_id, $trainer_id, $completion_percentage, $notes = '', $class_minutes = 0) {
    
    // Validate data first
    if (!ihd_validate_progress_data($student_id, $completion_percentage, $notes, $class_minutes)) {
        error_log("IHD: ABORTING - Invalid progress data for student: $student_id");
        return false;
    }
    
    // Then proceed with saving
    return ihd_track_daily_progress($student_id, $trainer_id, $completion_percentage, $notes, $class_minutes);
}



// Helper function to repair corrupted entries
function ihd_repair_corrupted_entry($entry) {
    $new_data = array();
    
    // If completion_date contains a name instead of date, try to find correct student ID
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entry->completion_date)) {
        error_log("IHD: Corrupted entry detected - ID: {$entry->id}, Invalid date: '{$entry->completion_date}'");
        
        // Set a default date (today)
        $new_data['completion_date'] = current_time('mysql');
        $new_data['notes'] = 'AUTOMATICALLY REPAIRED - Was: ' . $entry->completion_date;
    }
    
    return $new_data;
}
// Add this function to institute_hr.php and call it once
function ihd_create_missing_progress_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'ihd_student_progress';
    
    // Check if table exists
    if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        error_log("IHD: Creating missing table: $table_name");
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            student_id mediumint(9) NOT NULL,
            trainer_id mediumint(9) NOT NULL,
            completion_date date NOT NULL,
            completion_percentage int DEFAULT 0,
            notes text,
            class_minutes int DEFAULT 0,
            updated_by mediumint(9) NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY student_id (student_id),
            KEY completion_date (completion_date),
            KEY student_date (student_id, completion_date)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        error_log("IHD: Table $table_name created successfully");
    } else {
        error_log("IHD: Table $table_name already exists");
    }
}

// Run this once - you can call it temporarily
add_action('init', 'ihd_create_missing_progress_table');
// Add this function to institute_hr.php and call it once
function ihd_create_missing_trainer_attendance_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'ihd_trainer_attendance';
    
    // Check if table exists
    if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        error_log("IHD: Creating missing table: $table_name");
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            trainer_id mediumint(9) NOT NULL,
            attendance_date date NOT NULL,
            attendance_time time NOT NULL,
            ip_address varchar(45) NOT NULL,
            location_status varchar(20) DEFAULT 'present',
            notes text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            latitude DECIMAL(10,8) DEFAULT NULL,
            longitude DECIMAL(11,8) DEFAULT NULL,
            accuracy DECIMAL(8,2) DEFAULT NULL,
            logout_time TIME DEFAULT NULL,
            logout_latitude DECIMAL(10,8) DEFAULT NULL,
            logout_longitude DECIMAL(11,8) DEFAULT NULL,
            logout_accuracy DECIMAL(8,2) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY trainer_date (trainer_id, attendance_date),
            KEY attendance_date (attendance_date),
            KEY ip_address (ip_address)
        ) $charset_collate;";

        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        error_log("IHD: Table $table_name created successfully");
    } else {
        error_log("IHD: Table $table_name already exists");
    }
}

// Run this once - you can call it temporarily
add_action('init', 'ihd_create_missing_trainer_attendance_table');
register_activation_hook(__FILE__, 'ihd_activate');
function ihd_activate(){
    ihd_set_indian_timezone();
    
    // HR Manager role - read/write access to HR dashboard only
    if (!get_role('hr_manager')) {
        add_role('hr_manager', 'HR Manager', array(
            'read' => true,
            'manage_trainers' => true,
            'edit_trainers' => true,
            'delete_trainers' => true
        ));
    } else {
        $r = get_role('hr_manager'); 
        $r->add_cap('manage_trainers');
        $r->add_cap('edit_trainers');
        $r->add_cap('delete_trainers');
    }
    
    // Trainer role
    if (!get_role('trainer')) {
        add_role('trainer', 'Trainer', array(
            'read' => true
        ));
    }
    
    // Finance Manager role - read-only access to finance dashboard
    if (!get_role('finance_manager')) {
        add_role('finance_manager', 'Finance Manager', array(
            'read' => true,
            'manage_finance' => true,
            'view_manager' => true, // Can view manager dashboard but not edit
            'manage_fees' => true // New capability for fee management
        ));
    } else {
        $r = get_role('finance_manager'); 
        $r->add_cap('manage_finance');
        $r->add_cap('view_manager');
        $r->add_cap('manage_fees');
    }
    
    // Trainer Manager role - access to manager dashboard only
    if (!get_role('trainer_manager')) {
        add_role('trainer_manager', 'Trainer Manager', array(
            'read' => true,
            'view_manager' => true,
            'delete_students' => true
        ));
    } else {
        $r = get_role('trainer_manager'); 
        $r->add_cap('view_manager');
        $r->add_cap('delete_students');
    }
    
    // Sales role - can add students
    if (!get_role('sales')) {
        add_role('sales', 'Sales', array(
            'read' => true,
            'add_students' => true
        ));
    } else {
        $r = get_role('sales'); 
        $r->add_cap('add_students');
    }
    
    // Create database tables for class tracking
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    
    // Class sessions table
    $table_name = $wpdb->prefix . 'ihd_class_sessions';
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        student_id mediumint(9) NOT NULL,
        trainer_id mediumint(9) NOT NULL,
        start_time datetime DEFAULT CURRENT_TIMESTAMP,
        end_time datetime NULL,
        duration_minutes int DEFAULT 0,
        recording_path varchar(255) DEFAULT '',
        meeting_id varchar(100) DEFAULT '',
        status varchar(20) DEFAULT 'active',
        batch_id varchar(100) DEFAULT '',
        PRIMARY KEY (id),
        KEY student_id (student_id),
        KEY trainer_id (trainer_id),
        KEY status (status)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Check for errors
    if (!empty($wpdb->last_error)) {
        error_log("IHD: Table creation error - " . $wpdb->last_error);
    }
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    
    // Student progress tracking table - UPDATED WITH CLASS_MINUTES
    $table_name = $wpdb->prefix . 'ihd_student_progress';
    
    // Check if table exists first
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
    
    if (!$table_exists) {
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            student_id mediumint(9) NOT NULL,
            trainer_id mediumint(9) NOT NULL,
            completion_date date NOT NULL,
            completion_percentage int DEFAULT 0,
            notes text,
            class_minutes int DEFAULT 0,
            updated_by mediumint(9) NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY student_id (student_id),
            KEY completion_date (completion_date)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        error_log("IHD: Created table: $table_name");
    } else {
        error_log("IHD: Table already exists: $table_name");
        
        // Check if class_minutes column exists, if not add it
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name");
        $has_class_minutes = false;
        foreach ($columns as $column) {
            if ($column->Field == 'class_minutes') {
                $has_class_minutes = true;
                break;
            }
        }
        
        if (!$has_class_minutes) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN class_minutes int DEFAULT 0");
            error_log("IHD: Added class_minutes column to $table_name");
        }
    }
    
    // Add batch tracking table
    $table_name = $wpdb->prefix . 'ihd_batch_sessions';
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        batch_id varchar(100) NOT NULL,
        trainer_id mediumint(9) NOT NULL,
        course_id mediumint(9) NOT NULL,
        timing varchar(50) NOT NULL,
        schedule_type varchar(20) NOT NULL,
        start_time datetime DEFAULT CURRENT_TIMESTAMP,
        end_time datetime NULL,
        duration_minutes int DEFAULT 0,
        meeting_id varchar(100) DEFAULT '',
        status varchar(20) DEFAULT 'active',
        student_count int DEFAULT 0,
        PRIMARY KEY (id),
        KEY batch_id (batch_id),
        KEY trainer_id (trainer_id),
        KEY status (status)
    ) $charset_collate;";

    dbDelta($sql);

    // Add batch participants table
    $table_name = $wpdb->prefix . 'ihd_batch_participants';
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        batch_id varchar(100) NOT NULL,
        student_id mediumint(9) NOT NULL,
        student_name varchar(255) NOT NULL,
        join_time datetime DEFAULT CURRENT_TIMESTAMP,
        leave_time datetime NULL,
        attendance_minutes int DEFAULT 0,
        status varchar(20) DEFAULT 'joined',
        PRIMARY KEY (id),
        KEY batch_id (batch_id),
        KEY student_id (student_id)
    ) $charset_collate;";

    dbDelta($sql);

    // Attendance table
    $table_name = $wpdb->prefix . 'ihd_trainer_attendance';
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        trainer_id mediumint(9) NOT NULL,
        attendance_date date NOT NULL,
        attendance_time time NOT NULL,
        ip_address varchar(45) NOT NULL,
        location_status varchar(20) DEFAULT 'present',
        notes text,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY trainer_date (trainer_id, attendance_date),
        KEY attendance_date (attendance_date),
        KEY ip_address (ip_address)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    ihd_verify_all_tables();
    flush_rewrite_rules();
}
// Office location configuration
function ihd_get_office_location() {
    return array(
        'latitude' => 12.974708,  // Mumbai coordinates - change to your office location
        'longitude' => 80.219884,
        'radius_km' => 0.1, // 100 meters radius
        'office_name' => 'Placement Point Solutions - Chennai'
    );
}

// Get user's precise location from browser geolocation
function ihd_get_user_precise_location() {
    // This function is called via AJAX after browser geolocation
    if (!isset($_POST['latitude']) || !isset($_POST['longitude'])) {
        return false;
    }
    
    $latitude = floatval($_POST['latitude']);
    $longitude = floatval($_POST['longitude']);
    $accuracy = isset($_POST['accuracy']) ? floatval($_POST['accuracy']) : 0;
    
    // Validate coordinates
    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        return false;
    }
    
    return array(
        'latitude' => $latitude,
        'longitude' => $longitude,
        'accuracy' => $accuracy,
        'source' => 'browser_geolocation'
    );
}

// Check if user is in office using precise location
function ihd_is_user_in_office_precise() {
    $office = ihd_get_office_location();
    $user_location = ihd_get_user_precise_location();
    
    if (!$user_location) {
        return array(
            'in_office' => false,
            'reason' => 'Unable to detect your precise location. Please allow location access in your browser.',
            'user_location' => null
        );
    }
    
    // Check location accuracy
    if ($user_location['accuracy'] > 100) { // 100 meters accuracy threshold
        return array(
            'in_office' => false,
            'reason' => 'Location accuracy is too low (' . round($user_location['accuracy'], 0) . 'm). Please move to an area with better GPS signal.',
            'user_location' => $user_location
        );
    }
    
    $distance = ihd_calculate_distance(
        $office['latitude'],
        $office['longitude'],
        $user_location['latitude'],
        $user_location['longitude']
    );
    
    $in_office = $distance <= $office['radius_km'];
    
    return array(
        'in_office' => $in_office,
        'distance' => $distance,
        'reason' => $in_office ? 
            "You are within office premises (" . round($distance * 1000, 0) . " meters from office)" :
            "You are " . round($distance, 2) . " km away from office. Please come to office to mark attendance.",
        'user_location' => $user_location,
        'accuracy' => $user_location['accuracy']
    );
}

// Enhanced function with detailed error logging
function ihd_mark_trainer_attendance_precise($trainer_id) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'ihd_trainer_attendance';
    $current_date = current_time('mysql');
    $current_time = current_time('H:i:s');
    $user_ip = $_SERVER['REMOTE_ADDR'];
    
    error_log("IHD: Starting attendance marking for trainer: $trainer_id");
    error_log("IHD: Table: $table_name, Date: $current_date, Time: $current_time, IP: $user_ip");
    
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
    if (!$table_exists) {
        error_log("IHD: ERROR - Table $table_name does not exist!");
        return array(
            'success' => false,
            'message' => 'Database configuration error. Please contact administrator.'
        );
    }
    
    // Check if already marked attendance today
    $existing_attendance = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE trainer_id = %d AND attendance_date = DATE(%s)",
        $trainer_id, $current_date
    ));
    
    if ($existing_attendance) {
        error_log("IHD: Attendance already marked today for trainer: $trainer_id");
        return array(
            'success' => false,
            'message' => 'You have already marked attendance today at ' . $existing_attendance->attendance_time
        );
    }
    
    // Check location using precise geolocation
    $location_check = ihd_is_user_in_office_precise();
    
    if (!$location_check['in_office']) {
        error_log("IHD: Location check failed - " . $location_check['reason']);
        return array(
            'success' => false,
            'message' => $location_check['reason']
        );
    }
    
    // Prepare data for insertion
    $attendance_data = array(
        'trainer_id' => $trainer_id,
        'attendance_date' => $current_date,
        'attendance_time' => $current_time,
        'ip_address' => $user_ip,
        'location_status' => 'present',
        'notes' => $location_check['reason'],
        'created_at' => $current_date
    );
    
    $format = array('%d', '%s', '%s', '%s', '%s', '%s', '%s');
    
    // Log the data we're trying to insert
    error_log("IHD: Attempting to insert attendance data: " . print_r($attendance_data, true));
    
    // Mark attendance
    $result = $wpdb->insert($table_name, $attendance_data, $format);
    
    if ($result === false) {
        $error_message = $wpdb->last_error;
        error_log("IHD: DATABASE INSERT FAILED - Error: " . $error_message);
        error_log("IHD: Last query: " . $wpdb->last_query);
        
        return array(
            'success' => false,
            'message' => 'Database error: ' . $error_message
        );
    }
    
    $insert_id = $wpdb->insert_id;
    error_log("IHD: Attendance marked successfully! Insert ID: $insert_id");
    
    return array(
        'success' => true,
        'message' => 'Attendance marked successfully! ' . $location_check['reason'],
        'attendance_time' => $current_time,
        'insert_id' => $insert_id
    );
}
// AJAX handler for trainer logout
function ihd_handle_trainer_logout() {
    // Start debugging
    error_log("IHD: ===== AJAX LOGOUT REQUEST START =====");
    error_log("IHD: POST data: " . print_r($_POST, true));
    
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ihd_attendance_nonce')) {
        error_log("IHD: Nonce verification failed for logout");
        wp_send_json_error('Security verification failed. Please refresh the page.');
    }
    
    $trainer_id = get_current_user_id();
    error_log("IHD: Trainer ID for logout: " . $trainer_id);
    
    if (!$trainer_id) {
        error_log("IHD: No trainer ID found for logout");
        wp_send_json_error('User not logged in.');
    }
    
    // Check for location data
    if (!isset($_POST['latitude']) || !isset($_POST['longitude'])) {
        error_log("IHD: Missing location data for logout");
        wp_send_json_error('Location data missing. Please allow location access.');
    }
    
    $latitude = floatval($_POST['latitude']);
    $longitude = floatval($_POST['longitude']);
    $accuracy = isset($_POST['accuracy']) ? floatval($_POST['accuracy']) : 0;
    
    error_log("IHD: Logout Location - Lat: $latitude, Long: $longitude, Accuracy: $accuracy");
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'ihd_trainer_attendance';
    $current_date = current_time('mysql');
    
    // Check if attendance is marked for today and logout time is not set
    $existing_attendance = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE trainer_id = %d AND attendance_date = DATE(%s) AND logout_time IS NULL",
        $trainer_id, $current_date
    ));
    
    if (!$existing_attendance) {
        error_log("IHD: No active attendance found to logout or already logged out");
        wp_send_json_error('No active attendance found or you have already logged out for today.');
    }
    
    // Check location for logout (same as login - must be in office)
    $office = ihd_get_office_location();
    $distance = ihd_calculate_distance($office['latitude'], $office['longitude'], $latitude, $longitude);
    
    error_log("IHD: Logout - Distance from office: " . $distance . " km");
    
    if ($distance > $office['radius_km']) {
        error_log("IHD: Logout location check failed - too far from office");
        wp_send_json_error("You are " . round($distance, 2) . " km away from office. Please come to office to mark logout.");
    }
    
    // Update attendance record with logout time and location
    $logout_time = current_time('H:i:s');
    
    $update_data = array(
        'logout_time' => $logout_time,
        'logout_latitude' => $latitude,
        'logout_longitude' => $longitude,
        'logout_accuracy' => $accuracy
    );
    
    $where = array(
        'id' => $existing_attendance->id
    );
    
    error_log("IHD: Attempting to update logout data: " . print_r($update_data, true));
    
    $result = $wpdb->update(
        $table_name,
        $update_data,
        $where,
        array('%s', '%f', '%f', '%f'),
        array('%d')
    );
    
    if ($result === false) {
        error_log("IHD: Database update failed for logout: " . $wpdb->last_error);
        wp_send_json_error('Database error: ' . $wpdb->last_error);
    }
    
    error_log("IHD: Logout marked successfully! Record ID: " . $existing_attendance->id);
    
    wp_send_json_success(array(
        'message' => 'Logout marked successfully! Time: ' . $logout_time,
        'logout_time' => $logout_time,
        'record_id' => $existing_attendance->id
    ));
}
add_action('wp_ajax_ihd_mark_trainer_logout', 'ihd_handle_trainer_logout');
add_action('wp_ajax_nopriv_ihd_mark_trainer_logout', 'ihd_handle_trainer_logout');
// Function to verify and repair the attendance table
function ihd_verify_attendance_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'ihd_trainer_attendance';
    
    error_log("IHD: Verifying attendance table: $table_name");
    
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
    
    if (!$table_exists) {
        error_log("IHD: Table doesn't exist, creating it...");
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            trainer_id mediumint(9) NOT NULL,
            attendance_date date NOT NULL,
            attendance_time time NOT NULL,
            ip_address varchar(45) NOT NULL,
            location_status varchar(20) DEFAULT 'present',
            notes text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY trainer_date (trainer_id, attendance_date),
            KEY attendance_date (attendance_date),
            KEY ip_address (ip_address)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $result = dbDelta($sql);
        
        error_log("IHD: Table creation result: " . print_r($result, true));
        
        // Check for errors
        if (!empty($wpdb->last_error)) {
            error_log("IHD: Table creation error: " . $wpdb->last_error);
            return false;
        }
        
        error_log("IHD: Table created successfully");
        return true;
    } else {
        error_log("IHD: Table exists, checking structure...");
        
        // Check table structure
        $columns = $wpdb->get_results("DESCRIBE $table_name");
        error_log("IHD: Table structure: " . print_r($columns, true));
        
        return true;
    }
}

// Run table verification
add_action('init', function() {
    if (isset($_GET['fix_attendance_table'])) {
        ihd_verify_attendance_table();
    }
});
// Temporary function to bypass database issues
function ihd_mark_attendance_temporary_fix($trainer_id) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'ihd_trainer_attendance';
    $current_date = current_time('mysql');
    $current_time = current_time('H:i:s');
    $user_ip = $_SERVER['REMOTE_ADDR'];
    
    error_log("IHD: TEMPORARY FIX - Marking attendance for trainer: $trainer_id");
    
    // Simple insert with minimal fields
    $result = $wpdb->insert(
        $table_name,
        array(
            'trainer_id' => $trainer_id,
            'attendance_date' => $current_date,
            'attendance_time' => $current_time,
            'ip_address' => $user_ip,
            'location_status' => 'present',
            'notes' => 'Temporary fix - manual entry'
        ),
        array('%d', '%s', '%s', '%s', '%s', '%s')
    );
    
    if ($result === false) {
        error_log("IHD: TEMPORARY FIX FAILED - " . $wpdb->last_error);
        
        // Try alternative approach - use raw SQL
        $sql = $wpdb->prepare(
            "INSERT INTO $table_name (trainer_id, attendance_date, attendance_time, ip_address, location_status, notes) 
             VALUES (%d, %s, %s, %s, %s, %s)",
            $trainer_id, $current_date, $current_time, $user_ip, 'present', 'Raw SQL entry'
        );
        
        $result = $wpdb->query($sql);
        
        if ($result === false) {
            error_log("IHD: RAW SQL ALSO FAILED - " . $wpdb->last_error);
            return array(
                'success' => false,
                'message' => 'Database unavailable. Please try again later.'
            );
        }
    }
    
    error_log("IHD: TEMPORARY FIX SUCCESS - Attendance marked");
    return array(
        'success' => true,
        'message' => 'Attendance marked successfully (temporary fix)',
        'attendance_time' => $current_time
    );
}
// Enhanced AJAX handler with comprehensive debugging
function ihd_handle_precise_attendance() {
    // Start debugging
    error_log("IHD: ===== AJAX ATTENDANCE REQUEST START =====");
    error_log("IHD: POST data: " . print_r($_POST, true));
    
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ihd_attendance_nonce')) {
        error_log("IHD: Nonce verification failed");
        wp_send_json_error('Security verification failed. Please refresh the page.');
    }
    
    $trainer_id = get_current_user_id();
    error_log("IHD: Trainer ID: " . $trainer_id);
    
    if (!$trainer_id) {
        error_log("IHD: No trainer ID found");
        wp_send_json_error('User not logged in.');
    }
    
    // Check for location data
    if (!isset($_POST['latitude']) || !isset($_POST['longitude'])) {
        error_log("IHD: Missing location data");
        wp_send_json_error('Location data missing. Please allow location access.');
    }
    
    $latitude = floatval($_POST['latitude']);
    $longitude = floatval($_POST['longitude']);
    $accuracy = isset($_POST['accuracy']) ? floatval($_POST['accuracy']) : 0;
    
    error_log("IHD: Location - Lat: $latitude, Long: $longitude, Accuracy: $accuracy");
    
    // Check if already marked attendance today
    global $wpdb;
    $table_name = $wpdb->prefix . 'ihd_trainer_attendance';
    $current_date = current_time('mysql');
    
    $existing_attendance = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE trainer_id = %d AND attendance_date = DATE(%s)",
        $trainer_id, $current_date
    ));
    
    if ($existing_attendance) {
        error_log("IHD: Attendance already marked today");
        wp_send_json_error('You have already marked attendance today at ' . $existing_attendance->attendance_time);
    }
    
    // Check location
    $office = ihd_get_office_location();
    $distance = ihd_calculate_distance($office['latitude'], $office['longitude'], $latitude, $longitude);
    
    error_log("IHD: Distance from office: " . $distance . " km");
    
    if ($distance > $office['radius_km']) {
        error_log("IHD: Location check failed - too far from office");
        wp_send_json_error("You are " . round($distance, 2) . " km away from office. Please come to office to mark attendance.");
    }
    
    // Mark attendance
    $current_time = current_time('H:i:s');
    $user_ip = $_SERVER['REMOTE_ADDR'];
    
    $attendance_data = array(
        'trainer_id' => $trainer_id,
        'attendance_date' => $current_date,
        'attendance_time' => $current_time,
        'ip_address' => $user_ip,
        'location_status' => 'present',
        'notes' => "Precise location - Distance: " . round($distance * 1000, 0) . " meters from office",
        'latitude' => $latitude,
        'longitude' => $longitude,
        'accuracy' => $accuracy,
        'created_at' => $current_date
    );
    
    error_log("IHD: Attempting to insert attendance: " . print_r($attendance_data, true));
    
    $result = $wpdb->insert(
        $table_name,
        $attendance_data,
        array('%d', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%s')
    );
    
    if ($result === false) {
        error_log("IHD: Database insert failed: " . $wpdb->last_error);
        wp_send_json_error('Database error: ' . $wpdb->last_error);
    }
    
    $insert_id = $wpdb->insert_id;
    error_log("IHD: Attendance marked successfully! ID: $insert_id");
    
    wp_send_json_success(array(
        'message' => 'Attendance marked successfully! Time: ' . $current_time,
        'attendance_time' => $current_time,
        'insert_id' => $insert_id
    ));
}
add_action('wp_ajax_ihd_mark_precise_attendance', 'ihd_handle_precise_attendance');
add_action('wp_ajax_nopriv_ihd_mark_precise_attendance', 'ihd_handle_precise_attendance');

// Calculate distance between two coordinates
function ihd_calculate_distance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371; // Earth's radius in kilometers
    
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    
    return $earth_radius * $c; // Distance in kilometers
}

// Check if user is in office
function ihd_is_user_in_office($user_ip) {
    $office = ihd_get_office_location();
    $user_location = ihd_get_user_location_from_ip($user_ip);
    
    if (!$user_location) {
        return array(
            'in_office' => false,
            'reason' => 'Unable to detect your location. Please ensure you are connected to office WiFi.',
            'user_location' => null
        );
    }
    
    $distance = ihd_calculate_distance(
        $office['latitude'],
        $office['longitude'],
        $user_location['latitude'],
        $user_location['longitude']
    );
    
    $in_office = $distance <= $office['radius_km'];
    
    return array(
        'in_office' => $in_office,
        'distance' => $distance,
        'reason' => $in_office ? 
            "You are within office premises (" . round($distance * 1000, 0) . " meters from office)" :
            "You are " . round($distance, 2) . " km away from office. Please come to office to mark attendance.",
        'user_location' => $user_location
    );
}

// Mark attendance function
function ihd_mark_trainer_attendance($trainer_id) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'ihd_trainer_attendance';
    $current_date = current_time('mysql');
    $current_time = current_time('H:i:s');
    $user_ip = $_SERVER['REMOTE_ADDR'];
    
    // Check if already marked attendance today
    $existing_attendance = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE trainer_id = %d AND attendance_date = DATE(%s)",
        $trainer_id, $current_date
    ));
    
    if ($existing_attendance) {
        return array(
            'success' => false,
            'message' => 'You have already marked attendance today at ' . $existing_attendance->attendance_time
        );
    }
    
    // Check if same IP used for another trainer today
    $same_ip_attendance = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE ip_address = %s AND attendance_date = DATE(%s) AND trainer_id != %d",
        $user_ip, $current_date, $trainer_id
    ));
    
    if ($same_ip_attendance) {
        return array(
            'success' => false,
            'message' => 'This IP address has already been used for attendance by another trainer today.'
        );
    }
    
    // Check location
    $location_check = ihd_is_user_in_office($user_ip);
    
    if (!$location_check['in_office']) {
        return array(
            'success' => false,
            'message' => $location_check['reason']
        );
    }
    
    // Mark attendance
    $result = $wpdb->insert(
        $table_name,
        array(
            'trainer_id' => $trainer_id,
            'attendance_date' => $current_date,
            'attendance_time' => $current_time,
            'ip_address' => $user_ip,
            'location_status' => 'present',
            'notes' => $location_check['reason'],
            'created_at' => $current_date
        ),
        array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
    );
    
    if ($result) {
        return array(
            'success' => true,
            'message' => 'Attendance marked successfully! ' . $location_check['reason'],
            'attendance_time' => $current_time
        );
    }
    
    return array(
        'success' => false,
        'message' => 'Failed to mark attendance. Please try again.'
    );
}
// Update attendance table to store precise coordinates
function ihd_update_attendance_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ihd_trainer_attendance';
    
    // Check if latitude column exists, if not add it
    $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name");
    $has_latitude = false;
    $has_longitude = false;
    $has_accuracy = false;
    
    foreach ($columns as $column) {
        if ($column->Field == 'latitude') $has_latitude = true;
        if ($column->Field == 'longitude') $has_longitude = true;
        if ($column->Field == 'accuracy') $has_accuracy = true;
    }
    
    if (!$has_latitude) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN latitude DECIMAL(10,8) DEFAULT NULL");
    }
    
    if (!$has_longitude) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN longitude DECIMAL(11,8) DEFAULT NULL");
    }
    
    if (!$has_accuracy) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN accuracy DECIMAL(8,2) DEFAULT NULL");
    }
}

// Run this on init to ensure table is updated
add_action('init', 'ihd_update_attendance_table');
// Updated function to get fees collection data including partial payments
function ihd_get_fees_collection_data($start_date = null, $end_date = null) {
    global $wpdb;
    
    // Set default dates if not provided
    if (!$start_date) {
        $start_date = date('Y-m-01'); // First day of current month
    }
    if (!$end_date) {
        $end_date = date('Y-m-t'); // Last day of current month
    }
    
    // Get all students with fees data within date range
    $students = get_posts(array(
        'post_type' => 'student',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => 'completion_updated',
                'value' => array($start_date, $end_date),
                'compare' => 'BETWEEN',
                'type' => 'DATE'
            )
        )
    ));
    
    $total_collection = 0;
    $partial_payments = 0;
    $full_payments = 0;
    $course_collections = array();
    $recent_payments = array();
    $partial_payments_list = array();
    $total_paid_students = 0;
    $partial_paid_students = 0;
    
    foreach ($students as $student) {
        $fees_paid = floatval(get_post_meta($student->ID, 'fees_paid', true));
        $total_fees = floatval(get_post_meta($student->ID, 'total_fees', true));
        $fee_status = get_post_meta($student->ID, 'fee_status', true);
        $course_id = get_post_meta($student->ID, 'course_id', true);
        $course_name = get_term($course_id, 'module')->name ?? 'Unknown Course';
        $completion_updated = get_post_meta($student->ID, 'completion_updated', true);
        
        // Only count if fees are actually paid
        if ($fees_paid > 0) {
            $total_collection += $fees_paid;
            $total_paid_students++;
            
            // Check if it's partial payment
            if ($fees_paid < $total_fees && $total_fees > 0) {
                $partial_payments += $fees_paid;
                $partial_paid_students++;
                
                // Add to partial payments list
                $partial_payments_list[] = array(
                    'student_name' => $student->post_title,
                    'course_name' => $course_name,
                    'fees_paid' => $fees_paid,
                    'total_fees' => $total_fees,
                    'balance' => $total_fees - $fees_paid,
                    'payment_date' => $completion_updated,
                    'fee_status' => $fee_status,
                    'payment_type' => 'partial'
                );
            } else {
                $full_payments += $fees_paid;
            }
            
            // Group by course
            if (!isset($course_collections[$course_id])) {
                $course_collections[$course_id] = array(
                    'course_name' => $course_name,
                    'total_fees' => 0,
                    'student_count' => 0,
                    'partial_payments' => 0,
                    'full_payments' => 0,
                    'last_payment' => '',
                    'average_fee' => 0
                );
            }
            
            $course_collections[$course_id]['total_fees'] += $fees_paid;
            $course_collections[$course_id]['student_count']++;
            
            // Track partial vs full payments by course
            if ($fees_paid < $total_fees && $total_fees > 0) {
                $course_collections[$course_id]['partial_payments'] += $fees_paid;
            } else {
                $course_collections[$course_id]['full_payments'] += $fees_paid;
            }
            
            // Update last payment date
            if (!$course_collections[$course_id]['last_payment'] || 
                strtotime($completion_updated) > strtotime($course_collections[$course_id]['last_payment'])) {
                $course_collections[$course_id]['last_payment'] = $completion_updated;
            }
            
            // Add to recent payments
            $recent_payments[] = array(
                'student_name' => $student->post_title,
                'course_name' => $course_name,
                'fees_paid' => $fees_paid,
                'total_fees' => $total_fees,
                'balance' => $total_fees - $fees_paid,
                'payment_date' => $completion_updated,
                'fee_status' => $fee_status,
                'payment_type' => ($fees_paid < $total_fees && $total_fees > 0) ? 'partial' : 'full'
            );
        }
    }
    
    // Calculate average fees and sort by total collection (highest first)
    foreach ($course_collections as $course_id => &$course) {
        if ($course['student_count'] > 0) {
            $course['average_fee'] = $course['total_fees'] / $course['student_count'];
        }
    }
    
    // Sort courses by total collection (descending)
    uasort($course_collections, function($a, $b) {
        return $b['total_fees'] - $a['total_fees'];
    });
    
    // Sort recent payments by date (newest first)
    usort($recent_payments, function($a, $b) {
        return strtotime($b['payment_date']) - strtotime($a['payment_date']);
    });
    
    // Sort partial payments by date (newest first)
    usort($partial_payments_list, function($a, $b) {
        return strtotime($b['payment_date']) - strtotime($a['payment_date']);
    });
    
    // Get only last payments
    $recent_payments = array_slice($recent_payments, 0, 10);
    $partial_payments_list = array_slice($partial_payments_list, 0, 20);
    // Add this inside your ihd_get_fees_collection_data function, after the existing calculations

    $total_pending = 0;
    $pending_students_count = 0;

    foreach ($students as $student) {
        $fees_paid = floatval(get_post_meta($student->ID, 'fees_paid', true));
        $total_fees = floatval(get_post_meta($student->ID, 'total_fees', true));
        $fee_status = get_post_meta($student->ID, 'fee_status', true);
        
        // Calculate pending amount (only for students with fee_status = 'pending')
        if ($fee_status === 'pending' && $total_fees > 0) {
            $pending_amount = $total_fees - $fees_paid;
            if ($pending_amount > 0) {
                $total_pending += $pending_amount;
                $pending_students_count++;
            }
        }
    }
    return array(
        'total_collection' => $total_collection,
        'partial_payments' => $partial_payments,
        'full_payments' => $full_payments,
        'total_pending' => $total_pending, // Add this line
        'pending_students_count' => $pending_students_count, // Add this line
        'course_collections' => $course_collections,
        'recent_payments' => $recent_payments,
        'partial_payments_list' => $partial_payments_list,
        'total_paid_students' => $total_paid_students,
        'partial_paid_students' => $partial_paid_students
    );
}
// Add this function to verify all tables
function ihd_verify_all_tables() {
    global $wpdb;
    
    $tables = array(
        $wpdb->prefix . 'ihd_class_sessions',
        $wpdb->prefix . 'ihd_student_progress', 
        $wpdb->prefix . 'ihd_batch_sessions',
        $wpdb->prefix . 'ihd_batch_participants'
    );
    
    foreach ($tables as $table) {
        $exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if ($exists) {
            error_log("IHD: Table verified: $table");
        } else {
            error_log("IHD: ERROR - Table missing: $table");
        }
    }
}

// Call this on init to ensure tables exist
add_action('init', 'ihd_verify_all_tables');
/* ---------------- Ensure admins and managers have capability ---------------- */
add_action('init', function() {
    // Give administrators all capabilities
    $admin = get_role('administrator');
    if ($admin) {
        if (!$admin->has_cap('manage_trainers')) {
            $admin->add_cap('manage_trainers');
        }
        if (!$admin->has_cap('edit_trainers')) {
            $admin->add_cap('edit_trainers');
        }
        if (!$admin->has_cap('delete_trainers')) {
            $admin->add_cap('delete_trainers');
        }
        if (!$admin->has_cap('manage_finance')) {
            $admin->add_cap('manage_finance');
        }
        if (!$admin->has_cap('view_manager')) {
            $admin->add_cap('view_manager');
        }
        if (!$admin->has_cap('delete_students')) {
            $admin->add_cap('delete_students');
        }
        if (!$admin->has_cap('manage_fees')) {
            $admin->add_cap('manage_fees');
        }
        if (!$admin->has_cap('add_students')) {
            $admin->add_cap('add_students');
        }
    }
    
    // Ensure finance managers have read-only capabilities for manager dashboard
    $finance = get_role('finance_manager');
    if ($finance) {
        if (!$finance->has_cap('manage_finance')) {
            $finance->add_cap('manage_finance');
        }
        if (!$finance->has_cap('view_manager')) {
            $finance->add_cap('view_manager');
        }
        if (!$finance->has_cap('manage_fees')) {
            $finance->add_cap('manage_fees');
        }
        // Remove HR capabilities if they exist
        if ($finance->has_cap('manage_trainers')) {
            $finance->remove_cap('manage_trainers');
        }
        if ($finance->has_cap('edit_trainers')) {
            $finance->remove_cap('edit_trainers');
        }
        if ($finance->has_cap('delete_trainers')) {
            $finance->remove_cap('delete_trainers');
        }
        if ($finance->has_cap('delete_students')) {
            $finance->remove_cap('delete_students');
        }
        if ($finance->has_cap('add_students')) {
            $finance->remove_cap('add_students');
        }
    }
    
    // Ensure HR managers have only HR capabilities
    $hr = get_role('hr_manager');
    if ($hr) {
        if (!$hr->has_cap('edit_trainers')) {
            $hr->add_cap('edit_trainers');
        }
        if (!$hr->has_cap('delete_trainers')) {
            $hr->add_cap('delete_trainers');
        }
        // Remove manager/finance capabilities if they exist
        if ($hr->has_cap('view_manager')) {
            $hr->remove_cap('view_manager');
        }
        if ($hr->has_cap('manage_finance')) {
            $hr->remove_cap('manage_finance');
        }
        if ($hr->has_cap('delete_students')) {
            $hr->remove_cap('delete_students');
        }
        if ($hr->has_cap('manage_fees')) {
            $hr->remove_cap('manage_fees');
        }
        if ($hr->has_cap('add_students')) {
            $hr->remove_cap('add_students');
        }
    }
    
    // Ensure trainer managers have only manager capabilities
    $trainer_manager = get_role('trainer_manager');
    if ($trainer_manager) {
        if (!$trainer_manager->has_cap('view_manager')) {
            $trainer_manager->add_cap('view_manager');
        }
        if (!$trainer_manager->has_cap('delete_students')) {
            $trainer_manager->add_cap('delete_students');
        }
        // Remove HR/finance capabilities if they exist
        if ($trainer_manager->has_cap('manage_trainers')) {
            $trainer_manager->remove_cap('manage_trainers');
        }
        if ($trainer_manager->has_cap('edit_trainers')) {
            $trainer_manager->remove_cap('edit_trainers');
        }
        if ($trainer_manager->has_cap('delete_trainers')) {
            $trainer_manager->remove_cap('delete_trainers');
        }
        if ($trainer_manager->has_cap('manage_finance')) {
            $trainer_manager->remove_cap('manage_finance');
        }
        if ($trainer_manager->has_cap('manage_fees')) {
            $trainer_manager->remove_cap('manage_fees');
        }
        if ($trainer_manager->has_cap('add_students')) {
            $trainer_manager->remove_cap('add_students');
        }
    }
    
    // Ensure sales have only add students capability
    $sales = get_role('sales');
    if ($sales) {
        if (!$sales->has_cap('add_students')) {
            $sales->add_cap('add_students');
        }
        // Remove other capabilities if they exist
        if ($sales->has_cap('manage_trainers')) {
            $sales->remove_cap('manage_trainers');
        }
        if ($sales->has_cap('edit_trainers')) {
            $sales->remove_cap('edit_trainers');
        }
        if ($sales->has_cap('delete_trainers')) {
            $sales->remove_cap('delete_trainers');
        }
        if ($sales->has_cap('manage_finance')) {
            $sales->remove_cap('manage_finance');
        }
        if ($sales->has_cap('view_manager')) {
            $sales->remove_cap('view_manager');
        }
        if ($sales->has_cap('delete_students')) {
            $sales->remove_cap('delete_students');
        }
        if ($sales->has_cap('manage_fees')) {
            $sales->remove_cap('manage_fees');
        }
    }
});

/* ---------------- Register CPT and taxonomy ---------------- */
add_action('init', 'ihd_register_student_cpt_and_module_tax');
function ihd_register_student_cpt_and_module_tax(){
    // Student CPT (private, show in admin)
    register_post_type('student', array(
        'labels' => array('name'=>'Students','singular_name'=>'Student'),
        'public' => false,
        'show_ui' => true,
        'supports' => array('title','editor','custom-fields'),
    ));

    // Module taxonomy
    register_taxonomy('module', array('student'), array(
        'label' => 'Modules',
        'hierarchical' => false,
        'show_ui' => true,
    ));
}
// Add this function to debug timezone and date issues
function ihd_debug_current_timing_issues() {
    if (isset($_GET['debug_timing']) && current_user_can('administrator')) {
        global $wpdb;
        
        echo '<div class="notice notice-info">';
        echo '<h3>🕒 Current Timing Debug (Mumbai/Chennai - IST)</h3>';
        
        // Check PHP timezone
        echo '<p><strong>PHP Timezone:</strong> ' . date_default_timezone_get() . '</p>';
        echo '<p><strong>WordPress Timezone:</strong> ' . get_option('timezone_string') . '</p>';
        echo '<p><strong>GMT Offset:</strong> ' . get_option('gmt_offset') . '</p>';
        
        // Check current times
        echo '<p><strong>PHP Current Time:</strong> ' . date('Y-m-d H:i:s') . '</p>';
        echo '<p><strong>WordPress Current Time:</strong> ' . current_time('mysql') . '</p>';
        echo '<p><strong>WordPress Timestamp:</strong> ' . current_time('timestamp') . '</p>';
        
        // Check database time
        $db_time = $wpdb->get_var('SELECT NOW()');
        echo '<p><strong>Database Server Time:</strong> ' . $db_time . '</p>';
        
        // Check recent progress entries
        $progress_table = $wpdb->prefix . 'ihd_student_progress';
        $recent_entries = $wpdb->get_results("SELECT * FROM $progress_table ORDER BY id DESC LIMIT 3");
        
        echo '<h4>Recent Progress Entries:</h4>';
        foreach ($recent_entries as $entry) {
            echo '<p>ID: ' . $entry->id . ' | Student: ' . $entry->student_id . ' | Date: ' . $entry->completion_date . ' | Progress: ' . $entry->completion_percentage . '% | Minutes: ' . $entry->class_minutes . ' | Notes: ' . esc_html($entry->notes) . '</p>';
        }
        
        echo '</div>';
    }
}
add_action('init', 'ihd_debug_current_timing_issues');
/* ---------------- Helper functions ---------------- */
function ihd_generate_trainer_id($user_id){
    return 'TR-' . str_pad(intval($user_id), 5, '0', STR_PAD_LEFT);
}

function ihd_generate_meeting_id(){
    return 'MEET-' . strtoupper(wp_generate_password(8, false, false));
}

/**
 * Send Trainer Credentials Email
 *
 * @param string $email Trainer email
 * @param string $first_name Trainer first name
 * @param string $username Trainer login username
 * @param string $password Trainer password
 * @param string $trainer_id Trainer unique ID
 * @return bool True if email sent successfully, false otherwise
 */
function ihd_send_trainer_credentials_email($email, $first_name, $username, $password, $trainer_id) {
    $subject = "Your Trainer Account Credentials";

    $message  = "Hello {$first_name},\n\n";
    $message .= "Your trainer account has been created/updated.\n\n";
    $message .= "Trainer ID: {$trainer_id}\n";
    $message .= "Username: {$username}\n";
    $message .= "Password: {$password}\n\n";
    $message .= "Login here: " . wp_login_url() . "\n\n";
    $message .= "Please change your password after logging in.\n\n";
    $message .= "Regards,\n";
    $message .= get_bloginfo('name') . " HR Team";

    $headers = array('Content-Type: text/plain; charset=UTF-8');

    return wp_mail($email, $subject, $message, $headers);
}

function ihd_send_trainer_update_email($user_email, $first, $changes = ''){
    $subject = "Your Trainer Profile Updated";
    $body = "Hello {$first},\n\nYour trainer profile has been updated.\n\n{$changes}\n\nIf you have any questions, contact HR.";
    wp_mail($user_email, $subject, $body);
}
// Add this function to clean up incorrect session data
function ihd_cleanup_incorrect_sessions() {
    global $wpdb;
    $session_table = $wpdb->prefix . 'ihd_class_sessions';
    
    // Find sessions with unrealistic durations (> 24 hours)
    $incorrect_sessions = $wpdb->get_results("
        SELECT * FROM $session_table 
        WHERE duration_minutes > 1440 
        OR start_time LIKE '2025-10-01 07:11:%'
    ");
    
    if (!empty($incorrect_sessions)) {
        error_log("IHD: Found " . count($incorrect_sessions) . " incorrect sessions to clean up");
        
        foreach ($incorrect_sessions as $session) {
            // Set realistic duration (average class duration)
            $realistic_duration = 120; // 2 hours in minutes
            
            $wpdb->update(
                $session_table,
                array('duration_minutes' => $realistic_duration),
                array('id' => $session->id),
                array('%d'),
                array('%d')
            );
            
            error_log("IHD: Fixed session ID: " . $session->id . " - Set duration to: " . $realistic_duration . " minutes");
        }
    }
    
    return count($incorrect_sessions);
}

// Run cleanup once
add_action('init', function() {
    if (isset($_GET['cleanup_sessions']) && current_user_can('administrator')) {
        $fixed_count = ihd_cleanup_incorrect_sessions();
        error_log("IHD: Cleanup completed. Fixed $fixed_count sessions.");
        
        // Show message
        add_action('admin_notices', function() use ($fixed_count) {
            echo '<div class="notice notice-success"><p>Cleaned up ' . $fixed_count . ' incorrect session records.</p></div>';
        });
    }
});
function ihd_convert_timing_to_sortable($timing) {
    if (empty($timing)) return 0;
    
    // Remove spaces and convert to uppercase for consistent processing
    $timing = strtoupper(str_replace(' ', '', $timing));
    
    // Extract time parts using regex
    if (preg_match('/(\d+)-(\d+)(AM|PM)/', $timing, $matches)) {
        $start_hour = intval($matches[1]);
        $end_hour = intval($matches[2]);
        $period = $matches[3];
        
        // Convert to 24-hour format for sorting
        if ($period === 'PM' && $start_hour != 12) {
            $start_hour += 12;
        }
        if ($period === 'AM' && $start_hour == 12) {
            $start_hour = 0;
        }
        
        return $start_hour * 100; // Convert to sortable number
    }
    
    // If format doesn't match, return a high number to push to bottom
    return 9999;
}

// Get total class hours for a student from both tables
function ihd_get_student_total_hours($student_id) {
    global $wpdb;
    $session_table = $wpdb->prefix . 'ihd_class_sessions';
    $progress_table = $wpdb->prefix . 'ihd_student_progress';
    
    // Get total from class sessions
    $session_minutes = $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(duration_minutes) FROM $session_table WHERE student_id = %d AND status = 'completed'",
        $student_id
    ));
    
    // Get total from progress tracking (as backup)
    $progress_minutes = $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(class_minutes) FROM $progress_table WHERE student_id = %d",
        $student_id
    ));
    
    $total_minutes = max($session_minutes ?: 0, $progress_minutes ?: 0);
    
    return $total_minutes ? round($total_minutes / 60, 2) : 0;
}




// Add these functions to your existing institute-hr.php file

// FIXED: Enhanced progress tracking with proper note handling
function ihd_track_daily_progress($student_id, $trainer_id, $completion_percentage, $notes = '', $class_minutes = 0) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ihd_student_progress';
    
    // Get current IST date (for Mumbai/Chennai)
    $current_date = current_time('mysql');
    
    error_log("IHD: Tracking progress - Student: $student_id, Progress: $completion_percentage%, Minutes: $class_minutes, Notes: " . substr($notes, 0, 50));
    
    // Check if entry exists for today
    $existing_entry = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE student_id = %d AND DATE(completion_date) = DATE(%s) ORDER BY id DESC LIMIT 1",
        $student_id, $current_date
    ));
    
    if ($existing_entry) {
        // Update existing entry - FIXED: Proper parameter order
        $result = $wpdb->update(
            $table_name,
            array(
                'completion_percentage' => $completion_percentage,
                'notes' => $notes,
                'class_minutes' => $class_minutes,
                'updated_by' => $trainer_id,
                'updated_at' => $current_date
            ),
            array('id' => $existing_entry->id),
            array('%d', '%s', '%d', '%d', '%s'), // data formats
            array('%d') // where format
        );
        
        if ($result !== false) {
            error_log("IHD: Updated progress entry ID: " . $existing_entry->id . " for student: " . $student_id);
        } else {
            error_log("IHD: ERROR updating progress - " . $wpdb->last_error);
        }
    } else {
        // Create new entry - FIXED: Proper parameter order
        $result = $wpdb->insert(
            $table_name,
            array(
                'student_id' => $student_id,
                'trainer_id' => $trainer_id,
                'completion_date' => $current_date,
                'completion_percentage' => $completion_percentage,
                'notes' => $notes,
                'class_minutes' => $class_minutes,
                'updated_by' => $trainer_id,
                'updated_at' => $current_date
            ),
            array('%d', '%d', '%s', '%d', '%s', '%d', '%d', '%s')
        );
        
        if ($result !== false) {
            error_log("IHD: Created new progress entry ID: " . $wpdb->insert_id . " for student: " . $student_id);
        } else {
            error_log("IHD: ERROR creating progress - " . $wpdb->last_error);
        }
    }
    
    // Update student's main completion percentage
    update_post_meta($student_id, 'completion', $completion_percentage);
    update_post_meta($student_id, 'completion_updated', $current_date);
    
    return $result !== false;
}
// Test notes functionality
function ihd_test_notes() {
    if (isset($_GET['test_notes'])) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ihd_student_progress';
        
        // Get latest progress entries
        $progress_entries = $wpdb->get_results("SELECT * FROM $table_name ORDER BY id DESC LIMIT 5");
        
        echo "<div class='notice notice-info'>";
        echo "<h3>Progress Notes Test</h3>";
        foreach ($progress_entries as $entry) {
            echo "<p>Student ID: {$entry->student_id} | Progress: {$entry->completion_percentage}% | Notes: '{$entry->notes}' | Minutes: {$entry->class_minutes} | Date: {$entry->completion_date}</p>";
        }
        echo "</div>";
    }
}
add_action('init', 'ihd_test_notes');
// Debug timezone and date issues
function ihd_debug_timezone() {
    if (isset($_GET['debug_timezone'])) {
        error_log("IHD: === TIMEZONE DEBUG ===");
        error_log("IHD: PHP timezone: " . date_default_timezone_get());
        error_log("IHD: WordPress timezone: " . get_option('timezone_string'));
        error_log("IHD: PHP current: " . date('Y-m-d H:i:s'));
        error_log("IHD: WordPress current: " . current_time('mysql'));
        error_log("IHD: WordPress current (GMT): " . current_time('mysql', 1));
        error_log("IHD: === END TIMEZONE DEBUG ===");
        
        echo "<div class='notice notice-info'>";
        echo "<p><strong>Timezone Debug Info:</strong></p>";
        echo "<p>PHP timezone: " . date_default_timezone_get() . "</p>";
        echo "<p>WordPress timezone: " . get_option('timezone_string') . "</p>";
        echo "<p>PHP current: " . date('Y-m-d H:i:s') . "</p>";
        echo "<p>WordPress current: " . current_time('mysql') . "</p>";
        echo "<p>WordPress current (GMT): " . current_time('mysql', 1) . "</p>";
        echo "</div>";
    }
}
add_action('init', 'ihd_debug_timezone');

// Enhanced function to get student progress history with class time
function ihd_get_student_progress_history($student_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ihd_student_progress';
    
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name WHERE student_id = %d ORDER BY completion_date DESC",
        $student_id
    ));
}
// Temporary debug function - add this to institute-hr.php
function ihd_debug_class_sessions() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ihd_class_sessions';
    
    error_log("IHD: === CLASS SESSIONS DEBUG ===");
    
    $sessions = $wpdb->get_results("SELECT * FROM $table_name");
    
    if (empty($sessions)) {
        error_log("IHD: No class sessions found in database");
    } else {
        foreach ($sessions as $session) {
            error_log("IHD: Session - Student: {$session->student_id}, Duration: {$session->duration_minutes}min, Status: {$session->status}");
        }
    }
    
    error_log("IHD: === END CLASS SESSIONS DEBUG ===");
    
    return $sessions;
}

// Call this function temporarily
add_action('init', 'ihd_debug_class_sessions');
// Function to get daily class time report
function ihd_get_student_daily_class_report($student_id, $start_date = null, $end_date = null) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ihd_student_progress';
    
    $query = "SELECT * FROM $table_name WHERE student_id = %d";
    $params = array($student_id);
    
    if ($start_date) {
        $query .= " AND completion_date >= %s";
        $params[] = $start_date;
    }
    
    if ($end_date) {
        $query .= " AND completion_date <= %s";
        $params[] = $end_date;
    }
    
    $query .= " ORDER BY completion_date DESC";
    
    return $wpdb->get_results($wpdb->prepare($query, $params));
}
function ihd_trainer_daily_report_tab() {
    if (!is_user_logged_in()) return '';
    
    $user = wp_get_current_user();
    if (!in_array('trainer', $user->roles)) return '';

    global $wpdb;
    $progress_table = $wpdb->prefix . 'ihd_student_progress';
    
    // Date range filter
    $start_date = sanitize_text_field($_GET['report_start_date'] ?? date('Y-m-d', strtotime('-7 days')));
    $end_date = sanitize_text_field($_GET['report_end_date'] ?? date('Y-m-d'));
    
    ob_start();
    ?>
    
    <style>
    /* Daily Report Traditional Responsive Styles */
    .ihd-daily-report-section {
        background: #fff;
        padding: 20px;
        border-radius: 6px;
        border: 1px solid #ddd;
        margin-bottom: 20px;
    }
    
    .ihd-report-filters {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 6px;
        border: 1px solid #e9ecef;
        margin-bottom: 20px;
    }
    
    .ihd-report-filters h4 {
        margin: 0 0 15px 0;
        color: #2c3e50;
        font-size: 15px;
        font-weight: 600;
    }
    
    .ihd-filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 15px;
        align-items: end;
    }
    
    .ihd-filter-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #495057;
        font-size: 13px;
    }
    
    .ihd-filter-group input {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #ced4da;
        border-radius: 4px;
        font-size: 13px;
        height: 40px;
        box-sizing: border-box;
    }
    
    .ihd-filter-group button {
        background: #3498db;
        color: white;
        border: 1px solid #2980b9;
        padding: 10px 20px;
        border-radius: 4px;
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        width: 100%;
        height: 40px;
        box-sizing: border-box;
    }
    
    .ihd-filter-group button:hover {
        background: #2980b9;
    }
    
    .ihd-filter-group a {
        display: block;
        padding: 10px 20px;
        background: #95a5a6;
        color: white;
        text-align: center;
        text-decoration: none;
        border-radius: 4px;
        font-size: 13px;
        font-weight: 500;
        border: 1px solid #7f8c8d;
        height: 40px;
        box-sizing: border-box;
    }
    
    .ihd-filter-group a:hover {
        background: #7f8c8d;
        color: white;
        text-decoration: none;
    }
    
    .ihd-report-summary {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }
    
    .ihd-summary-card {
        background: white;
        padding: 20px;
        border-radius: 6px;
        border: 1px solid #ddd;
        border-left: 4px solid #3498db;
        text-align: center;
    }
    
    .ihd-summary-card h4 {
        margin: 0 0 10px 0;
        color: #7f8c8d;
        font-size: 13px;
        font-weight: 600;
    }
    
    .ihd-summary-card p {
        font-size: 24px;
        font-weight: 700;
        margin: 0;
        color: #3498db;
    }
    
    .ihd-summary-card.total-classes {
        border-left-color: #3498db;
    }
    
    .ihd-summary-card.total-classes p {
        color: #3498db;
    }
    
    .ihd-summary-card.total-hours {
        border-left-color: #e67e22;
    }
    
    .ihd-summary-card.total-hours p {
        color: #e67e22;
    }
    
    .ihd-summary-card.avg-duration {
        border-left-color: #27ae60;
    }
    
    .ihd-summary-card.avg-duration p {
        color: #27ae60;
    }
    
    .ihd-summary-card.students-taught {
        border-left-color: #9b59b6;
    }
    
    .ihd-summary-card.students-taught p {
        color: #9b59b6;
    }
    
    .ihd-export-btn {
        background: #27ae60;
        color: white;
        padding: 12px 25px;
        border: 1px solid #229954;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s ease;
        text-align: center;
        text-decoration: none;
        display: inline-block;
        margin-bottom: 15px;
        height: 40px;
        box-sizing: border-box;
    }
    
    .ihd-export-btn:hover {
        background: #229954;
        border-color: #1e8449;
    }
    
    .ihd-daily-report-table-container {
        overflow-x: auto;
        border-radius: 6px;
        margin: 15px 0;
        border: 1px solid #ddd;
        -webkit-overflow-scrolling: touch;
        max-width: 100%;
    }
    
    .ihd-daily-report-table {
        width: 100%;
        border-collapse: collapse;
        background: #fff;
        font-size: 12px;
        table-layout: auto;
        min-width: 800px;
    }
    
    .ihd-daily-report-table thead {
        background: #2c3e50;
    }
    
    .ihd-daily-report-table th {
        color: white;
        padding: 10px 8px;
        text-align: left;
        font-weight: 600;
        font-size: 12px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        border-right: 1px solid #34495e;
    }
    
    .ihd-daily-report-table th:last-child {
        border-right: none;
    }
    
    .ihd-daily-report-table td {
        padding: 8px;
        border-bottom: 1px solid #ecf0f1;
        border-right: 1px solid #ecf0f1;
        vertical-align: top;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
    
    .ihd-daily-report-table td:last-child {
        border-right: none;
    }
    
    .ihd-daily-report-table tbody tr:hover {
        background: #f8f9fa;
    }
    
    .ihd-daily-report-table tbody tr:nth-child(even) {
        background: #fafbfc;
    }
    
    .ihd-class-time {
        color: #e67e22;
        font-weight: 600;
        font-size: 11px;
    }
    
    .ihd-class-notes {
        max-width: 200px;
        overflow: hidden;
        white-space: nowrap;
        font-size: 11px;
    }
    
    .ihd-class-notes.expanded {
        white-space: normal;
        overflow: visible;
        max-width: none;
    }
    
    .ihd-toggle-notes {
        background: none;
        border: none;
        color: #3498db;
        cursor: pointer;
        font-size: 10px;
        margin-left: 5px;
        padding: 2px 6px;
    }
    
    .ihd-no-data {
        text-align: center;
        padding: 40px 20px;
        color: #7f8c8d;
        font-style: italic;
        background: white;
        border-radius: 6px;
        border: 1px solid #ddd;
    }
    
    .ihd-no-data p {
        margin: 0;
        font-size: 14px;
    }
    
    /* Progress bars */
    .ihd-trainer-progress-container {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .ihd-trainer-progress-bar {
        width: 70px;
        height: 6px;
        background: #ecf0f1;
        border-radius: 3px;
        overflow: hidden;
    }
    
    .ihd-trainer-progress-fill {
        height: 100%;
        background: #3498db;
        border-radius: 3px;
        transition: width 0.3s ease;
    }
    
    /* Mode badges */
    .ihd-trainer-mode-badge {
        padding: 4px 8px;
        border-radius: 3px;
        font-size: 11px;
        font-weight: 600;
        display: inline-block;
        text-align: center;
        min-width: 70px;
    }
    
    .mode-online {
        background: #3498db;
        color: white;
    }
    
    .mode-offline {
        background: #7f8c8d;
        color: white;
    }
    
    /* Table column widths */
    .ihd-daily-report-table th:nth-child(1),
    .ihd-daily-report-table td:nth-child(1) {
        width: 120px; /* Date */
        min-width: 120px;
    }
    
    .ihd-daily-report-table th:nth-child(2),
    .ihd-daily-report-table td:nth-child(2) {
        width: 120px; /* Student Name */
        min-width: 120px;
    }
    
    .ihd-daily-report-table th:nth-child(3),
    .ihd-daily-report-table td:nth-child(3) {
        width: 120px; /* Course */
        min-width: 120px;
    }
    
    .ihd-daily-report-table th:nth-child(4),
    .ihd-daily-report-table td:nth-child(4) {
        width: 100px; /* Timing */
        min-width: 100px;
    }
    
    .ihd-daily-report-table th:nth-child(5),
    .ihd-daily-report-table td:nth-child(5) {
        width: 80px; /* Mode */
        min-width: 80px;
    }
    
    .ihd-daily-report-table th:nth-child(6),
    .ihd-daily-report-table td:nth-child(6) {
        width: 100px; /* Duration */
        min-width: 100px;
    }
    
    .ihd-daily-report-table th:nth-child(7),
    .ihd-daily-report-table td:nth-child(7) {
        width: 100px; /* Progress */
        min-width: 100px;
    }
    
    .ihd-daily-report-table th:nth-child(8),
    .ihd-daily-report-table td:nth-child(8) {
        width: 150px; /* Notes */
        min-width: 150px;
        max-width: 200px;
    }
    
    /* Mobile-specific styles */
    @media (max-width: 768px) {
        .ihd-daily-report-section {
            padding: 15px;
        }
        
        .ihd-report-filters {
            padding: 15px;
        }
        
        .ihd-filter-grid {
            grid-template-columns: 1fr;
        }
        
        .ihd-report-summary {
            grid-template-columns: 1fr;
        }
        
        .ihd-summary-card {
            padding: 15px;
        }
        
        .ihd-summary-card p {
            font-size: 20px;
        }
        
        .ihd-daily-report-table {
            font-size: 11px;
            min-width: 700px;
        }
        
        .ihd-daily-report-table th,
        .ihd-daily-report-table td {
            padding: 6px 4px;
        }
        
        .ihd-daily-report-table th:nth-child(1),
        .ihd-daily-report-table td:nth-child(1) {
            width: 100px;
            min-width: 100px;
        }
        
        .ihd-daily-report-table th:nth-child(8),
        .ihd-daily-report-table td:nth-child(8) {
            width: 120px;
            min-width: 120px;
            max-width: 150px;
        }
        
        .ihd-export-btn {
            width: 100%;
        }
    }
    
    @media (max-width: 480px) {
        .ihd-daily-report-section {
            padding: 12px;
        }
        
        .ihd-report-filters {
            padding: 12px;
        }
        
        .ihd-summary-card {
            padding: 12px;
        }
        
        .ihd-summary-card p {
            font-size: 18px;
        }
        
        .ihd-daily-report-table {
            font-size: 10px;
            min-width: 650px;
        }
        
        .ihd-daily-report-table th,
        .ihd-daily-report-table td {
            padding: 5px 3px;
        }
        
        .ihd-daily-report-table th:nth-child(1),
        .ihd-daily-report-table td:nth-child(1) {
            width: 90px;
            min-width: 90px;
        }
        
        .ihd-daily-report-table th:nth-child(8),
        .ihd-daily-report-table td:nth-child(8) {
            width: 100px;
            min-width: 100px;
            max-width: 120px;
        }
    }
    
    /* Focus states for accessibility */
    .ihd-export-btn:focus,
    .ihd-filter-group input:focus,
    .ihd-filter-group button:focus,
    .ihd-filter-group a:focus,
    .ihd-toggle-notes:focus {
        outline: 2px solid #3498db;
        outline-offset: 2px;
    }
    
    /* Print styles */
    @media print {
        .ihd-report-filters,
        .ihd-export-btn {
            display: none;
        }
        
        .ihd-daily-report-section {
            border: 1px solid #000;
        }
        
        .ihd-daily-report-table {
            border: 1px solid #000;
        }
        
        .ihd-daily-report-table-container {
            overflow: visible;
        }
    }
    </style>

    <div class="ihd-daily-report-section">
        <h3>📊 Daily Class Report</h3>
        
        <!-- Date Range Filter -->
        <div class="ihd-report-filters">
            <h4>📅 Filter Report by Date Range</h4>
            <form method="get" class="ihd-filter-grid">
                <input type="hidden" name="tab" value="daily-report">
                <div class="ihd-filter-group">
                    <label for="report_start_date">Start Date</label>
                    <input type="date" id="report_start_date" name="report_start_date" value="<?php echo esc_attr($start_date); ?>" required>
                </div>
                <div class="ihd-filter-group">
                    <label for="report_end_date">End Date</label>
                    <input type="date" id="report_end_date" name="report_end_date" value="<?php echo esc_attr($end_date); ?>" required>
                </div>
                <div class="ihd-filter-group">
                    <button type="submit">🔍 Generate Report</button>
                </div>
                <div class="ihd-filter-group">
                    <a href="?tab=daily-report">🔄 Reset</a>
                </div>
            </form>
        </div>

        <?php
        // Get daily class data for the trainer
        $daily_classes = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, s.post_title as student_name, 
                    m.name as course_name,
                    s_meta.meta_value as training_mode,
                    s_meta2.meta_value as timing
             FROM $progress_table p
             LEFT JOIN {$wpdb->posts} s ON p.student_id = s.ID
             LEFT JOIN {$wpdb->term_relationships} tr ON s.ID = tr.object_id
             LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             LEFT JOIN {$wpdb->terms} m ON tt.term_id = m.term_id
             LEFT JOIN {$wpdb->postmeta} s_meta ON s.ID = s_meta.post_id AND s_meta.meta_key = 'training_mode'
             LEFT JOIN {$wpdb->postmeta} s_meta2 ON s.ID = s_meta2.post_id AND s_meta2.meta_key = 'timing'
             WHERE p.trainer_id = %d 
             AND DATE(p.completion_date) BETWEEN %s AND %s
             AND p.class_minutes > 0
             ORDER BY p.completion_date DESC, p.updated_at DESC",
            $user->ID, $start_date, $end_date
        ));

        if (!empty($daily_classes)) {
            // Calculate summary statistics
            $total_classes = count($daily_classes);
            $total_minutes = 0;
            $total_hours = 0;
            $unique_students = array();
            
            foreach ($daily_classes as $class) {
                $total_minutes += $class->class_minutes;
                $unique_students[$class->student_id] = true;
            }
            
            $total_hours = round($total_minutes / 60, 2);
            $avg_duration = $total_classes > 0 ? round($total_minutes / $total_classes) : 0;
            $unique_student_count = count($unique_students);
            ?>
            
            <!-- Summary Cards -->
            <div class="ihd-report-summary">
                <div class="ihd-summary-card total-classes">
                    <h4>Total Classes</h4>
                    <p><?php echo intval($total_classes); ?></p>
                </div>
                <div class="ihd-summary-card total-hours">
                    <h4>Total Hours</h4>
                    <p><?php echo number_format($total_hours, 2); ?>h</p>
                </div>
                <div class="ihd-summary-card avg-duration">
                    <h4>Avg. Duration</h4>
                    <p><?php echo intval($avg_duration); ?>m</p>
                </div>
                <div class="ihd-summary-card students-taught">
                    <h4>Students Taught</h4>
                    <p><?php echo intval($unique_student_count); ?></p>
                </div>
            </div>

            <!-- Export Button -->
            <button class="ihd-export-btn" onclick="exportDailyReport()">
                📥 Export to Excel
            </button>

            <!-- Daily Classes Table -->
            <div class="ihd-daily-report-table-container">
                <table class="ihd-daily-report-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Student Name</th>
                            <th>Course</th>
                            <th>Timing</th>
                            <th>Mode</th>
                            <th>Duration</th>
                            <th>Progress</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($daily_classes as $class): 
                            $class_date = date('M j, Y', strtotime($class->completion_date));
                            $class_time = date('g:i A', strtotime($class->updated_at));
                            $class_duration = $class->class_minutes;
                            $class_hours = $class_duration > 0 ? round($class_duration / 60, 2) : 0;
                            $training_mode = $class->training_mode ?: 'offline';
                            $mode_class = 'ihd-trainer-mode-badge mode-' . $training_mode;
                        ?>
                        <tr>
                            <td data-label="Date">
                                <strong><?php echo esc_html($class_date); ?></strong><br>
                                <small style="color: #7f8c8d;"><?php echo esc_html($class_time); ?></small>
                            </td>
                            <td data-label="Student Name">
                                <strong><?php echo esc_html($class->student_name); ?></strong>
                            </td>
                            <td data-label="Course"><?php echo esc_html($class->course_name); ?></td>
                            <td data-label="Timing"><?php echo esc_html($class->timing); ?></td>
                            <td data-label="Mode">
                                <span class="<?php echo $mode_class; ?>">
                                    <?php echo esc_html(ucfirst($training_mode)); ?>
                                </span>
                            </td>
                            <td data-label="Class Duration" class="ihd-class-time">
                                <?php echo intval($class_duration); ?> min<br>
                                <small>(<?php echo number_format($class_hours, 2); ?> hours)</small>
                            </td>
                            <td data-label="Progress">
                                <div class="ihd-trainer-progress-container">
                                    <span style="color:#3498db;font-weight:bold;font-size:11px;"><?php echo intval($class->completion_percentage); ?>%</span>
                                    <div class="ihd-trainer-progress-bar">
                                        <div class="ihd-trainer-progress-fill" style="width: <?php echo intval($class->completion_percentage); ?>%;"></div>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Notes">
                                <div class="ihd-class-notes" id="notes-<?php echo $class->id; ?>">
                                    <?php echo esc_html($class->notes ?: 'No notes'); ?>
                                </div>
                                <?php if (!empty($class->notes) && strlen($class->notes) > 50): ?>
                                    <button class="ihd-toggle-notes" onclick="toggleNotes(<?php echo $class->id; ?>)">
                                        More
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <script>
            function toggleNotes(classId) {
                const notesElement = document.getElementById('notes-' + classId);
                const button = notesElement.nextElementSibling;
                
                if (notesElement.classList.contains('expanded')) {
                    notesElement.classList.remove('expanded');
                    button.textContent = 'More';
                } else {
                    notesElement.classList.add('expanded');
                    button.textContent = 'Less';
                }
            }
            
            function exportDailyReport() {
                // Create CSV content
                let csvContent = "Date,Student Name,Course,Timing,Mode,Class Duration (min),Class Duration (hours),Progress %,Notes\n";
                
                <?php foreach ($daily_classes as $class): ?>
                    csvContent += "<?php 
                        echo date('Y-m-d', strtotime($class->completion_date)) . ',' .
                             esc_attr($class->student_name) . ',' .
                             esc_attr($class->course_name) . ',' .
                             esc_attr($class->timing) . ',' .
                             esc_attr($class->training_mode) . ',' .
                             intval($class->class_minutes) . ',' .
                             number_format($class->class_minutes / 60, 2) . ',' .
                             intval($class->completion_percentage) . ',"' .
                             str_replace('"', '""', $class->notes ?: 'No notes') . "\"\n";
                    ?>";
                <?php endforeach; ?>
                
                // Create and download file
                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const link = document.createElement('a');
                const url = URL.createObjectURL(blob);
                
                link.setAttribute('href', url);
                link.setAttribute('download', 'daily-class-report-<?php echo $start_date; ?>-to-<?php echo $end_date; ?>.csv');
                link.style.visibility = 'hidden';
                
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
            </script>
            
        <?php } else { ?>
            <div class="ihd-no-data">
                <p>📭 No class data found for the selected date range.</p>
                <p style="margin-top: 10px; font-size: 13px;">
                    Date Range: <?php echo date('M j, Y', strtotime($start_date)); ?> to <?php echo date('M j, Y', strtotime($end_date)); ?>
                </p>
            </div>
        <?php } ?>
    </div>
    
    <?php
    return ob_get_clean();
}

function ihd_trainer_attendance_tab() {
    if (!is_user_logged_in()) return '';
    
    $user = wp_get_current_user();
    if (!in_array('trainer', $user->roles)) return '';

    global $wpdb;
    $attendance_table = $wpdb->prefix . 'ihd_trainer_attendance';
    
    $messages = array();
    
    // Handle attendance marking - keep your existing code for marking attendance
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_mark_attendance'])) {
        if (!isset($_POST['ihd_mark_attendance_nonce']) || !wp_verify_nonce($_POST['ihd_mark_attendance_nonce'], 'ihd_mark_attendance_action')) {
            $messages[] = '<div class="error">Invalid request.</div>';
        } else {
            $result = ihd_mark_trainer_attendance($user->ID);
            
            if ($result['success']) {
                $messages[] = '<div class="updated">✅ ' . esc_html($result['message']) . '</div>';
            } else {
                $messages[] = '<div class="error">❌ ' . esc_html($result['message']) . '</div>';
            }
        }
    }
    
    // Date range filter
    $start_date = sanitize_text_field($_GET['attendance_start_date'] ?? date('Y-m-d', strtotime('-30 days')));
    $end_date = sanitize_text_field($_GET['attendance_end_date'] ?? date('Y-m-d'));
    
    // Check if attendance is already marked for today and if logout is pending
    $current_date = current_time('mysql');
    $today_attendance = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $attendance_table WHERE trainer_id = %d AND attendance_date = DATE(%s)",
        $user->ID, $current_date
    ));
    
    $has_today_attendance = !empty($today_attendance);
    $has_logged_out = $has_today_attendance && !empty($today_attendance->logout_time);
    
    ob_start();
    ?>
    
    <style>
    .ihd-attendance-section {
        background: #fff;
        padding: 20px;
        border-radius: 6px;
        border: 1px solid #ddd;
        margin-bottom: 20px;
    }

    .ihd-attendance-card {
        background: #2c3e50;
        color: white;
        padding: 25px;
        border-radius: 6px;
        text-align: center;
        margin-bottom: 20px;
        border: 1px solid #34495e;
        border-left: 4px solid #3498db;
    }

    .ihd-attendance-card.logout-card {
        background: #c0392b;
        border-left: 4px solid #e74c3c;
    }

    .ihd-attendance-card h3 {
        margin: 0 0 15px 0;
        font-size: 18px;
        font-weight: 600;
    }

    .ihd-attendance-card p {
        margin: 8px 0;
        font-size: 14px;
        opacity: 0.9;
    }

    .ihd-mark-attendance-btn {
        background: #27ae60;
        color: white;
        padding: 12px 25px;
        border: 1px solid #229954;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s ease;
        margin: 10px 0;
        height: 40px;
        box-sizing: border-box;
    }

    .ihd-mark-attendance-btn:hover {
        background: #229954;
        border-color: #1e8449;
    }

    .ihd-mark-attendance-btn:disabled {
        background: #95a5a6;
        border-color: #7f8c8d;
        cursor: not-allowed;
    }

    .ihd-logout-btn {
        background: #e74c3c;
        color: white;
        padding: 12px 25px;
        border: 1px solid #c0392b;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s ease;
        margin: 10px 0;
        height: 40px;
        box-sizing: border-box;
    }

    .ihd-logout-btn:hover {
        background: #c0392b;
        border-color: #a93226;
    }

    .ihd-logout-btn:disabled {
        background: #95a5a6;
        border-color: #7f8c8d;
        cursor: not-allowed;
    }

    .attendance-status {
        background: rgba(255, 255, 255, 0.1);
        padding: 15px;
        border-radius: 4px;
        margin: 15px 0;
        border-left: 4px solid #fff;
    }

    .attendance-status h4 {
        margin: 0 0 8px 0;
        font-size: 15px;
        font-weight: 600;
    }

    .attendance-status p {
        margin: 5px 0;
        font-size: 13px;
    }

    .ihd-attendance-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 15px;
        margin: 20px 0;
    }

    .ihd-attendance-stat-card {
        background: white;
        padding: 20px;
        border-radius: 6px;
        border: 1px solid #ddd;
        border-left: 4px solid #3498db;
        text-align: center;
    }

    .ihd-attendance-stat-card h4 {
        margin: 0 0 10px 0;
        color: #7f8c8d;
        font-size: 13px;
        font-weight: 600;
    }

    .ihd-attendance-stat-card p {
        font-size: 24px;
        font-weight: 700;
        margin: 0;
        color: #3498db;
    }

    .ihd-attendance-filters {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 6px;
        border: 1px solid #e9ecef;
        margin-bottom: 20px;
    }

    .ihd-attendance-filters .ihd-filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 15px;
        align-items: end;
    }

    .ihd-attendance-filters label {
        display: block;
        margin-bottom: 5px;
        font-weight: 600;
        color: #495057;
        font-size: 13px;
    }

    .ihd-attendance-filters input,
    .ihd-attendance-filters select {
        padding: 10px 12px;
        border: 1px solid #ced4da;
        border-radius: 4px;
        font-size: 13px;
        width: 100%;
        box-sizing: border-box;
        height: 40px;
    }

    .ihd-attendance-filters button {
        background: #3498db;
        color: white;
        border: 1px solid #2980b9;
        padding: 10px 20px;
        border-radius: 4px;
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        height: 40px;
        box-sizing: border-box;
    }

    .ihd-attendance-filters button:hover {
        background: #2980b9;
    }

    .ihd-attendance-table-container {
        overflow-x: auto;
        border-radius: 6px;
        margin: 15px 0;
        border: 1px solid #ddd;
        -webkit-overflow-scrolling: touch;
        max-width: 100%;
    }

    .ihd-attendance-table {
        width: 100%;
        border-collapse: collapse;
        background: #fff;
        font-size: 12px;
        table-layout: auto;
        min-width: 700px;
    }

    .ihd-attendance-table thead {
        background: #2c3e50;
    }

    .ihd-attendance-table th {
        color: white;
        padding: 10px 8px;
        text-align: left;
        font-weight: 600;
        font-size: 12px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        border-right: 1px solid #34495e;
    }

    .ihd-attendance-table th:last-child {
        border-right: none;
    }

    .ihd-attendance-table td {
        padding: 8px;
        border-bottom: 1px solid #ecf0f1;
        border-right: 1px solid #ecf0f1;
        vertical-align: top;
        word-wrap: break-word;
        overflow-wrap: break-word;
        max-width: 200px;
    }

    .ihd-attendance-table td:last-child {
        border-right: none;
    }

    .ihd-attendance-table tbody tr:hover {
        background: #f8f9fa;
    }

    .ihd-attendance-table tbody tr:nth-child(even) {
        background: #fafbfc;
    }

    .attendance-present {
        color: #27ae60;
        font-weight: 600;
        font-size: 11px;
    }

    .attendance-absent {
        color: #e74c3c;
        font-weight: 600;
        font-size: 11px;
    }

    .logout-time {
        color: #e67e22;
        font-weight: 600;
        font-size: 11px;
    }

    /* Table column widths for better fit */
    .ihd-attendance-table th:nth-child(1),
    .ihd-attendance-table td:nth-child(1) {
        width: 120px; /* Date */
        min-width: 120px;
    }

    .ihd-attendance-table th:nth-child(2),
    .ihd-attendance-table td:nth-child(2) {
        width: 100px; /* Day */
        min-width: 100px;
    }

    .ihd-attendance-table th:nth-child(3),
    .ihd-attendance-table td:nth-child(3) {
        width: 100px; /* Login Time */
        min-width: 100px;
    }

    .ihd-attendance-table th:nth-child(4),
    .ihd-attendance-table td:nth-child(4) {
        width: 100px; /* Logout Time */
        min-width: 100px;
    }

    .ihd-attendance-table th:nth-child(5),
    .ihd-attendance-table td:nth-child(5) {
        width: 120px; /* Status */
        min-width: 120px;
    }

    .ihd-attendance-table th:nth-child(6),
    .ihd-attendance-table td:nth-child(6) {
        width: 120px; /* IP Address */
        min-width: 120px;
    }

    .ihd-attendance-table th:nth-child(7),
    .ihd-attendance-table td:nth-child(7) {
        width: 150px; /* Location */
        min-width: 150px;
        max-width: 200px;
    }

    /* Mobile-specific styles */
    @media (max-width: 768px) {
        .ihd-attendance-section {
            padding: 15px;
        }
        .table-container{
            overflow-x:auto;
        }
        .ihd-attendance-card {
            padding: 20px;
        }
        
        .ihd-attendance-card h3 {
            font-size: 16px;
        }
        
        .ihd-attendance-card p {
            font-size: 13px;
        }
        
        .ihd-attendance-filters .ihd-filter-grid {
            grid-template-columns: 1fr;
        }
        
        .ihd-attendance-stats {
            grid-template-columns: 1fr;
        }
        
        .ihd-attendance-stat-card {
            padding: 15px;
        }
        
        .ihd-attendance-stat-card p {
            font-size: 20px;
        }
        
        .ihd-attendance-table {
            font-size: 11px;
            min-width: 650px;
        }
        
        .ihd-attendance-table th,
        .ihd-attendance-table td {
            padding: 6px 4px;
        }
        
        .ihd-attendance-table th:nth-child(1),
        .ihd-attendance-table td:nth-child(1) {
            width: 100px;
            min-width: 100px;
        }
        
        .ihd-attendance-table th:nth-child(2),
        .ihd-attendance-table td:nth-child(2) {
            width: 80px;
            min-width: 80px;
        }
        
        .ihd-attendance-table th:nth-child(7),
        .ihd-attendance-table td:nth-child(7) {
            width: 120px;
            min-width: 120px;
            max-width: 150px;
        }
        
        .ihd-mark-attendance-btn,
        .ihd-logout-btn {
            width: 100%;
            margin: 5px 0;
        }
    }

    @media (max-width: 480px) {
        .ihd-attendance-section {
            padding: 12px;
        }
        
        .ihd-attendance-card {
            padding: 15px;
        }
        .table-container{
            overflow-x:auto;
        }
        .ihd-attendance-card h3 {
            font-size: 15px;
        }
        
        .ihd-attendance-stat-card {
            padding: 12px;
        }
        
        .ihd-attendance-stat-card p {
            font-size: 18px;
        }
        
        .ihd-attendance-table {
            font-size: 10px;
            min-width: 600px;
        }
        
        .ihd-attendance-table th,
        .ihd-attendance-table td {
            padding: 5px 3px;
        }
        
        .ihd-attendance-table th:nth-child(1),
        .ihd-attendance-table td:nth-child(1) {
            width: 90px;
            min-width: 90px;
        }
        
        .ihd-attendance-table th:nth-child(7),
        .ihd-attendance-table td:nth-child(7) {
            width: 100px;
            min-width: 100px;
            max-width: 120px;
        }
    }

    /* Focus states for accessibility */
    .ihd-mark-attendance-btn:focus,
    .ihd-logout-btn:focus,
    .ihd-attendance-filters input:focus,
    .ihd-attendance-filters select:focus,
    .ihd-attendance-filters button:focus {
        outline: 2px solid #3498db;
        outline-offset: 2px;
    }

    /* Print styles */
    @media print {
        .ihd-attendance-card,
        .ihd-attendance-filters,
        .ihd-mark-attendance-btn,
        .ihd-logout-btn {
            display: none;
        }
        
        .ihd-attendance-section {
            border: 1px solid #000;
        }
        
        .ihd-attendance-table {
            border: 1px solid #000;
        }
        
        .ihd-attendance-table-container {
            overflow: visible;
        }
    }

    /* No results styling */
    .ihd-attendance-section .text-center {
        text-align: center;
        padding: 40px 20px;
        color: #7f8c8d;
    }

    .ihd-attendance-section .text-center p {
        margin: 0;
        font-size: 14px;
    }
    </style>
    <div class="ihd-attendance-section">
        <h3>📅 Daily Attendance</h3>
        
        <?php foreach ($messages as $m) echo $m; ?>

        <!-- Attendance Marking Card -->
        <div class="ihd-attendance-card <?php echo $has_today_attendance && !$has_logged_out ? 'logout-card' : ''; ?>">
            <?php if (!$has_today_attendance): ?>
                <h3>Mark Your Attendance with Precise Location</h3>
                <p>📍 Office: <?php echo ihd_get_office_location()['office_name']; ?></p>
                <p>⏰ Current Time: <?php echo current_time('M j, Y g:i A'); ?></p>
                <p>🌐 Your IP: <?php echo $_SERVER['REMOTE_ADDR']; ?></p>
                
                <button type="button" id="ihd-mark-attendance-precise" class="ihd-mark-attendance-btn">
                    📍 Mark Attendance with Location
                </button>
            <?php elseif ($has_today_attendance && !$has_logged_out): ?>
                <h3>Mark Your Logout with Precise Location</h3>
                <p>📍 Office: <?php echo ihd_get_office_location()['office_name']; ?></p>
                <p>⏰ Login Time: <?php echo date('g:i A', strtotime($today_attendance->attendance_time)); ?></p>
                <p>🌐 Your IP: <?php echo $_SERVER['REMOTE_ADDR']; ?></p>
                
                <button type="button" id="ihd-mark-logout-precise" class="ihd-logout-btn">
                    🚪 Mark Logout with Location
                </button>
            <?php else: ?>
                <h3>Attendance Completed for Today</h3>
                <p>✅ Login: <?php echo date('g:i A', strtotime($today_attendance->attendance_time)); ?></p>
                <p>✅ Logout: <?php echo date('g:i A', strtotime($today_attendance->logout_time)); ?></p>
                <p>📍 Office: <?php echo ihd_get_office_location()['office_name']; ?></p>
                
                <button type="button" class="ihd-mark-attendance-btn" disabled>
                    ✅ Attendance Completed
                </button>
            <?php endif; ?>
            
            <div id="ihd-attendance-status" style="margin-top: 15px; display: none;"></div>
            
            <p style="font-size: 0.9em; margin-top: 3%; opacity: 0.8;">
                💡 This will ask for location permission and use precise GPS coordinates for verification.
            </p>
        </div>

        <!-- Current Status Display -->
        <?php if ($has_today_attendance): ?>
            <div class="attendance-status">
                <h4>Today's Attendance Status</h4>
                <p><strong>Login Time:</strong> <?php echo date('g:i A', strtotime($today_attendance->attendance_time)); ?></p>
                <?php if ($has_logged_out): ?>
                    <p><strong>Logout Time:</strong> <?php echo date('g:i A', strtotime($today_attendance->logout_time)); ?></p>
                    <p><strong>Status:</strong> <span style="color: #27ae60; font-weight: bold;">✅ Completed</span></p>
                <?php else: ?>
                    <p><strong>Logout Time:</strong> <span style="color: #e74c3c; font-weight: bold;">Pending</span></p>
                    <p><strong>Status:</strong> <span style="color: #e67e22; font-weight: bold;">🟡 Currently Logged In</span></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Attendance Statistics -->
        <?php
        // Get attendance statistics
        $total_attendance = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $attendance_table WHERE trainer_id = %d AND attendance_date BETWEEN %s AND %s",
            $user->ID, $start_date, $end_date
        ));
        
        $present_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $attendance_table WHERE trainer_id = %d AND location_status = 'present' AND attendance_date BETWEEN %s AND %s",
            $user->ID, $start_date, $end_date
        ));
        
        $completed_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $attendance_table WHERE trainer_id = %d AND logout_time IS NOT NULL AND attendance_date BETWEEN %s AND %s",
            $user->ID, $start_date, $end_date
        ));
        
        $total_days = round((strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24)) + 1;
        $attendance_percentage = $total_days > 0 ? round(($present_count / $total_days) * 100) : 0;
        $completion_percentage = $present_count > 0 ? round(($completed_count / $present_count) * 100) : 0;
        ?>
        
        <div class="ihd-attendance-stats">
            <div class="ihd-attendance-stat-card">
                <h4>Total Days</h4>
                <p><?php echo intval($total_days); ?></p>
            </div>
            <div class="ihd-attendance-stat-card">
                <h4>Present</h4>
                <p style="color: #27ae60;"><?php echo intval($present_count); ?></p>
            </div>
            <div class="ihd-attendance-stat-card">
                <h4>Completed</h4>
                <p style="color: #3498db;"><?php echo intval($completed_count); ?></p>
            </div>
            <div class="ihd-attendance-stat-card">
                <h4>Completion %</h4>
                <p style="color: #9b59b6;"><?php echo intval($completion_percentage); ?>%</p>
            </div>
        </div>

        <!-- Rest of your existing code for filters and history table remains the same -->
        <!-- Date Range Filter -->
        <div class="ihd-attendance-filters">
            <h4>📅 Filter Attendance History</h4>
            <form method="get" class="ihd-filter-grid">
                <input type="hidden" name="tab" value="attendance">
                <div class="ihd-filter-group">
                    <label for="attendance_start_date">Start Date</label>
                    <input type="date" id="attendance_start_date" name="attendance_start_date" value="<?php echo esc_attr($start_date); ?>" required>
                </div>
                <div class="ihd-filter-group">
                    <label for="attendance_end_date">End Date</label>
                    <input type="date" id="attendance_end_date" name="attendance_end_date" value="<?php echo esc_attr($end_date); ?>" required>
                </div>
                <div class="ihd-filter-group">
                    <button type="submit" style="width: 100%; padding: 3%; background: #3498db; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600;">
                        🔍 Filter Attendance
                    </button>
                </div>
                <div class="ihd-filter-group">
                    <a href="?tab=attendance" style="display: block; padding: 3%; background: #95a5a6; color: white; text-align: center; text-decoration: none; border-radius: 8px; font-weight: 600;">
                        🔄 Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Attendance History -->
        <?php
        $attendance_history = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $attendance_table 
             WHERE trainer_id = %d 
             AND attendance_date BETWEEN %s AND %s 
             ORDER BY attendance_date DESC",
            $user->ID, $start_date, $end_date
        ));
        ?>

        <?php if (!empty($attendance_history)): ?>
            <h4>📋 Attendance History</h4>
            
            <div class="table-container">
                <table class="ihd-attendance-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th >Day</th>
                            <th>Login Time</th>
                            <th>Logout Time</th>
                            <th>Status</th>
                            <th>IP Address</th>
                            <th>Location</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendance_history as $record): 
                            $day_name = date('l', strtotime($record->attendance_date));
                            $status_class = $record->location_status === 'present' ? 'attendance-present' : 'attendance-absent';
                            $logout_display = $record->logout_time ? '<span class="logout-time">' . date('g:i A', strtotime($record->logout_time)) . '</span>' : '<span style="color: #e74c3c;">Pending</span>';
                        ?>
                        <tr>
                            <td><strong><?php echo date('M j, Y', strtotime($record->attendance_date)); ?></strong></td>
                            <td><?php echo esc_html($day_name); ?></td>
                            <td><?php echo date('g:i A', strtotime($record->attendance_time)); ?></td>
                            <td><?php echo $logout_display; ?></td>
                            <td class="<?php echo $status_class; ?>">
                                <?php echo $record->logout_time ? '✅ Completed' : '🟡 Logged In'; ?>
                            </td>
                            <td><code><?php echo esc_html($record->ip_address); ?></code></td>
                            <td><?php echo esc_html($record->notes ?: 'Office premises'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 8%; color: #7f8c8d;">
                <p>📭 No attendance records found for the selected date range.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const attendanceBtn = document.getElementById('ihd-mark-attendance-precise');
        const logoutBtn = document.getElementById('ihd-mark-logout-precise');
        const statusDiv = document.getElementById('ihd-attendance-status');

        // Attendance marking function (your existing code)
        if (attendanceBtn) {
            attendanceBtn.addEventListener('click', function() {
                markAttendanceWithPreciseLocation('login');
            });
        }

        // Logout marking function
        if (logoutBtn) {
            logoutBtn.addEventListener('click', function() {
                markAttendanceWithPreciseLocation('logout');
            });
        }

        function markAttendanceWithPreciseLocation(action) {
            const button = action === 'login' ? attendanceBtn : logoutBtn;
            const originalText = button.textContent;
            const actionText = action === 'login' ? 'Attendance' : 'Logout';

            // Show loading state
            button.disabled = true;
            button.textContent = action === 'login' ? 'Getting your location...' : 'Getting location for logout...';
            statusDiv.style.display = 'block';
            statusDiv.innerHTML = '<p style="color: #e67e22;">🔄 Getting your precise location...</p>';

            if (!navigator.geolocation) {
                showError('Geolocation is not supported by this browser.');
                return;
            }

            const options = {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 0
            };

            navigator.geolocation.getCurrentPosition(
                async (position) => {
                    const locationData = {
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                        accuracy: position.coords.accuracy
                    };

                    console.log(actionText + ' Location obtained:', locationData);

                    // Show location found
                    button.textContent = action === 'login' ? 'Location found! Marking attendance...' : 'Location found! Marking logout...';
                    statusDiv.innerHTML = '<p style="color: #3498db;">📍 Location found! Accuracy: ' + Math.round(locationData.accuracy) + ' meters. Marking ' + actionText.toLowerCase() + '...</p>';

                    try {
                        // Determine the AJAX action
                        const ajaxAction = action === 'login' ? 'ihd_mark_precise_attendance' : 'ihd_mark_trainer_logout';
                        
                        // Send to server via AJAX
                        const formData = new URLSearchParams();
                        formData.append('action', ajaxAction);
                        formData.append('latitude', locationData.latitude);
                        formData.append('longitude', locationData.longitude);
                        formData.append('accuracy', locationData.accuracy);
                        formData.append('nonce', '<?php echo wp_create_nonce('ihd_attendance_nonce'); ?>');

                        console.log('Sending ' + actionText + ' AJAX request...');

                        const response = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: formData
                        });

                        console.log(actionText + ' Response received:', response);

                        if (!response.ok) {
                            throw new Error('Network response was not ok: ' + response.status);
                        }

                        const result = await response.json();
                        console.log(actionText + ' AJAX result:', result);

                        if (result.success) {
                            // Success
                            if (action === 'login') {
                                button.textContent = '✅ Attendance Marked!';
                                button.style.background = 'linear-gradient(135deg, #27ae60, #229954)';
                            } else {
                                button.textContent = '✅ Logout Marked!';
                                button.style.background = 'linear-gradient(135deg, #27ae60, #229954)';
                            }
                            button.disabled = true;
                            statusDiv.innerHTML = '<p style="color: #27ae60;">✅ ' + result.data.message + '</p>';

                            // Reload page after 2 seconds to show updated status
                            setTimeout(() => {
                                window.location.reload();
                            }, 2000);
                        } else {
                            // Server returned error
                            throw new Error(result.data);
                        }

                    } catch (error) {
                        console.error(actionText + ' AJAX Error:', error);
                        showError('Failed to mark ' + actionText.toLowerCase() + ': ' + error.message, button, originalText);
                    }
                },
                (error) => {
                    console.error('Geolocation Error:', error);
                    let errorMessage = 'Unknown error occurred';

                    switch(error.code) {
                        case error.PERMISSION_DENIED:
                            errorMessage = 'Location permission denied. Please allow location access in your browser settings.';
                            break;
                        case error.POSITION_UNAVAILABLE:
                            errorMessage = 'Location information is unavailable. Please check your GPS signal.';
                            break;
                        case error.TIMEOUT:
                            errorMessage = 'Location request timed out. Please try again.';
                            break;
                    }
                    showError(errorMessage, button, originalText);
                },
                options
            );
        }

        function showError(message, button, originalText) {
            console.error('Showing error:', message);
            if (button) {
                button.disabled = false;
                button.textContent = originalText;
            }
            statusDiv.innerHTML = '<p style="color: #e74c3c;">❌ ' + message + '</p>';

            // Auto-hide error after 10 seconds
            setTimeout(() => {
                statusDiv.style.display = 'none';
            }, 10000);
        }
    });
    </script>
    
    <?php
    return ob_get_clean();
}

// Enhanced function to check trainer active class
function ihd_check_trainer_active_class($trainer_id) {
        global $wpdb;

        // Verify table exists
        $batch_table = $wpdb->prefix . 'ihd_batch_sessions';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$batch_table'");

        if (!$table_exists) {
            error_log("IHD: Batch table doesn't exist");
            return false;
        }

        $active_batch = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $batch_table WHERE trainer_id = %d AND status = 'active' LIMIT 1",
            $trainer_id
        ));

        error_log("IHD: Checking active class for trainer $trainer_id - Found: " . ($active_batch ? 'YES' : 'NO'));

        return $active_batch;
}

    // Safe function to generate admin meeting link
function ihd_generate_admin_meeting_link($batch) {
        if (!$batch || !isset($batch->meeting_id) || !isset($batch->batch_id)) {
            error_log("IHD: Invalid batch data for meeting link");
            return '#';
        }

        $link = home_url('/adminjoin') . '?' . http_build_query(array(
            'meeting_join' => $batch->meeting_id,
            'batch_id' => $batch->batch_id,
            'admin_access' => 'true'
        ));

        error_log("IHD: Generated admin link: " . $link);
        return $link;
}



// Get class sessions for a student
function ihd_get_student_class_sessions($student_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ihd_class_sessions';
    
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name WHERE student_id = %d ORDER BY start_time DESC",
        $student_id
    ));
}

// Add this to your existing plugin file
function ihd_verify_class_sessions_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ihd_class_sessions';
    
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
    
    if (!$table_exists) {
        // Recreate the table
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            student_id mediumint(9) NOT NULL,
            trainer_id mediumint(9) NOT NULL,
            start_time datetime DEFAULT CURRENT_TIMESTAMP,
            end_time datetime NULL,
            duration_minutes int DEFAULT 0,
            recording_path varchar(255) DEFAULT '',
            meeting_id varchar(100) DEFAULT '',
            status varchar(20) DEFAULT 'active',
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        error_log("IHD: Recreated missing table $table_name");
    }
}

// Call this function in your activation hook or when needed
add_action('init', 'ihd_verify_class_sessions_table');
// Verify database tables and fix issues
function ihd_verify_and_fix_database_tables() {
    global $wpdb;
    
    if (isset($_GET['fix_db']) && current_user_can('administrator')) {
        echo '<div class="notice notice-info">';
        echo '<h3>🔧 Database Verification & Fix</h3>';
        
        $progress_table = $wpdb->prefix . 'ihd_student_progress';
        $batch_table = $wpdb->prefix . 'ihd_batch_sessions';
        $session_table = $wpdb->prefix . 'ihd_class_sessions';
        
        // Check table structures
        $tables = array($progress_table, $batch_table, $session_table);
        
        foreach ($tables as $table) {
            $exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
            if ($exists) {
                echo "<p>✅ Table exists: $table</p>";
                
                // Check columns for progress table
                if ($table === $progress_table) {
                    $columns = $wpdb->get_results("SHOW COLUMNS FROM $table");
                    $has_notes = false;
                    $has_class_minutes = false;
                    
                    foreach ($columns as $column) {
                        if ($column->Field == 'notes') $has_notes = true;
                        if ($column->Field == 'class_minutes') $has_class_minutes = true;
                    }
                    
                    if (!$has_notes) {
                        $wpdb->query("ALTER TABLE $table ADD COLUMN notes TEXT");
                        echo "<p>✅ Added 'notes' column to $table</p>";
                    }
                    
                    if (!$has_class_minutes) {
                        $wpdb->query("ALTER TABLE $table ADD COLUMN class_minutes INT DEFAULT 0");
                        echo "<p>✅ Added 'class_minutes' column to $table</p>";
                    }
                }
            } else {
                echo "<p>❌ Table missing: $table</p>";
            }
        }
        
        echo '</div>';
    }
}
add_action('init', 'ihd_verify_and_fix_database_tables');
// Debug progress data saving
function ihd_debug_progress_saving() {
    if (isset($_GET['debug_progress_save']) && current_user_can('administrator')) {
        global $wpdb;
        
        echo '<div class="notice notice-info">';
        echo '<h3>🐛 Progress Saving Debug</h3>';
        
        // Log recent POST data
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo '<h4>Recent POST Data:</h4>';
            echo '<pre>' . print_r($_POST, true) . '</pre>';
        }
        
        // Check last progress entries
        $progress_table = $wpdb->prefix . 'ihd_student_progress';
        $last_entries = $wpdb->get_results("SELECT * FROM $progress_table ORDER BY id DESC LIMIT 5");
        
        echo '<h4>Last 5 Progress Entries:</h4>';
        foreach ($last_entries as $entry) {
            echo '<p>';
            echo 'ID: ' . $entry->id . ' | ';
            echo 'Student: ' . $entry->student_id . ' | ';
            echo 'Date: ' . $entry->completion_date . ' | ';
            echo 'Progress: ' . $entry->completion_percentage . '% | ';
            echo 'Minutes: ' . $entry->class_minutes . ' | ';
            echo 'Notes: "' . esc_html($entry->notes) . '" | ';
            echo 'Updated: ' . $entry->updated_at;
            echo '</p>';
        }
        
        echo '</div>';
    }
}
add_action('init', 'ihd_debug_progress_saving');
// Enqueue necessary scripts for video conferencing
function ihd_enqueue_video_conferencing_scripts() {
    if (is_page() && (has_shortcode(get_post()->post_content, 'trainer_meeting') || has_shortcode(get_post()->post_content, 'student_meeting_join'))) {
        // PeerJS for WebRTC
        wp_enqueue_script('peerjs', 'https://unpkg.com/peerjs@1.4.7/dist/peerjs.min.js', array(), '1.4.7', true);
        
        // Our custom video conferencing script
        wp_enqueue_script('ihd-video-conferencing', plugin_dir_url(__FILE__) . 'assets/video-conferencing.js', array('jquery', 'peerjs'), '1.0', true);
        
        // Styles
        wp_enqueue_style('ihd-video-conferencing', plugin_dir_url(__FILE__) . 'assets/video-conferencing.css', array(), '1.0');
        
        // Localize script for AJAX
        wp_localize_script('ihd-video-conferencing', 'ihd_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ihd_video_nonce')
        ));
    }
}
add_action('wp_enqueue_scripts', 'ihd_enqueue_video_conferencing_scripts');

// AJAX handler for meeting data
function ihd_get_meeting_data() {
    check_ajax_referer('ihd_video_nonce', 'nonce');
    
    $meeting_id = sanitize_text_field($_POST['meeting_id']);
    $student_id = intval($_POST['student_id']);
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'ihd_class_sessions';
    
    $session = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE meeting_id = %s AND student_id = %d AND status = 'active'",
        $meeting_id, $student_id
    ));
    
    if ($session) {
        wp_send_json_success(array(
            'trainer_id' => $session->trainer_id,
            'meeting_id' => $session->meeting_id,
            'start_time' => $session->start_time
        ));
    } else {
        wp_send_json_error('Meeting not found or inactive');
    }
}
add_action('wp_ajax_ihd_get_meeting_data', 'ihd_get_meeting_data');
add_action('wp_ajax_nopriv_ihd_get_meeting_data', 'ihd_get_meeting_data');

// AJAX handler for chat messages
function ihd_send_chat_message() {
    check_ajax_referer('ihd_video_nonce', 'nonce');
    
    $meeting_id = sanitize_text_field($_POST['meeting_id']);
    $message = sanitize_text_field($_POST['message']);
    $sender = sanitize_text_field($_POST['sender']);
    $sender_name = sanitize_text_field($_POST['sender_name']);
    
    // Store message in transient (you might want to use a proper table for production)
    $chat_key = 'ihd_chat_' . $meeting_id;
    $chat_messages = get_transient($chat_key) ?: array();
    
    $chat_messages[] = array(
        'timestamp' => current_time('mysql'),
        'sender' => $sender,
        'sender_name' => $sender_name,
        'message' => $message
    );
    
    // Keep only last 100 messages
    if (count($chat_messages) > 100) {
        $chat_messages = array_slice($chat_messages, -100);
    }
    
    set_transient($chat_key, $chat_messages, 12 * HOUR_IN_SECONDS);
    
    wp_send_json_success('Message sent');
}
add_action('wp_ajax_ihd_send_chat_message', 'ihd_send_chat_message');
add_action('wp_ajax_nopriv_ihd_send_chat_message', 'ihd_send_chat_message');

// AJAX handler for getting chat messages
function ihd_get_chat_messages() {
    check_ajax_referer('ihd_video_nonce', 'nonce');
    
    $meeting_id = sanitize_text_field($_POST['meeting_id']);
    $chat_key = 'ihd_chat_' . $meeting_id;
    $chat_messages = get_transient($chat_key) ?: array();
    
    wp_send_json_success($chat_messages);
}
add_action('wp_ajax_ihd_get_chat_messages', 'ihd_get_chat_messages');
add_action('wp_ajax_nopriv_ihd_get_chat_messages', 'ihd_get_chat_messages');

// Add this to your existing AJAX handlers
function ihd_handle_user_join() {
    check_ajax_referer('ihd_video_nonce', 'nonce');
    
    $meeting_id = sanitize_text_field($_POST['meeting_id']);
    $user_name = sanitize_text_field($_POST['user_name']);
    $user_role = sanitize_text_field($_POST['user_role']);
    
    // Store participant info in transient
    $participants_key = 'ihd_participants_' . $meeting_id;
    $participants = get_transient($participants_key) ?: array();
    
    $participants[] = array(
        'name' => $user_name,
        'role' => $user_role,
        'joined_at' => current_time('mysql')
    );
    
    set_transient($participants_key, $participants, 12 * HOUR_IN_SECONDS);
    
    wp_send_json_success('User joined recorded');
}
add_action('wp_ajax_ihd_handle_user_join', 'ihd_handle_user_join');
add_action('wp_ajax_nopriv_ihd_handle_user_join', 'ihd_handle_user_join');

// Fix for timezone issue - ensure all dates use Indian timezone
function ihd_get_current_time() {
    return current_time('mysql',1);
}

// Enhanced student name matching function
function ihd_find_student_by_name($student_name, $batch_id) {
    // First try exact match
    $students = get_posts(array(
        'post_type' => 'student',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => 'current_batch_id',
                'value' => $batch_id,
                'compare' => '='
            )
        )
    ));
    
    foreach ($students as $student) {
        // Exact match
        if (strcasecmp(trim($student->post_title), trim($student_name)) === 0) {
            return $student;
        }
        
        // Partial match (first name only)
        $student_first_name = explode(' ', $student->post_title)[0];
        $input_first_name = explode(' ', $student_name)[0];
        
        if (strcasecmp(trim($student_first_name), trim($input_first_name)) === 0) {
            return $student;
        }
    }
    
    return false;
}

/* ---------------- NEW: Admin Dashboard Shortcode ---------------- */
function ihd_admin_dashboard_shortcode() {
    if (!is_user_logged_in() || !current_user_can('administrator')) {
        return '<p style="text-align: center; padding: 20px; color: #e74c3c; font-size: 1.2em;">Access denied. Only Administrators can access this dashboard.</p>';
    }

    $messages = array();
    $trainer_id = isset($_GET['trainer_id']) ? intval($_GET['trainer_id']) : 0;
    $student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
	// Fees management date filters
    $fees_start_date = sanitize_text_field($_GET['fees_start_date'] ?? date('Y-m-01'));
    $fees_end_date = sanitize_text_field($_GET['fees_end_date'] ?? date('Y-m-t'));

    // Validate dates
    if (!strtotime($fees_start_date) || !strtotime($fees_end_date)) {
        $fees_start_date = date('Y-m-01');
        $fees_end_date = date('Y-m-t');
    }
    // Handle actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Handle any admin actions here if needed
    }

    ob_start();
    ?>
    
    <style>
        /* Admin Dashboard Traditional Responsive Styles */
        .ihd-admin-dashboard {
            max-width: 100%;
            margin: 0 auto;
            padding: 20px 15px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            box-sizing: border-box;
            font-size: 14px;
        }

        /* Fees Management Styles */
        .fees-collection-card {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 25px;
            border-radius: 8px;
            text-align: center;
            margin: 15px 0;
            border: none;
        }

        .fees-collection-card h3 {
            margin: 0 0 10px 0;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .fees-collection-card .amount {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 10px 0;
        }

        .fees-collection-card .period {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        /* Course collection highlights */
        .course-high {
            background: #e8f6f3 !important;
            border-left: 4px solid #27ae60 !important;
        }

        .course-medium {
            background: #fff9e6 !important;
            border-left: 4px solid #f39c12 !important;
        }

        .course-low {
            background: #fefefe !important;
            border-left: 4px solid #bdc3c7 !important;
        }

        .ihd-admin-dashboard h2 {
            text-align: center;
            margin-bottom: 25px;
            color: #2c3e50;
            font-size: 24px;
            font-weight: 600;
            padding-bottom: 15px;
            border-bottom: 2px solid #3498db;
        }

        .ihd-admin-dashboard h3 {
            margin: 25px 0 15px 0;
            color: #34495e;
            font-size: 18px;
            font-weight: 600;
            padding-bottom: 10px;
            border-bottom: 1px solid #bdc3c7;
        }

        .ihd-admin-dashboard h4 {
            margin: 20px 0 10px 0;
            color: #2c3e50;
            font-size: 15px;
            font-weight: 600;
        }

        /* Messages */
        .ihd-admin-dashboard .error {
            background: #ffe6e6;
            border: 1px solid #ffcccc;
            border-radius: 4px;
            padding: 12px 15px;
            margin-bottom: 15px;
            color: #cc0000;
            font-size: 13px;
            border-left: 4px solid #ff3333;
        }

        .ihd-admin-dashboard .updated {
            background: #e6ffe6;
            border: 1px solid #ccffcc;
            border-radius: 4px;
            padding: 12px 15px;
            margin-bottom: 15px;
            color: #006600;
            font-size: 13px;
            border-left: 4px solid #33cc33;
        }

        /* Back button */
        .ihd-admin-back-btn {
            display: inline-block;
            margin: 15px 0;
            padding: 10px 20px;
            background: #95a5a6;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
            border: 1px solid #7f8c8d;
        }

        .ihd-admin-back-btn:hover {
            background: #7f8c8d;
            border-color: #6c7a7d;
            color: white;
            text-decoration: none;
        }

        /* Stats Grid */
        .ihd-admin-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .ihd-admin-stats-card {
            background: white;
            padding: 20px;
            border-radius: 6px;
            border: 1px solid #ddd;
            border-left: 4px solid #3498db;
            text-align: center;
            transition: transform 0.2s ease;
        }

        .ihd-admin-stats-card:hover {
            transform: translateY(-2px);
        }

        .ihd-admin-stats-card h4 {
            margin: 0 0 10px 0;
            color: #7f8c8d;
            font-size: 13px;
            font-weight: 600;
        }

        .ihd-admin-stats-card p {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
            color: #3498db;
        }

        /* Traditional Tables */
        .ihd-admin-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            background: #fff;
            border: 1px solid #ddd;
            font-size: 12px;
            table-layout: fixed;
        }

        .ihd-admin-table thead {
            background: #2c3e50;
        }

        .ihd-admin-table th {
            color: white;
            padding: 10px 8px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            border-right: 1px solid #34495e;
        }

        .ihd-admin-table th:last-child {
            border-right: none;
        }

        .ihd-admin-table td {
            padding: 8px;
            border-bottom: 1px solid #ecf0f1;
            vertical-align: top;
            border-right: 1px solid #ecf0f1;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .ihd-admin-table td:last-child {
            border-right: none;
        }

        .ihd-admin-table tbody tr:hover {
            background: #f8f9fa;
        }

        .ihd-admin-table tbody tr:nth-child(even) {
            background: #fafbfc;
        }

        /* Buttons */
        .ihd-admin-btn {
            background: #3498db;
            color: white;
            padding: 8px 16px;
            text-decoration: none;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
            border: 1px solid #2980b9;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
            min-width: 100px;
            height: 32px;
            box-sizing: border-box;
        }

        .ihd-admin-btn:hover {
            background: #2980b9;
            border-color: #2471a3;
            color: white;
            text-decoration: none;
        }

        .ihd-admin-view-btn {
            background: #27ae60;
            border-color: #229954;
        }

        .ihd-admin-view-btn:hover {
            background: #229954;
            border-color: #1e8449;
        }

        /* Progress bars */
        .ihd-admin-progress-container {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .ihd-admin-progress-bar {
            width: 80px;
            height: 6px;
            background: #ecf0f1;
            border-radius: 3px;
            overflow: hidden;
        }

        .ihd-admin-progress-fill {
            height: 100%;
            background: #3498db;
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .ihd-admin-last-updated {
            font-size: 10px;
            color: #7f8c8d;
            display: block;
            margin-top: 2px;
        }

        /* Table Container */
        .ihd-admin-table-container {
            overflow-x: auto;
            border-radius: 6px;
            margin: 15px 0;
            -webkit-overflow-scrolling: touch;
            border: 1px solid #ddd;
        }

        /* Session History */
        .ihd-admin-session-item {
            background: #f8f9fa;
            padding: 12px 15px;
            border-radius: 4px;
            margin-bottom: 10px;
            border-left: 4px solid #3498db;
            border: 1px solid #e9ecef;
        }

        .ihd-admin-session-date {
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
            font-size: 13px;
        }

        .ihd-admin-session-duration {
            color: #27ae60;
            font-weight: 600;
            font-size: 12px;
        }

        /* Progress Notes Form Styles */
        .ihd-progress-notes-form {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            padding: 15px;
            margin: 15px 0;
        }

        .ihd-progress-notes-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
            font-size: 11px;
        }

        .ihd-progress-notes-table th {
            background: #2c3e50;
            color: white;
            padding: 8px 6px;
            text-align: left;
            font-weight: 600;
            border-right: 1px solid #34495e;
        }

        .ihd-progress-notes-table th:last-child {
            border-right: none;
        }

        .ihd-progress-notes-table td {
            padding: 6px;
            border: 1px solid #dee2e6;
            background: white;
            border-right: 1px solid #dee2e6;
        }

        .ihd-progress-notes-table td:last-child {
            border-right: none;
        }

        .ihd-progress-input {
            width: 60px;
            padding: 4px 6px;
            border: 1px solid #ced4da;
            border-radius: 3px;
            font-size: 11px;
            height: 28px;
            box-sizing: border-box;
        }

        .ihd-notes-input {
            width: 100%;
            padding: 4px 6px;
            border: 1px solid #ced4da;
            border-radius: 3px;
            font-size: 11px;
            height: 28px;
            box-sizing: border-box;
        }

        /* Class Time Badges */
        .ihd-class-time-badge {
            background: #e67e22;
            color: white;
            padding: 3px 6px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 600;
        }

        /* Date Filter Styles */
        .ihd-date-filter {
            background: white;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #ddd;
            margin: 15px 0;
        }

        .ihd-filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            align-items: end;
        }

        .ihd-filter-grid label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #495057;
            font-size: 12px;
        }

        .ihd-filter-grid input {
            padding: 8px 10px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 13px;
            width: 100%;
            box-sizing: border-box;
            height: 38px;
        }

        .ihd-filter-grid button,
        .ihd-filter-grid a {
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            height: 38px;
            box-sizing: border-box;
        }

        .ihd-filter-grid button {
            background: #3498db;
            color: white;
            border: 1px solid #2980b9;
        }

        .ihd-filter-grid button:hover {
            background: #2980b9;
        }

        .ihd-filter-grid a {
            background: #95a5a6;
            color: white;
            border: 1px solid #7f8c8d;
        }

        .ihd-filter-grid a:hover {
            background: #7f8c8d;
            color: white;
            text-decoration: none;
        }

        /* Status Badges */
        .ihd-trainer-status {
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 600;
            display: inline-block;
            text-align: center;
            min-width: 80px;
        }

        .ihd-status-live {
            background: #e74c3c;
            color: white;
            animation: pulse 2s infinite;
        }

        .ihd-status-offline {
            background: #95a5a6;
            color: white;
        }

        .ihd-join-class-btn {
            background: #27ae60 !important;
            border-color: #229954 !important;
            margin-bottom: 5px;
            display: block;
            text-align: center;
            width: 100%;
        }

        .ihd-join-class-btn:hover {
            background: #229954 !important;
            border-color: #1e8449 !important;
        }

        .ihd-no-class-btn {
            background: #95a5a6 !important;
            border-color: #7f8c8d !important;
            margin-bottom: 5px;
            display: block;
            text-align: center;
            width: 100%;
            cursor: not-allowed;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }

        /* Small text */
        small {
            font-size: 10px;
            color: #7f8c8d;
        }

        /* Code styling */
        code {
            background: #f8f9fa;
            padding: 2px 6px;
            border-radius: 3px;
            border: 1px solid #e9ecef;
            font-family: 'Courier New', monospace;
            font-size: 10px;
            color: #e74c3c;
        }

        /* No Results */
        .no-results {
            text-align: center;
            padding: 40px 20px;
            background: white;
            border-radius: 6px;
            color: #7f8c8d;
            border: 1px solid #ddd;
        }

        .no-results p {
            font-size: 14px;
            margin: 0;
        }

        /* Mobile-specific styles */
        @media (max-width: 1024px) {
            .ihd-admin-table {
                font-size: 11px;
            }

            .ihd-admin-table th,
            .ihd-admin-table td {
                padding: 6px 4px;
            }
        }

        @media (max-width: 768px) {
            .ihd-admin-dashboard {
                padding: 15px 10px;
                font-size: 13px;
            }

            .ihd-admin-dashboard h2 {
                font-size: 20px;
                margin-bottom: 20px;
            }

            .ihd-admin-dashboard h3 {
                font-size: 16px;
                margin: 20px 0 12px 0;
            }

            .ihd-admin-stats-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .ihd-admin-stats-card {
                padding: 15px;
            }

            .ihd-admin-stats-card p {
                font-size: 18px;
            }

            .ihd-admin-table {
                font-size: 11px;
                min-width: 800px;
            }

            .ihd-admin-table th,
            .ihd-admin-table td {
                padding: 8px 6px;
                white-space: nowrap;
            }

            .ihd-admin-btn {
                padding: 10px 12px;
                min-width: 80px;
                font-size: 11px;
                height: 36px;
            }

            .ihd-filter-grid {
                grid-template-columns: 1fr;
            }

            .ihd-admin-progress-bar {
                width: 60px;
            }
        }

        @media (max-width: 480px) {
            .ihd-admin-dashboard {
                padding: 10px 8px;
                font-size: 12px;
            }

            .ihd-admin-dashboard h2 {
                font-size: 18px;
            }

            .ihd-admin-dashboard h3 {
                font-size: 15px;
            }

            .ihd-admin-stats-card {
                padding: 12px;
            }

            .ihd-admin-stats-card p {
                font-size: 16px;
            }

            .ihd-admin-table {
                font-size: 10px;
            }

            .ihd-admin-table th,
            .ihd-admin-table td {
                padding: 6px 4px;
            }

            .ihd-admin-progress-bar {
                width: 50px;
            }

            .ihd-trainer-status {
                font-size: 9px;
                min-width: 70px;
                padding: 3px 6px;
            }

            .ihd-admin-back-btn {
                padding: 8px 16px;
                font-size: 12px;
            }
        }

        /* Print Styles */
        @media print {
            .ihd-admin-dashboard {
                background: white;
                padding: 0;
            }

            .ihd-admin-dashboard button,
            .ihd-admin-back-btn {
                display: none;
            }

            .ihd-admin-table {
                box-shadow: none;
                border: 1px solid #000;
            }

            .ihd-admin-stats-card {
                border: 1px solid #000;
            }
        }

        /* Utility Classes */
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .font-bold { font-weight: bold; }
        .font-normal { font-weight: normal; }
        .text-sm { font-size: 11px; }
        .text-xs { font-size: 10px; }

        /* Color Variations for Stats Cards */
        .stats-card-primary { border-left-color: #3498db; }
        .stats-card-warning { border-left-color: #e67e22; }
        .stats-card-success { border-left-color: #27ae60; }
        .stats-card-info { border-left-color: #9b59b6; }

        .stats-card-primary p { color: #3498db; }
        .stats-card-warning p { color: #e67e22; }
        .stats-card-success p { color: #27ae60; }
        .stats-card-info p { color: #9b59b6; }

        /* Focus States for Accessibility */
        .ihd-admin-btn:focus,
        .ihd-filter-grid input:focus,
        .ihd-filter-grid button:focus {
            outline: 2px solid #3498db;
            outline-offset: 2px;
        }

        /* Table Column Widths */
        .col-student-name { width: 150px; }
        .col-course { width: 120px; }
        .col-progress { width: 100px; }
        .col-hours { width: 80px; }
        .col-updated { width: 100px; }
        .col-actions { width: 100px; }

        .col-trainer-name { width: 120px; }
        .col-email { width: 150px; }
        .col-modules { width: 150px; }
        .col-active-students { width: 80px; }
        .col-completed-students { width: 80px; }
        .col-total-students { width: 80px; }
        .col-status { width: 100px; }
        .col-trainer-actions { width: 120px; }

        .col-date { width: 100px; }
        .col-progress-percent { width: 80px; }
        .col-class-time { width: 100px; }
        .col-updated-by { width: 100px; }
        .col-topics { width: 150px; }

        .col-session-date { width: 120px; }
        .col-duration { width: 80px; }
        .col-session-status { width: 80px; }
        .col-recording { width: 80px; }

        /* Animation for progress bars */
        @keyframes progressFill {
            from { width: 0%; }
            to { width: attr(data-width); }
        }

        .ihd-admin-progress-fill {
            animation: progressFill 1s ease-out;
        }

        /* Hover effects */
        .ihd-admin-table tbody tr {
            transition: background-color 0.2s ease;
        }

        .ihd-admin-table tbody tr:hover {
            background-color: #e3f2fd !important;
        }

        /* Form validation styles */
        .ihd-progress-input:invalid,
        .ihd-notes-input:invalid {
            border-color: #e74c3c;
            background-color: #ffe6e6;
        }

        .ihd-progress-input:valid,
        .ihd-notes-input:valid {
            border-color: #27ae60;
            background-color: #e6ffe6;
        }

        /* Tab Styles - ADDED MISSING STYLES */
        .ihd-admin-tabs {
            display: flex;
            border-bottom: 2px solid #3498db;
            margin-bottom: 20px;
            background: white;
            border-radius: 6px 6px 0 0;
            overflow: hidden;
        }

        .ihd-admin-tab {
            padding: 12px 20px;
            cursor: pointer;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-bottom: none;
            margin-right: 5px;
            border-radius: 4px 4px 0 0;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .ihd-admin-tab:hover {
            background: #e9ecef;
        }

        .ihd-admin-tab.ihd-active {
            background: #3498db;
            color: white;
            border-color: #3498db;
        }

        .ihd-admin-section {
            display: none;
        }

        .ihd-admin-section.active {
            display: block;
        }

        /* Status Badge Styles - ADDED MISSING STYLES */
        .ihd-trainer-status-badge {
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 600;
            display: inline-block;
            text-align: center;
            min-width: 60px;
        }

        .status-paid {
            background: #27ae60;
            color: white;
        }

        .status-pending {
            background: #f39c12;
            color: white;
        }
    </style>

    <div class="ihd-admin-dashboard">
        <h2>👑 Admin Dashboard</h2>

        <!-- Tabs -->
        <div class="ihd-admin-tabs" id="ihdAdminTabs">
            <div class="ihd-admin-tab <?php echo $active_tab === 'trainers-overview' ? 'ihd-active' : ''; ?>" data-target="trainers-overview">👨‍🏫 Trainers</div>
            <div class="ihd-admin-tab <?php echo $active_tab === 'fees-management' ? 'ihd-active' : ''; ?>" data-target="fees-management">💰 Fees Management</div>
            <div class="ihd-admin-tab <?php echo $active_tab === 'partial-payments' ? 'ihd-active' : ''; ?>" data-target="partial-payments">📋 Partial Payments</div>
        </div>
		<!-- Partial Payments Tab -->
        <div id="partial-payments" class="ihd-admin-section <?php echo $active_tab === 'partial-payments' ? 'active' : ''; ?>">
            <h3>📋 Partial Payments & Balance Tracking</h3>

            <!-- Date Range Filter -->
            <div class="ihd-date-filter">
                <h4>📅 Filter Partial Payments by Date Range</h4>
                <form method="get" class="ihd-filter-grid">
                    <input type="hidden" name="tab" value="partial-payments">
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">Start Date</label>
                        <input type="date" name="fees_start_date" value="<?php echo esc_attr($fees_start_date); ?>" style="width: 100%; padding: 10px;">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">End Date</label>
                        <input type="date" name="fees_end_date" value="<?php echo esc_attr($fees_end_date); ?>" style="width: 100%; padding: 10px;">
                    </div>
                    <div>
                        <button type="submit" style="width: 100%; padding: 10px; background: #3498db; color: white; border: none; border-radius: 5px; cursor: pointer;">
                            🔍 Filter Payments
                        </button>
                    </div>
                    <div>
                        <a href="?tab=partial-payments" style="display: block; padding: 10px; background: #95a5a6; color: white; text-align: center; text-decoration: none; border-radius: 5px;">
                            🔄 Reset
                        </a>
                    </div>
                </form>
            </div>

            <?php
            // Get fees collection data
            $fees_data = ihd_get_fees_collection_data($fees_start_date, $fees_end_date);
            $partial_payments_list = $fees_data['partial_payments_list'];
            $total_partial_amount = $fees_data['partial_payments'];
            $partial_students_count = $fees_data['partial_paid_students'];
            ?>

            <!-- Partial Payments Summary -->
            <div class="ihd-admin-stats-grid">
                <div class="ihd-admin-stats-card" style="border-left-color: #e67e22;">
                    <h4>💰 Partial Payments</h4>
                    <p style="color: #e67e22; font-size: 1.8rem;">₹<?php echo number_format($total_partial_amount, 2); ?></p>
                    <small style="color: #7f8c8d;">
                        <?php echo date('M j, Y', strtotime($fees_start_date)); ?> to <?php echo date('M j, Y', strtotime($fees_end_date)); ?>
                    </small>
                </div>
                <div class="ihd-admin-stats-card" style="border-left-color: #3498db;">
                    <h4>👥 Students with Partial</h4>
                    <p style="color: #3498db;"><?php echo $partial_students_count; ?></p>
                </div>
                <div class="ihd-admin-stats-card" style="border-left-color: #9b59b6;">
                    <h4>📋 Total Records</h4>
                    <p style="color: #9b59b6;"><?php echo count($partial_payments_list); ?></p>
                </div>
            </div>

            <!-- Partial Payments List -->
            <h4>📊 Recent Partial Payments</h4>
            <?php if (!empty($partial_payments_list)): ?>
                <div class="ihd-admin-table-container">
                    <table class="ihd-admin-table">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Course</th>
                                <th>Paid Amount</th>
                                <th>Total Fees</th>
                                <th>Balance</th>
                                <th>Payment Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($partial_payments_list as $payment): ?>
                            <tr>
                                <td><strong><?php echo esc_html($payment['student_name']); ?></strong></td>
                                <td><?php echo esc_html($payment['course_name']); ?></td>
                                <td>
                                    <span style="color: #e67e22; font-weight: bold; font-size: 1.1em;">
                                        ₹<?php echo number_format($payment['fees_paid'], 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="color: #2c3e50; font-weight: bold;">
                                        ₹<?php echo number_format($payment['total_fees'], 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="color: #e74c3c; font-weight: bold;">
                                        ₹<?php echo number_format($payment['balance'], 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="color: #7f8c8d; font-size: 0.9em;">
                                        <?php echo date('M j, Y', strtotime($payment['payment_date'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="ihd-trainer-status-badge status-pending">
                                        <?php echo ucfirst($payment['fee_status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 40px 20px; background: white; border-radius: 6px; color: #7f8c8d; border: 1px solid #ddd;">
                    <p>📭 No partial payments found for the selected period.</p>
                </div>
            <?php endif; ?>
        </div>
        <!-- Fees Management Tab -->
        <div id="fees-management" class="ihd-admin-section <?php echo $active_tab === 'fees-management' ? 'active' : ''; ?>">
            <h3>💰 Fees Management & Collection Report</h3>

            <!-- Date Range Filter -->
            <div class="ihd-date-filter">
                <h4>📅 Filter Collection by Date Range</h4>
                <form method="get" class="ihd-filter-grid">
                    <input type="hidden" name="tab" value="fees-management">
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">Start Date</label>
                        <input type="date" name="fees_start_date" value="<?php echo esc_attr($fees_start_date); ?>" style="width: 100%; padding: 10px;">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">End Date</label>
                        <input type="date" name="fees_end_date" value="<?php echo esc_attr($fees_end_date); ?>" style="width: 100%; padding: 10px;">
                    </div>
                    <div>
                        <button type="submit" style="width: 100%; padding: 10px; background: #3498db; color: white; border: none; border-radius: 5px; cursor: pointer;">
                            🔍 Filter Collection
                        </button>
                    </div>
                    <div>
                        <a href="?tab=fees-management" style="display: block; padding: 10px; background: #95a5a6; color: white; text-align: center; text-decoration: none; border-radius: 5px;">
                            🔄 Reset
                        </a>
                    </div>
                </form>
            </div>

            <?php
            // Get fees collection data
            $fees_data = ihd_get_fees_collection_data($fees_start_date, $fees_end_date);
            $total_collection = $fees_data['total_collection'];
            $course_collections = $fees_data['course_collections'];
            ?>

            <!-- Overall Collection Summary -->
            <div class="ihd-admin-stats-grid">
                <div class="ihd-admin-stats-card" style="border-left-color: #27ae60;">
                    <h4>💰 Total Collection</h4>
                    <p style="color: #27ae60; font-size: 1.8rem;">₹<?php echo number_format($total_collection, 2); ?></p>
                    <small style="color: #7f8c8d;">
                        <?php echo date('M j, Y', strtotime($fees_start_date)); ?> to <?php echo date('M j, Y', strtotime($fees_end_date)); ?>
                    </small>
                </div>
                <div class="ihd-admin-stats-card" style="border-left-color: #e67e22;">
                    <h4>📥 Partial Payments</h4>
                    <p style="color: #e67e22;">₹<?php echo number_format($fees_data['partial_payments'], 2); ?></p>
                    <small style="color: #7f8c8d;"><?php echo $fees_data['partial_paid_students']; ?> students</small>
                </div>
                <div class="ihd-admin-stats-card" style="border-left-color: #e74c3c;">
                    <h4>⏳ Pending Payments</h4>
                    <p style="color: #e74c3c;">₹<?php echo number_format($fees_data['total_pending'], 2); ?></p>
                    <small style="color: #7f8c8d;"><?php echo $fees_data['pending_students_count']; ?> students</small>
                </div>
                <div class="ihd-admin-stats-card" style="border-left-color: #3498db;">
                    <h4>📊 Total Courses</h4>
                    <p style="color: #3498db;"><?php echo count($course_collections); ?></p>
                </div>
                <div class="ihd-admin-stats-card" style="border-left-color: #9b59b6;">
                    <h4>👥 Paid Students</h4>
                    <p style="color: #9b59b6;"><?php echo $fees_data['total_paid_students']; ?></p>
                </div>
            </div>

            <!-- Course-wise Collection -->
            <!-- Course-wise Collection -->
            <h4>📈 Course-wise Collection (Highest to Lowest)</h4>
            <div class="ihd-admin-table-container">
                <table class="ihd-admin-table">
                    <thead>
                        <tr>
                            <th>Course Name</th>
                            <th>Total Collection</th>
                            <th>Partial Payments</th>
                            <th>Full Payments</th>
                            <th>Paid Students</th>
                            <th>Average Fee</th>
                            <th>Last Payment</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($course_collections)): ?>
                            <?php foreach ($course_collections as $course): ?>
                            <tr>
                                <td><strong><?php echo esc_html($course['course_name']); ?></strong></td>
                                <td>
                                    <span style="color: #27ae60; font-weight: bold; font-size: 1.1em;">
                                        ₹<?php echo number_format($course['total_fees'], 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="color: #e67e22; font-weight: bold;">
                                        ₹<?php echo number_format($course['partial_payments'], 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="color: #3498db; font-weight: bold;">
                                        ₹<?php echo number_format($course['full_payments'], 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="color: #9b59b6; font-weight: bold;">
                                        <?php echo $course['student_count']; ?> students
                                    </span>
                                </td>
                                <td>
                                    <span style="color: #2c3e50; font-weight: bold;">
                                        ₹<?php echo number_format($course['average_fee'], 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($course['last_payment']): ?>
                                        <span style="color: #7f8c8d; font-size: 0.9em;">
                                            <?php echo date('M j, Y', strtotime($course['last_payment'])); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #bdc3c7;">No payments</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 20px; color: #7f8c8d;">
                                    No fee collection data found for the selected period.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Recent Payments -->
            <h4>🕒 Recent Payments</h4>
            <div class="ihd-admin-table-container">
                <table class="ihd-admin-table">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Course</th>
                            <th>Amount Paid</th>
                            <th>Total Fees</th>
                            <th>Balance</th>
                            <th>Payment Date</th>
                            <th>Type</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($fees_data['recent_payments'])): ?>
                            <?php foreach ($fees_data['recent_payments'] as $payment): ?>
                            <tr>
                                <td><strong><?php echo esc_html($payment['student_name']); ?></strong></td>
                                <td><?php echo esc_html($payment['course_name']); ?></td>
                                <td>
                                    <span style="color: #27ae60; font-weight: bold;">
                                        ₹<?php echo number_format($payment['fees_paid'], 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="color: #2c3e50;">
                                        ₹<?php echo number_format($payment['total_fees'], 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="color: <?php echo $payment['balance'] > 0 ? '#e74c3c' : '#27ae60'; ?>; font-weight: bold;">
                                        ₹<?php echo number_format($payment['balance'], 2); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M j, Y', strtotime($payment['payment_date'])); ?></td>
                                <td>
                                    <span class="ihd-trainer-status-badge <?php echo $payment['payment_type'] === 'partial' ? 'status-pending' : 'status-paid'; ?>">
                                        <?php echo ucfirst($payment['payment_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="ihd-trainer-status-badge status-<?php echo $payment['fee_status']; ?>">
                                        <?php echo ucfirst($payment['fee_status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 20px; color: #7f8c8d;">
                                    No recent payments found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- Pending Payments List -->
            <!-- Pending Payments List -->
            <h4>⏳ Pending Payments</h4>
            <?php
            // Get all students for pending payments calculation
            $all_students_for_pending = get_posts(array(
                'post_type' => 'student',
                'posts_per_page' => -1,
                'meta_query' => array(
                    array(
                        'key' => 'completion_updated',
                        'value' => array($fees_start_date, $fees_end_date),
                        'compare' => 'BETWEEN',
                        'type' => 'DATE'
                    )
                )
            ));

            // Get pending payments list
            $pending_payments_list = array();
            foreach ($all_students_for_pending as $student) {
                $fees_paid = floatval(get_post_meta($student->ID, 'fees_paid', true));
                $total_fees = floatval(get_post_meta($student->ID, 'total_fees', true));
                $fee_status = get_post_meta($student->ID, 'fee_status', true);
                $course_id = get_post_meta($student->ID, 'course_id', true);
                $course_name = get_term($course_id, 'module')->name ?? 'Unknown Course';
                
                if ($fee_status === 'pending' && $total_fees > 0) {
                    $pending_amount = $total_fees - $fees_paid;
                    if ($pending_amount > 0) {
                        $pending_payments_list[] = array(
                            'student_name' => $student->post_title,
                            'course_name' => $course_name,
                            'total_fees' => $total_fees,
                            'fees_paid' => $fees_paid,
                            'pending_amount' => $pending_amount,
                            'payment_date' => get_post_meta($student->ID, 'completion_updated', true),
                            'fee_status' => $fee_status
                        );
                    }
                }
            }

            // Sort by pending amount (highest first)
            usort($pending_payments_list, function($a, $b) {
                return $b['pending_amount'] - $a['pending_amount'];
            });
            ?>

            <?php if (!empty($pending_payments_list)): ?>
                <div class="ihd-admin-table-container">
                    <table class="ihd-admin-table">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Course</th>
                                <th>Total Fees</th>
                                <th>Paid</th>
                                <th>Pending</th>
                                <th>Last Updated</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_payments_list as $payment): ?>
                            <tr>
                                <td><strong><?php echo esc_html($payment['student_name']); ?></strong></td>
                                <td><?php echo esc_html($payment['course_name']); ?></td>
                                <td>
                                    <span style="color: #2c3e50; font-weight: bold;">
                                        ₹<?php echo number_format($payment['total_fees'], 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="color: #27ae60; font-weight: bold;">
                                        ₹<?php echo number_format($payment['fees_paid'], 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="color: #e74c3c; font-weight: bold; font-size: 1.1em;">
                                        ₹<?php echo number_format($payment['pending_amount'], 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($payment['payment_date']): ?>
                                        <span style="color: #7f8c8d; font-size: 0.9em;">
                                            <?php echo date('M j, Y', strtotime($payment['payment_date'])); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #bdc3c7;">Not available</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="ihd-trainer-status-badge status-pending">
                                        <?php echo ucfirst($payment['fee_status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 40px 20px; background: white; border-radius: 6px; color: #7f8c8d; border: 1px solid #ddd;">
                    <p>✅ No pending payments found for the selected period.</p>
                    <p style="margin-top: 10px; font-size: 13px;">
                        Date Range: <?php echo date('M j, Y', strtotime($fees_start_date)); ?> to <?php echo date('M j, Y', strtotime($fees_end_date)); ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Trainers Overview Tab -->
        <div id="trainers-overview" class="ihd-admin-section <?php echo $active_tab === 'trainers-overview' ? 'active' : ''; ?>">
            <?php foreach ($messages as $m) echo $m; ?>

            <?php if ($student_id > 0): ?>
                <!-- Student Detail View -->
                <?php 
                $student = get_post($student_id);
                if ($student): 
                    $trainer_id = get_post_meta($student_id, 'trainer_user_id', true);
                    $trainer = get_user_by('id', $trainer_id);
                    $trainer_name = $trainer ? trim($trainer->first_name . ' ' . $trainer->last_name) : 'N/A';
                    $course_id = get_post_meta($student_id, 'course_id', true);
                    $course_name = get_term($course_id, 'module')->name ?? '';
                    $completion = get_post_meta($student_id, 'completion', true) ?: 0;
                    $total_hours = ihd_get_student_total_hours($student_id);
                    $progress_history = ihd_get_student_progress_history($student_id);
                    $class_sessions = ihd_get_student_class_sessions($student_id);

                    // Apply date filter if set
                    $start_date = sanitize_text_field($_GET['start_date'] ?? '');
                    $end_date = sanitize_text_field($_GET['end_date'] ?? '');

                    if ($start_date || $end_date) {
                        $filtered_progress = ihd_get_student_daily_class_report($student_id, $start_date, $end_date);
                    } else {
                        $filtered_progress = $progress_history;
                    }
                ?>
                    <a href="?trainer_id=<?php echo $trainer_id; ?>" class="ihd-admin-back-btn">← Back to Trainer</a>

                    <h3>📊 Student Details: <?php echo esc_html($student->post_title); ?></h3>

                    <div class="ihd-admin-stats-grid">
                        <div class="ihd-admin-stats-card">
                            <h4>Overall Progress</h4>
                            <p><?php echo intval($completion); ?>%</p>
                        </div>
                        <div class="ihd-admin-stats-card" style="border-left-color: #e67e22;">
                            <h4>Total Class Hours</h4>
                            <p style="color: #e67e22;"><?php echo number_format($total_hours, 2); ?>h</p>
                        </div>
                        <div class="ihd-admin-stats-card" style="border-left-color: #27ae60;">
                            <h4>Course</h4>
                            <p style="color: #27ae60; font-size: 1.2rem;"><?php echo esc_html($course_name); ?></p>
                        </div>
                        <div class="ihd-admin-stats-card" style="border-left-color: #9b59b6;">
                            <h4>Trainer</h4>
                            <p style="color: #9b59b6; font-size: 1.2rem;"><?php echo esc_html($trainer_name); ?></p>
                        </div>
                    </div>

                    <!-- Daily Class Time Summary -->
                    <h4>📊 Daily Class Time Summary</h4>
                    <div class="ihd-admin-stats-grid">
                        <?php
                        // Calculate totals
                        $total_class_minutes = 0;
                        $total_class_days = 0;
                        $average_daily_minutes = 0;

                        foreach ($progress_history as $progress) {
                            if ($progress->class_minutes > 0) {
                                $total_class_minutes += $progress->class_minutes;
                                $total_class_days++;
                            }
                        }

                        if ($total_class_days > 0) {
                            $average_daily_minutes = round($total_class_minutes / $total_class_days);
                        }

                        $total_class_hours = round($total_class_minutes / 60, 2);
                        $average_daily_hours = round($average_daily_minutes / 60, 2);
                        ?>

                        <div class="ihd-admin-stats-card">
                            <h4>Total Class Days</h4>
                            <p><?php echo $total_class_days; ?></p>
                        </div>
                        <div class="ihd-admin-stats-card" style="border-left-color: #e67e22;">
                            <h4>Total Class Time</h4>
                            <p style="color: #e67e22;"><?php echo $total_class_hours; ?>h</p>
                        </div>
                        <div class="ihd-admin-stats-card" style="border-left-color: #9b59b6;">
                            <h4>Avg. Daily Time</h4>
                            <p style="color: #9b59b6;"><?php echo $average_daily_hours; ?>h</p>
                        </div>
                    </div>

                    <!-- Date Range Filter for Reports -->
                    <div class="ihd-date-filter">
                        <h4>📅 Filter Daily Report</h4>
                        <form method="get" class="ihd-filter-grid">
                            <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Start Date</label>
                                <input type="date" name="start_date" value="<?php echo esc_attr($start_date); ?>" style="width: 100%; padding: 10px;">
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: bold;">End Date</label>
                                <input type="date" name="end_date" value="<?php echo esc_attr($end_date); ?>" style="width: 100%; padding: 10px;">
                            </div>
                            <div>
                                <button type="submit" style="width: 100%; padding: 10px; background: #3498db; color: white; border: none; border-radius: 5px; cursor: pointer;">
                                    🔍 Filter Report
                                </button>
                            </div>
                            <div>
                                <a href="?student_id=<?php echo $student_id; ?>" style="display: block; padding: 10px; background: #95a5a6; color: white; text-align: center; text-decoration: none; border-radius: 5px;">
                                    🔄 Reset
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- Progress History -->
                    <h4>📈 Daily Progress & Class History</h4>

                    <!-- Progress History - FIXED VERSION -->
                    <div class="ihd-admin-table-container">
                        <table class="ihd-admin-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Progress</th>
                                    <th>Class Time</th>
                                    <th>Updated By</th>
                                    <th>Topics Covered</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $display_progress = ($start_date || $end_date) ? $filtered_progress : $progress_history;

                                // DEBUG: Check what we're actually getting
                                error_log("IHD: Displaying " . count($display_progress) . " progress entries");
                                foreach ($display_progress as $index => $progress) {
                                    error_log("IHD: Entry $index - Student: {$progress->student_id}, Date: {$progress->completion_date}, Progress: {$progress->completion_percentage}%, Minutes: {$progress->class_minutes}");
                                }

                                foreach ($display_progress as $progress): 
                                    // FIXED: Proper data extraction
                                    $updater = get_user_by('id', $progress->updated_by);
                                    $updater_name = $updater ? trim($updater->first_name . ' ' . $updater->last_name) : 'System';
                                    $class_hours = $progress->class_minutes > 0 ? round($progress->class_minutes / 60, 2) : 0;

                                    // FIXED: Proper date formatting with validation
                                    $display_date = '';
                                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $progress->completion_date)) {
                                        $display_date = date('M j, Y', strtotime($progress->completion_date));
                                    } else {
                                        $display_date = '<span style="color: #e74c3c;">INVALID: ' . esc_html(substr($progress->completion_date, 0, 20)) . '</span>';
                                        error_log("IHD: INVALID DATE in progress entry ID: {$progress->id} - '{$progress->completion_date}'");
                                    }
                                ?>
                                <tr>
                                    <td><?php echo $display_date; ?></td>
                                    <td>
                                        <div class="ihd-admin-progress-container">
                                            <span style="color:#3498db;font-weight:bold;"><?php echo intval($progress->completion_percentage); ?>%</span>
                                            <div class="ihd-admin-progress-bar">
                                                <div class="ihd-admin-progress-fill" style="width: <?php echo intval($progress->completion_percentage); ?>%;"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($progress->class_minutes > 0): ?>
                                            <span style="color:#e67e22;font-weight:bold;">
                                                <?php echo $progress->class_minutes; ?> min (<?php echo $class_hours; ?>h)
                                            </span>
                                        <?php else: ?>
                                            <span style="color:#7f8c8d;">No class</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($updater_name); ?></td>
                                    <td><?php echo esc_html($progress->notes ?: 'No notes'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Class Sessions -->
                    <h4>🕒 Class Sessions</h4>
                    <?php if (!empty($class_sessions)): ?>
                        <div class="ihd-admin-table-container">
                            <table class="ihd-admin-table">
                                <thead>
                                    <tr>
                                        <th>Date & Time (IST)</th>
                                        <th>Duration</th>
                                        <th>Status</th>
                                        <th>Recording</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($class_sessions as $session): 
                                        // FIXED: Use proper IST time conversion
                                        $start_time_ist = $session->start_time;

                                        // Convert to readable IST format
                                        $start_datetime = new DateTime($start_time_ist, new DateTimeZone('Asia/Kolkata'));
                                        $start_formatted = $start_datetime->format('M j, Y g:i A');

                                        $duration_minutes = $session->duration_minutes;
                                        $duration_hours = $duration_minutes > 0 ? round($duration_minutes / 60, 2) : 0;
                                    ?>
                                    <tr>
                                        <td>
                                            <?php echo esc_html($start_formatted); ?>
                                            <br><small style="color: #7f8c8d;">IST</small>
                                        </td>
                                        <td>
                                            <span class="ihd-admin-session-duration">
                                                <?php echo number_format($duration_hours, 2); ?> hours
                                                <br><small>(<?php echo intval($duration_minutes); ?> minutes)</small>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="color: <?php echo $session->status === 'completed' ? '#27ae60' : '#e67e22'; ?>; font-weight: bold;">
                                                <?php echo ucfirst($session->status); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($session->recording_path): ?>
                                                <a href="<?php echo esc_url($session->recording_path); ?>" target="_blank" class="ihd-admin-btn">📹 View</a>
                                            <?php else: ?>
                                                <span style="color: #7f8c8d;">No recording</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; padding: 5%; color: #7f8c8d;">No class sessions recorded yet.</p>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="error" style="text-align: center; padding: 5%;">Student not found.</div>
                <?php endif; ?>

            <?php elseif ($trainer_id > 0): ?>
                <!-- Trainer Students View -->
                <?php 
                $trainer = get_user_by('id', $trainer_id);
                if ($trainer): 
                    $trainer_name = trim($trainer->first_name . ' ' . $trainer->last_name) ?: $trainer->user_login;

                    // Get trainer's students
                    $students = get_posts(array(
                        'post_type' => 'student',
                        'posts_per_page' => -1,
                        'meta_query' => array(
                            array(
                                'key' => 'trainer_user_id',
                                'value' => $trainer_id,
                                'compare' => '='
                            )
                        )
                    ));
                ?>
                    <a href="?" class="ihd-admin-back-btn">← Back to All Trainers</a>

                    <h3>👥 Students of <?php echo esc_html($trainer_name); ?></h3>

                    <?php if (!empty($students)): ?>
                        <div class="ihd-admin-table-container">
                            <table class="ihd-admin-table">
                                <thead>
                                    <tr>
                                        <th>Student Name</th>
                                        <th>Course</th>
                                        <th>Progress</th>
                                        <th>Total Hours</th>
                                        <th>Last Updated</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($students as $student): 
                                        $course_id = get_post_meta($student->ID, 'course_id', true);
                                        $course_name = get_term($course_id, 'module')->name ?? '';
                                        $completion = get_post_meta($student->ID, 'completion', true) ?: 0;
                                        $total_hours = ihd_get_student_total_hours($student->ID);
                                        $last_updated = get_post_meta($student->ID, 'completion_updated', true);
                                    ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($student->post_title); ?></strong></td>
                                        <td><?php echo esc_html($course_name); ?></td>
                                        <td>
                                            <div class="ihd-admin-progress-container">
                                                <span style="color:#3498db;font-weight:bold;"><?php echo intval($completion); ?>%</span>
                                                <div class="ihd-admin-progress-bar">
                                                    <div class="ihd-admin-progress-fill" style="width: <?php echo intval($completion); ?>%;"></div>
                                                </div>
                                            </div>
                                            <?php if ($last_updated): ?>
                                                <span class="ihd-admin-last-updated">
                                                    Updated: <?php echo date('M j, Y', strtotime($last_updated)); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="color: #e67e22; font-weight: bold;">
                                                <?php echo number_format($total_hours, 2); ?>h
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo $last_updated ? date('M j, Y', strtotime($last_updated)) : 'Never'; ?>
                                        </td>
                                        <td>
                                            <a href="?student_id=<?php echo $student->ID; ?>" class="ihd-admin-btn ihd-admin-view-btn">📊 Details</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; padding: 5%; color: #7f8c8d;">No students found for this trainer.</p>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="error" style="text-align: center; padding: 5%;">Trainer not found.</div>
                <?php endif; ?>

            <?php else: ?>
                <!-- Main Admin Dashboard -->
                <h3>📊 All Trainers Overview</h3>

                <?php
                $trainers = get_users(array('role' => 'trainer'));
                if (!empty($trainers)): 

                    // Calculate statistics
                    $total_trainers = count($trainers);
                    $total_students = 0;
                    $total_active_students = 0;
                    $total_completed_students = 0;

                    foreach ($trainers as $trainer) {
                        $active_students = get_posts(array(
                            'post_type' => 'student',
                            'posts_per_page' => -1,
                            'meta_query' => array(
                                array('key' => 'trainer_user_id', 'value' => $trainer->ID, 'compare' => '='),
                                array('key' => 'status', 'value' => 'active', 'compare' => '=')
                            ),
                            'fields' => 'ids'
                        ));

                        $completed_students = get_posts(array(
                            'post_type' => 'student',
                            'posts_per_page' => -1,
                            'meta_query' => array(
                                array('key' => 'trainer_user_id', 'value' => $trainer->ID, 'compare' => '='),
                                array('key' => 'status', 'value' => 'completed', 'compare' => '=')
                            ),
                            'fields' => 'ids'
                        ));

                        $total_students += count($active_students) + count($completed_students);
                        $total_active_students += count($active_students);
                        $total_completed_students += count($completed_students);
                    }
                ?>

                <div class="ihd-admin-stats-grid">
                    <div class="ihd-admin-stats-card">
                        <h4>Total Trainers</h4>
                        <p><?php echo $total_trainers; ?></p>
                    </div>
                    <div class="ihd-admin-stats-card" style="border-left-color: #e67e22;">
                        <h4>Total Students</h4>
                        <p style="color: #e67e22;"><?php echo $total_students; ?></p>
                    </div>
                    <div class="ihd-admin-stats-card" style="border-left-color: #3498db;">
                        <h4>Active Students</h4>
                        <p style="color: #3498db;"><?php echo $total_active_students; ?></p>
                    </div>
                    <div class="ihd-admin-stats-card" style="border-left-color: #27ae60;">
                        <h4>Completed Students</h4>
                        <p style="color: #27ae60;"><?php echo $total_completed_students; ?></p>
                    </div>
                </div>

                <div class="ihd-admin-table-container">
                    <table class="ihd-admin-table">
                        <thead>
                            <tr>
                                <th>Trainer Name</th>
                                <th>Email</th>
                                <th>Assigned Modules</th>
                                <th>Active Students</th>
                                <th>Completed Students</th>
                                <th>Total Students</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trainers as $trainer): 
                                $name = trim($trainer->first_name . ' ' . $trainer->last_name) ?: $trainer->user_login;

                                // Get assigned modules
                                $modules_ids = get_user_meta($trainer->ID, 'assigned_modules', true) ?: array();
                                $module_names = array();
                                foreach ((array)$modules_ids as $m) {
                                    $term = get_term($m, 'module');
                                    if ($term && !is_wp_error($term)) {
                                        $module_names[] = $term->name;
                                    }
                                }

                                // Count students
                                $active_students_count = get_posts(array(
                                    'post_type' => 'student',
                                    'posts_per_page' => -1,
                                    'meta_query' => array(
                                        array('key' => 'trainer_user_id', 'value' => $trainer->ID, 'compare' => '='),
                                        array('key' => 'status', 'value' => 'active', 'compare' => '=')
                                    ),
                                    'fields' => 'ids'
                                ));

                                $completed_students_count = get_posts(array(
                                    'post_type' => 'student',
                                    'posts_per_page' => -1,
                                    'meta_query' => array(
                                        array('key' => 'trainer_user_id', 'value' => $trainer->ID, 'compare' => '='),
                                        array('key' => 'status', 'value' => 'completed', 'compare' => '=')
                                    ),
                                    'fields' => 'ids'
                                ));

                                $total_students = count($active_students_count) + count($completed_students_count);
                                // Check if trainer has active class
                                $active_class = ihd_check_trainer_active_class($trainer->ID);
                                $status_text = $active_class ? '🎯 Live Class' : '💤 No Class';
                                $status_class = $active_class ? 'ihd-status-live' : 'ihd-status-offline';
                                $join_link = $active_class ? ihd_generate_admin_meeting_link($active_class) : '#';
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($name); ?></strong></td>
                                <td><?php echo esc_html($trainer->user_email); ?></td>
                                <td>
                                    <?php if (!empty($module_names)): ?>
                                        <span style="color:#2c3e50;"><?php echo esc_html(implode(', ', $module_names)); ?></span>
                                    <?php else: ?>
                                        <span style="color:#bdc3c7; font-style: italic;">No modules assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="color:#e67e22;font-weight:bold; font-size: 1.1em;"><?php echo count($active_students_count); ?></span>
                                </td>
                                <td>
                                    <span style="color:#27ae60;font-weight:bold; font-size: 1.1em;"><?php echo count($completed_students_count); ?></span>
                                </td>
                                <td>
                                    <span style="color:#3498db;font-weight:bold; font-size: 1.1em;"><?php echo $total_students; ?></span>
                                </td>
                                <td>
                                    <span class="ihd-trainer-status <?php echo $status_class; ?>">
                                        <?php echo $status_text; ?>
                                        <?php if ($active_class): ?>
                                            <br><small style="font-size: 0.8em; color: #7f8c8d;">
                                                Since: <?php echo date('g:i A', strtotime($active_class->start_time)); ?>
                                            </small>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($active_class): ?>
                                        <a href="<?php echo esc_url($join_link); ?>" target="_blank" class="ihd-admin-btn ihd-join-class-btn">
                                            🎬 Join Class
                                        </a>
                                    <?php else: ?>
                                        <span class="ihd-admin-btn ihd-no-class-btn" style="background: #95a5a6; cursor: not-allowed;">
                                            ⏸️ No Class
                                        </span>
                                    <?php endif; ?>
                                    <a href="?trainer_id=<?php echo $trainer->ID; ?>" class="ihd-admin-btn">View Students</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <p style="text-align: center; padding: 5%; color: #7f8c8d;">No trainers found in the system.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // Tab functionality
    document.addEventListener('DOMContentLoaded', function() {
        const tabs = document.querySelectorAll('.ihd-admin-tab');
        const sections = document.querySelectorAll('.ihd-admin-section');

        tabs.forEach(tab => {
            tab.addEventListener('click', function() {
                const target = this.getAttribute('data-target');

                // Update active tab
                tabs.forEach(t => t.classList.remove('ihd-active'));
                this.classList.add('ihd-active');

                // Show active section
                sections.forEach(section => {
                    section.classList.remove('active');
                    if (section.id === target) {
                        section.classList.add('active');
                    }
                });

                // Update URL without reload
                const url = new URL(window.location);
                url.searchParams.set('tab', target);
                window.history.pushState({}, '', url);
            });
        });

        // Set initial tab based on URL or default
        const urlParams = new URLSearchParams(window.location.search);
        const activeTab = urlParams.get('tab') || 'trainers-overview';

        const initialTab = document.querySelector(`.ihd-admin-tab[data-target="${activeTab}"]`);
        const initialSection = document.getElementById(activeTab);

        if (initialTab && initialSection) {
            tabs.forEach(t => t.classList.remove('ihd-active'));
            sections.forEach(s => s.classList.remove('active'));

            initialTab.classList.add('ihd-active');
            initialSection.classList.add('active');
        }
    });

    
    function confirmEndBatch(batchId) {
        // Validate all progress inputs
        let allValid = true;
        const progressInputs = document.querySelectorAll('input[type="number"][name^="progress_"]');
        const notesInputs = document.querySelectorAll('input[type="text"][name^="notes_"]');
        
        progressInputs.forEach(input => {
            if (!input.value || input.value < 0 || input.value > 100) {
                allValid = false;
                input.style.borderColor = '#e74c3c';
            } else {
                input.style.borderColor = '';
            }
        });
        
        notesInputs.forEach(input => {
            if (!input.value.trim()) {
                allValid = false;
                input.style.borderColor = '#e74c3c';
            } else {
                input.style.borderColor = '';
            }
        });
        
        if (!allValid) {
            alert('Please fill all progress percentages (0-100) and notes for each student before ending the class.');
            return false;
        }
        
        return confirm('Are you sure you want to end the batch class session?\n\nBatch ID: ' + batchId + '\n\nThis will record attendance and progress for all students.');
    }
	
    // Auto-save progress notes (optional feature)
    function autoSaveProgressNotes() {
        const forms = document.querySelectorAll('form[id^="endBatchForm_"]');
        forms.forEach(form => {
            const inputs = form.querySelectorAll('input, textarea');
            inputs.forEach(input => {
                input.addEventListener('change', function() {
                    const batchId = form.id.replace('endBatchForm_', '');
                    const data = {};
                    inputs.forEach(i => {
                        data[i.name] = i.value;
                    });
                    localStorage.setItem('batch_progress_' + batchId, JSON.stringify(data));
                });
            });
            
            // Load saved data
            const saved = localStorage.getItem('batch_progress_' + batchId);
            if (saved) {
                const data = JSON.parse(saved);
                inputs.forEach(input => {
                    if (data[input.name]) {
                        input.value = data[input.name];
                    }
                });
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        autoSaveProgressNotes();
        
        // Clear saved data when form is submitted
        const forms = document.querySelectorAll('form[id^="endBatchForm_"]');
        forms.forEach(form => {
            form.addEventListener('submit', function() {
                const batchId = form.id.replace('endBatchForm_', '');
                localStorage.removeItem('batch_progress_' + batchId);
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

add_shortcode('admin_dashboard', 'ihd_admin_dashboard_shortcode');
function ihd_trainer_dashboard_shortcode() {
    if (!is_user_logged_in()) return '<p style="text-align: center; padding: 20px; color: #e74c3c; font-size: 1.2em;">Access denied. Only Trainers can access this dashboard.</p>';
    
    $user = wp_get_current_user();
    if (!in_array('trainer', $user->roles)) return 'Access denied.';

    $messages = array();
    global $wpdb;

    // ---------------- Add Student
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_add_student'])) {
        if (!isset($_POST['ihd_add_student_nonce']) || !wp_verify_nonce($_POST['ihd_add_student_nonce'], 'ihd_add_student_action')) {
            $messages[] = '<div class="error">Invalid request (add student).</div>';
        } else {
            $s_name = sanitize_text_field($_POST['student_name'] ?? '');
            $phone = sanitize_text_field($_POST['phone'] ?? '');
            $timing = sanitize_text_field($_POST['timing'] ?? '');
            $schedule_type = sanitize_text_field($_POST['schedule_type'] ?? 'weekdays');
            $course = intval($_POST['course'] ?? 0);
            $start_date = sanitize_text_field($_POST['start_date'] ?? '');
            $completion = intval($_POST['completion'] ?? 0);
            $training_mode = sanitize_text_field($_POST['training_mode'] ?? 'offline');

            if (!$s_name || !$course || !$start_date) {
                $messages[] = '<div class="error">Please fill all required fields.</div>';
            } else {
                $student_id = wp_insert_post(array(
                    'post_type' => 'student',
                    'post_title' => $s_name,
                    'post_status' => 'publish',
                ));

                if (!is_wp_error($student_id)) {
                    update_post_meta($student_id, 'trainer_user_id', $user->ID);
                    update_post_meta($student_id, 'phone', $phone);
                    update_post_meta($student_id, 'timing', $timing);
                    update_post_meta($student_id, 'schedule_type', $schedule_type);
                    update_post_meta($student_id, 'course_id', $course);
                    update_post_meta($student_id, 'start_date', $start_date);
                    update_post_meta($student_id, 'completion', $completion);
                    update_post_meta($student_id, 'training_mode', $training_mode);
                    update_post_meta($student_id, 'status', 'active');
                    update_post_meta($student_id, 'fee_status', 'pending');
                    update_post_meta($student_id, 'completion_updated', current_time('mysql'));
                    
                    // Assign module taxonomy
                    wp_set_object_terms($student_id, array($course), 'module');
                    
                    $messages[] = '<div class="updated">Student added successfully.</div>';
                } else {
                    $messages[] = '<div class="error">Error adding student.</div>';
                }
            }
        }
    }

    // ---------------- Update Completion with Notes
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_update_completion'])) {
        if (!isset($_POST['ihd_update_completion_nonce']) || !wp_verify_nonce($_POST['ihd_update_completion_nonce'], 'ihd_update_completion_action')) {
            $messages[] = '<div class="error">Invalid request (update completion).</div>';
        } else {
            $student_id = intval($_POST['student_id']);
            $completion = intval($_POST['completion']);
            $progress_notes = sanitize_textarea_field($_POST['progress_notes'] ?? '');
            $student = get_post($student_id);

            if ($student && get_post_meta($student_id, 'trainer_user_id', true) == $user->ID) {
                $fee_status = get_post_meta($student_id, 'fee_status', true);
                if ($fee_status === 'hold') {
                    $messages[] = '<div class="error">Cannot update completion - student is on hold due to pending fees.</div>';
                } else {
                    // Update completion percentage
                    update_post_meta($student_id, 'completion', $completion);
                    update_post_meta($student_id, 'completion_updated', current_time('mysql'));

                    // Track daily progress with notes
                    ihd_track_daily_progress($student_id, $user->ID, $completion, $progress_notes);

                    if ($completion >= 100) {
                        update_post_meta($student_id, 'status', 'completed');
                        update_post_meta($student_id, 'completion_date', date('Y-m-d'));
                    }

                    $messages[] = '<div class="updated">Progress updated successfully with notes.</div>';
                }
            } else {
                $messages[] = '<div class="error">Invalid student.</div>';
            }
        }
    }

    // ---------------- Start Batch Class Session
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_start_batch_class'])) {
        if (!isset($_POST['ihd_start_batch_class_nonce']) || !wp_verify_nonce($_POST['ihd_start_batch_class_nonce'], 'ihd_start_batch_class_action')) {
            $messages[] = '<div class="error">Invalid request (start batch class).</div>';
        } else {
            $batch_key = sanitize_text_field($_POST['batch_key']);
            list($course_id, $timing, $schedule_type) = explode('|', $batch_key);

            error_log("IHD: Starting batch class - Trainer: $user->ID, Course: $course_id, Timing: $timing, Schedule: $schedule_type");

            $batch = ihd_get_or_create_batch($user->ID, $course_id, $timing, $schedule_type);

            if ($batch && !is_wp_error($batch)) {
                // Get all students in this batch group
                $batch_students = get_posts(array(
                    'post_type' => 'student',
                    'posts_per_page' => -1,
                    'meta_query' => array(
                        'relation' => 'AND',
                        array('key' => 'trainer_user_id', 'value' => $user->ID, 'compare' => '='),
                        array('key' => 'course_id', 'value' => $course_id, 'compare' => '='),
                        array('key' => 'timing', 'value' => $timing, 'compare' => '='),
                        array('key' => 'schedule_type', 'value' => $schedule_type, 'compare' => '='),
                        array('key' => 'status', 'value' => 'active', 'compare' => '=')
                    )
                ));

                // Assign all students to this batch
                $assigned_count = 0;
                foreach ($batch_students as $student) {
                    if (update_post_meta($student->ID, 'current_batch_id', $batch->batch_id)) {
                        $assigned_count++;
                    }
                }

                $trainer_meeting_url = 'https://placemen.unaux.com/host/?' . http_build_query(array(
                    'meeting_host' => $batch->meeting_id,
                    'batch_id' => $batch->batch_id
                ));

                $student_meeting_url = 'https://placemen.unaux.com/join/?' . http_build_query(array(
                    'meeting_join' => $batch->meeting_id,
                    'batch_id' => $batch->batch_id
                ));

                $student_names = array_map(function($s) { return $s->post_title; }, $batch_students);

                $messages[] = '<div class="updated">Batch class session started successfully! 
                    <div style="margin-top: 10px;">
                        <strong>Your Meeting Link:</strong><br>
                        <input type="text" value="' . esc_url($trainer_meeting_url) . '" style="width: 100%; padding: 5px; margin: 5px 0;" readonly>
                        <button onclick="copyText(this)" style="padding: 5px 10px; background: #3498db; color: white; border: none; border-radius: 3px; cursor: pointer;">📋 Copy Your Link</button>
                    </div>
                    <div style="margin-top: 10px;">
                        <strong>Student Meeting Link (Share this):</strong><br>
                        <input type="text" value="' . esc_url($student_meeting_url) . '" style="width: 100%; padding: 5px; margin: 5px 0;" readonly>
                        <button onclick="copyText(this)" style="padding: 5px 10px; background: #27ae60; color: white; border: none; border-radius: 3px; cursor: pointer;">📋 Copy Student Link</button>
                    </div>
                    <div style="margin-top: 10px; background: #e8f6f3; padding: 10px; border-radius: 5px;">
                        <strong>Students in this batch (' . count($batch_students) . ' assigned):</strong><br>
                        ' . implode(', ', array_map('esc_html', $student_names)) . '
                    </div>
                    <div style="margin-top: 10px; font-size: 14px; color: #7f8c8d;">
                        <strong>Note to students:</strong> They must enter their <strong>exact name</strong> as shown above to join the class.
                    </div>
                </div>';
            } else {
                error_log("IHD: Batch creation failed - " . print_r($batch, true));
                $messages[] = '<div class="error">Failed to start batch class session. Please try again.</div>';
            }
        }
    }

    // ---------------- End Batch Class Session with Progress Notes
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_end_batch_class'])) {
        if (!isset($_POST['ihd_end_batch_class_nonce']) || !wp_verify_nonce($_POST['ihd_end_batch_class_nonce'], 'ihd_end_batch_class_action')) {
            $messages[] = '<div class="error">Invalid request (end batch class).</div>';
        } else {
            $batch_id = sanitize_text_field($_POST['batch_id']);
            
            // Collect progress notes for each student
            $progress_notes = array();
            foreach ($_POST as $key => $value) {
                if (strpos($key, 'progress_notes_') === 0) {
                    $student_id = str_replace('progress_notes_', '', $key);
                    if (is_numeric($student_id)) {
                        $progress_notes[$student_id] = array(
                            'notes' => sanitize_textarea_field($value),
                            'progress' => intval($_POST['progress_percentage_' . $student_id] ?? 0)
                        );
                    }
                }
            }

            error_log("IHD: ===== BATCH END REQUEST STARTED =====");
            error_log("IHD: Batch ID: " . $batch_id);
            error_log("IHD: Trainer ID: " . $user->ID);
            error_log("IHD: Progress notes collected: " . count($progress_notes));

            // End batch session with progress tracking
            $result = ihd_end_batch_session_with_progress($batch_id, $user->ID, $progress_notes);

            if ($result) {
                $messages[] = '<div class="updated">✅ Batch class session ended successfully! 
                    <div style="margin-top: 10px;">
                        <strong>Duration:</strong> ' . $result['duration'] . ' minutes<br>
                        <strong>Students:</strong> ' . $result['students_cleared'] . ' students cleared<br>
                        <strong>Sessions Recorded:</strong> ' . $result['sessions_recorded'] . '<br>
                        <strong>Progress Notes:</strong> Updated for ' . count($progress_notes) . ' students<br>
                        <strong>Batch ID:</strong> ' . esc_html($batch_id) . '
                    </div>
                </div>';
                error_log("IHD: Batch end completed successfully with progress tracking");
            } else {
                $messages[] = '<div class="error">❌ Failed to end batch session. Please try again.</div>';
                error_log("IHD: ERROR - Batch end failed");
            }
            error_log("IHD: ===== BATCH END REQUEST COMPLETED =====");
        }
    }

    // ---------------- Fetch Active Students
    $active_students = get_posts(array(
        'post_type'=>'student',
        'meta_query'=>array(
            array('key'=>'trainer_user_id','value'=>$user->ID,'compare'=>'='),
            array('key'=>'status','value'=>'active','compare'=>'=')
        ),
        'posts_per_page'=>-1
    ));

    // Group students by batch
    $batch_groups = array();
    foreach ($active_students as $s) {
        $course_id = get_post_meta($s->ID, 'course_id', true);
        $timing = get_post_meta($s->ID, 'timing', true);
        $schedule_type = get_post_meta($s->ID, 'schedule_type', true);
        $batch_key = $course_id . '|' . $timing . '|' . $schedule_type;
        
        if (!isset($batch_groups[$batch_key])) {
            $batch_groups[$batch_key] = array(
                'course_id' => $course_id,
                'timing' => $timing,
                'schedule_type' => $schedule_type,
                'students' => array(),
                'batch' => null
            );
        }
        $batch_groups[$batch_key]['students'][] = $s;
    }

    // Check for active batches and assign to groups
    $active_batches = ihd_get_active_batches($user->ID);
    foreach ($batch_groups as $batch_key => $group) {
        $matching_batch = null;
        foreach ($active_batches as $batch) {
            if ($batch->course_id == $group['course_id'] && 
                $batch->timing == $group['timing'] && 
                $batch->schedule_type == $group['schedule_type']) {
                $matching_batch = $batch;
                break;
            }
        }
        $batch_groups[$batch_key]['batch'] = $matching_batch;
    }

    // ---------------- Fetch Completed Students
    $completed_students = get_posts(array(
        'post_type'=>'student',
        'meta_query'=>array(
            array('key'=>'trainer_user_id','value'=>$user->ID,'compare'=>'='),
            array('key'=>'status','value'=>'completed','compare'=>'=')
        ),
        'posts_per_page'=>-1
    ));

    // ---------------- Fetch Trainer Modules
    $modules_ids = get_user_meta($user->ID, 'assigned_modules', true) ?: array();
    $modules = array();
    foreach ((array)$modules_ids as $m) {
        $term = get_term($m, 'module');
        if ($term && !is_wp_error($term)) $modules[$m] = $term->name;
    }

    ob_start(); ?>
    
    <style>
    /* Trainer Dashboard Traditional Responsive Styles */
    .ihd-trainer-dashboard {
        max-width: 100%;
        margin: 0 auto;
        padding: 15px;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: #f5f5f5;
        min-height: 100vh;
        box-sizing: border-box;
        font-size: 14px;
    }

    .ihd-trainer-dashboard h2 {
        text-align: center;
        margin-bottom: 20px;
        color: #2c3e50;
        font-size: 22px;
        font-weight: 600;
        padding-bottom: 15px;
        border-bottom: 2px solid #3498db;
    }

    .ihd-trainer-dashboard h3 {
        margin: 20px 0 15px 0;
        color: #34495e;
        font-size: 17px;
        font-weight: 600;
        padding-bottom: 10px;
        border-bottom: 1px solid #bdc3c7;
    }

    .ihd-trainer-dashboard h4 {
        margin: 15px 0 8px 0;
        color: #2c3e50;
        font-size: 15px;
        font-weight: 600;
    }

    /* Messages */
    .ihd-trainer-dashboard .error {
        background: #ffe6e6;
        border: 1px solid #ffcccc;
        border-radius: 4px;
        padding: 12px 15px;
        margin-bottom: 15px;
        color: #cc0000;
        font-size: 13px;
        border-left: 4px solid #ff3333;
    }

    .ihd-trainer-dashboard .updated {
        background: #e6ffe6;
        border: 1px solid #ccffcc;
        border-radius: 4px;
        padding: 12px 15px;
        margin-bottom: 15px;
        color: #006600;
        font-size: 13px;
        border-left: 4px solid #33cc33;
    }

    /* Tabs */
    .ihd-trainer-tabs {
        display: flex;
        flex-wrap: wrap;
        margin-bottom: 20px;
        border-radius: 6px 6px 0 0;
        overflow: hidden;
        background: #fff;
        border: 1px solid #ddd;
        border-bottom: none;
    }

    .ihd-trainer-tab {
        flex: 1;
        padding: 12px 15px;
        cursor: pointer;
        text-align: center;
        background: #ecf0f1;
        transition: all 0.3s ease;
        border: none;
        font-weight: 600;
        color: #7f8c8d;
        font-size: 13px;
        min-width: 120px;
        border-right: 1px solid #ddd;
    }

    .ihd-trainer-tab:last-child {
        border-right: none;
    }

    .ihd-trainer-tab.ihd-active {
        background: #3498db;
        color: #fff;
    }

    .ihd-trainer-tab:hover:not(.ihd-active) {
        background: #d5dbdb;
    }

    .ihd-trainer-section {
        display: none;
        animation: fadeIn 0.3s ease;
        background: #fff;
        padding: 20px;
        border-radius: 0 0 6px 6px;
        border: 1px solid #ddd;
        border-top: none;
    }

    .ihd-trainer-section.active {
        display: block;
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    /* Forms */
    .ihd-trainer-add-form {
        background: white;
        padding: 20px;
        border-radius: 6px;
        border: 1px solid #ddd;
        margin-bottom: 20px;
        border-left: 4px solid #3498db;
    }

    .ihd-trainer-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 15px;
    }

    .ihd-trainer-dashboard input[type="text"],
    .ihd-trainer-dashboard input[type="tel"],
    .ihd-trainer-dashboard input[type="date"],
    .ihd-trainer-dashboard input[type="number"],
    .ihd-trainer-dashboard select,
    .ihd-trainer-dashboard textarea {
        padding: 10px 12px;
        border: 1px solid #ced4da;
        border-radius: 4px;
        width: 100%;
        box-sizing: border-box;
        font-size: 13px;
        transition: all 0.2s ease;
        background: #fff;
        height: 40px;
    }

    .ihd-trainer-dashboard textarea {
        height: auto;
        min-height: 80px;
        resize: vertical;
    }

    .ihd-trainer-dashboard input:focus,
    .ihd-trainer-dashboard select:focus,
    .ihd-trainer-dashboard textarea:focus {
        border-color: #3498db;
        outline: none;
        box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.1);
    }

    .ihd-trainer-dashboard button {
        background: #3498db;
        color: #fff;
        border: 1px solid #2980b9;
        padding: 10px 20px;
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.2s ease;
        font-size: 13px;
        font-weight: 500;
        height: 40px;
        box-sizing: border-box;
    }

    .ihd-trainer-dashboard button:hover {
        background: #2980b9;
        border-color: #2471a3;
    }

    .ihd-trainer-dashboard button:disabled {
        background: #95a5a6;
        border-color: #7f8c8d;
        cursor: not-allowed;
    }

    /* Batch Group Styles */
    .batch-group {
        background: #fff;
        padding: 20px;
        margin: 15px 0;
        border-radius: 6px;
        border: 1px solid #ddd;
        border-left: 4px solid #3498db;
    }

    .batch-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 15px;
        padding-bottom: 15px;
        border-bottom: 1px solid #ecf0f1;
    }

    .batch-info h4 {
        margin: 0 0 5px 0;
        color: #2c3e50;
        font-size: 16px;
    }

    .batch-details {
        color: #7f8c8d;
        margin: 5px 0;
        font-size: 13px;
    }

    .batch-actions {
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
    }

    /* Buttons */
    .ihd-trainer-start-class-btn {
        background: #27ae60;
        border-color: #229954;
        color: white;
        padding: 10px 20px;
        border-radius: 4px;
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s ease;
        text-align: center;
        text-decoration: none;
        display: inline-block;
        height: 40px;
        box-sizing: border-box;
    }

    .ihd-trainer-start-class-btn:hover {
        background: #229954;
        border-color: #1e8449;
    }

    .ihd-trainer-end-class-btn {
        background: #e74c3c;
        border-color: #c0392b;
        color: white;
        padding: 10px 20px;
        border-radius: 4px;
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s ease;
        text-align: center;
        height: 40px;
        box-sizing: border-box;
    }

    .ihd-trainer-end-class-btn:hover {
        background: #c0392b;
        border-color: #a93226;
    }

    .ihd-trainer-active-session {
        background: #e8f6f3;
        border: 1px solid #27ae60;
        border-radius: 4px;
        padding: 15px;
        margin-top: 15px;
        width: 100%;
    }

    .ihd-trainer-session-info {
        font-size: 13px;
        color: #27ae60;
        font-weight: 600;
        margin-bottom: 10px;
    }

    /* Tables */
    .ihd-trainer-table-container {
        overflow-x: auto;
        border-radius: 4px;
        margin: 15px 0;
        border: 1px solid #ddd;
        -webkit-overflow-scrolling: touch;
    }

    .ihd-trainer-table {
        width: 100%;
        border-collapse: collapse;
        background: #fff;
        font-size: 12px;
        table-layout: fixed;
        min-width: 800px;
    }

    .ihd-trainer-table thead {
        background: #2c3e50;
    }

    .ihd-trainer-table th {
        color: white;
        padding: 10px 8px;
        text-align: left;
        font-weight: 600;
        font-size: 12px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        border-right: 1px solid #34495e;
    }

    .ihd-trainer-table th:last-child {
        border-right: none;
    }

    .ihd-trainer-table td {
        padding: 8px;
        border-bottom: 1px solid #ecf0f1;
        vertical-align: top;
        border-right: 1px solid #ecf0f1;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }

    .ihd-trainer-table td:last-child {
        border-right: none;
    }

    .ihd-trainer-table tbody tr:hover {
        background: #f8f9fa;
    }

    .ihd-trainer-table tbody tr:nth-child(even) {
        background: #fafbfc;
    }

    /* Badges */
    .ihd-trainer-weekdays-badge {
        background: #3498db;
        color: white;
        padding: 4px 8px;
        border-radius: 3px;
        font-size: 11px;
        font-weight: 600;
        display: inline-block;
        text-align: center;
        min-width: 70px;
    }

    .ihd-trainer-weekends-badge {
        background: #27ae60;
        color: white;
        padding: 4px 8px;
        border-radius: 3px;
        font-size: 11px;
        font-weight: 600;
        display: inline-block;
        text-align: center;
        min-width: 70px;
    }

    .ihd-trainer-status-badge {
        padding: 4px 8px;
        border-radius: 3px;
        font-size: 11px;
        font-weight: 600;
        display: inline-block;
        text-align: center;
        min-width: 70px;
    }

    .ihd-trainer-mode-badge {
        padding: 4px 8px;
        border-radius: 3px;
        font-size: 11px;
        font-weight: 600;
        display: inline-block;
        text-align: center;
        min-width: 70px;
    }

    .status-hold {
        background: #e74c3c;
        color: white;
    }

    .status-pending {
        background: #e67e22;
        color: white;
    }

    .status-paid {
        background: #27ae60;
        color: white;
    }

    .mode-online {
        background: #3498db;
        color: white;
    }

    .mode-offline {
        background: #7f8c8d;
        color: white;
    }

    /* Progress Bars */
    .ihd-trainer-progress-container {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .ihd-trainer-progress-bar {
        width: 70px;
        height: 6px;
        background: #ecf0f1;
        border-radius: 3px;
        overflow: hidden;
    }

    .ihd-trainer-progress-fill {
        height: 100%;
        background: #3498db;
        border-radius: 3px;
        transition: width 0.3s ease;
    }

    .ihd-trainer-last-updated {
        font-size: 10px;
        color: #7f8c8d;
        display: block;
        margin-top: 2px;
    }

    /* Update Form */
    .ihd-trainer-update-form {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .ihd-trainer-update-inputs {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .ihd-trainer-update-form input {
        width: 60px;
        padding: 6px 8px;
        height: 32px;
        box-sizing: border-box;
    }

    .ihd-trainer-update-form textarea {
        width: 100%;
        min-height: 50px;
        font-size: 12px;
        padding: 8px;
    }

    .ihd-trainer-hold-notice {
        color: #e74c3c;
        font-size: 11px;
        margin-top: 5px;
        display: block;
        font-weight: 600;
    }

    /* Progress Notes Form */
    .ihd-progress-notes-form {
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        border-radius: 4px;
        padding: 15px;
        margin: 15px 0;
    }

    .ihd-progress-notes-table {
        width: 100%;
        border-collapse: collapse;
        margin: 10px 0;
        font-size: 11px;
        min-width: 600px;
    }

    .ihd-progress-notes-table th {
        background: #2c3e50;
        color: white;
        padding: 8px 6px;
        text-align: left;
        font-weight: 600;
        border-right: 1px solid #34495e;
    }

    .ihd-progress-notes-table th:last-child {
        border-right: none;
    }

    .ihd-progress-notes-table td {
        padding: 6px;
        border: 1px solid #dee2e6;
        background: white;
        border-right: 1px solid #dee2e6;
    }

    .ihd-progress-notes-table td:last-child {
        border-right: none;
    }

    .ihd-progress-input {
        width: 60px;
        padding: 4px 6px;
        border: 1px solid #ced4da;
        border-radius: 3px;
        font-size: 11px;
        height: 28px;
        box-sizing: border-box;
    }

    .ihd-notes-input {
        width: 100%;
        padding: 6px 8px;
        border: 1px solid #ced4da;
        border-radius: 3px;
        font-size: 11px;
        min-height: 50px;
        box-sizing: border-box;
        resize: vertical;
    }

    /* No Students */
    .ihd-trainer-no-students {
        text-align: center;
        padding: 40px 20px;
        background: white;
        border-radius: 6px;
        color: #7f8c8d;
        border: 1px solid #ddd;
    }

    .ihd-trainer-no-students p {
        font-size: 14px;
        margin: 0;
    }

    /* Mobile-specific styles */
    @media (max-width: 1024px) {
        .ihd-trainer-table {
            font-size: 11px;
        }
        
        .ihd-trainer-table th,
        .ihd-trainer-table td {
            padding: 6px 4px;
        }
    }

    @media (max-width: 768px) {
        .ihd-trainer-dashboard {
            padding: 12px 10px;
            font-size: 13px;
        }
        
        .ihd-trainer-dashboard h2 {
            font-size: 20px;
            margin-bottom: 15px;
        }
        
        .ihd-trainer-dashboard h3 {
            font-size: 16px;
            margin: 15px 0 12px 0;
        }
        
        .ihd-trainer-tabs {
            flex-direction: column;
        }
        
        .ihd-trainer-tab {
            border-right: none;
            border-bottom: 1px solid #ddd;
        }
        
        .ihd-trainer-tab:last-child {
            border-bottom: none;
        }
        
        .batch-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
        
        .batch-actions {
            width: 100%;
            justify-content: space-between;
        }
        
        .ihd-trainer-form-grid {
            grid-template-columns: 1fr;
            gap: 12px;
        }
        
        .ihd-trainer-table {
            font-size: 11px;
            min-width: 700px;
        }
        
        .ihd-trainer-table th,
        .ihd-trainer-table td {
            padding: 8px 6px;
            white-space: nowrap;
        }
        
        .ihd-trainer-dashboard button {
            width: 100%;
            margin: 5px 0;
        }
        
        .ihd-trainer-update-inputs {
            flex-direction: column;
            gap: 5px;
        }
        
        .ihd-trainer-update-form input,
        .ihd-trainer-update-form textarea,
        .ihd-trainer-update-form button {
            width: 100%;
        }
        
        .ihd-progress-notes-table {
            font-size: 10px;
            min-width: 500px;
        }
    }

    @media (max-width: 480px) {
        .ihd-trainer-dashboard {
            padding: 10px 8px;
            font-size: 12px;
        }
        
        .ihd-trainer-dashboard h2 {
            font-size: 18px;
        }
        
        .ihd-trainer-dashboard h3 {
            font-size: 15px;
        }
        
        .ihd-trainer-add-form,
        .batch-group,
        .ihd-trainer-section {
            padding: 15px;
        }
        
        .ihd-trainer-table {
            font-size: 10px;
            min-width: 600px;
        }
        
        .ihd-trainer-table th,
        .ihd-trainer-table td {
            padding: 6px 4px;
        }
        
        .ihd-trainer-progress-bar {
            width: 50px;
        }
        
        .ihd-trainer-status-badge,
        .ihd-trainer-mode-badge,
        .ihd-trainer-weekdays-badge,
        .ihd-trainer-weekends-badge {
            font-size: 10px;
            min-width: 60px;
            padding: 3px 6px;
        }
    }

    /* Print Styles */
    @media print {
        .ihd-trainer-dashboard {
            background: white;
            padding: 0;
        }
        
        .ihd-trainer-dashboard button,
        .ihd-trainer-tabs {
            display: none;
        }
        
        .ihd-trainer-section {
            display: block !important;
            border: 1px solid #000;
        }
        
        .ihd-trainer-table {
            border: 1px solid #000;
        }
    }

    /* Focus States for Accessibility */
    .ihd-trainer-dashboard button:focus,
    .ihd-trainer-dashboard input:focus,
    .ihd-trainer-dashboard select:focus,
    .ihd-trainer-dashboard textarea:focus {
        outline: 2px solid #3498db;
        outline-offset: 2px;
    }

    /* Form validation styles */
    .ihd-progress-input:invalid,
    .ihd-notes-input:invalid {
        border-color: #e74c3c;
        background-color: #ffe6e6;
    }

    .ihd-progress-input:valid,
    .ihd-notes-input:valid {
        border-color: #27ae60;
        background-color: #e6ffe6;
    }

    /* Utility Classes */
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .text-left { text-align: left; }
    .font-bold { font-weight: bold; }
    .font-normal { font-weight: normal; }
    .text-sm { font-size: 11px; }
    .text-xs { font-size: 10px; }

    /* Animation for progress bars */
    @keyframes progressFill {
        from { width: 0%; }
        to { width: attr(data-width); }
    }

    .ihd-trainer-progress-fill {
        animation: progressFill 1s ease-out;
    }
    </style>

    <div class="ihd-trainer-dashboard">
        <h2>👨‍🏫 Trainer Dashboard</h2>
        <?php foreach ($messages as $m) echo $m; ?>

        <!-- Add Student Section -->
        <div class="ihd-trainer-add-form">
            <h3>➕ Add New Student</h3>
            <form method="post">
                <?php wp_nonce_field('ihd_add_student_action','ihd_add_student_nonce'); ?>
                <div class="ihd-trainer-form-grid">
                    <input type="text" name="student_name" placeholder="Student Name" required>
                    <input type="tel" name="phone" placeholder="Phone Number">
                    <input type="text" name="timing" placeholder="Timing (e.g., 7-8AM, 2-3PM, 7-8PM)" required>
                    <select name="schedule_type" required>
                        <option value="weekdays">📅 Weekdays (Mon-Fri)</option>
                        <option value="weekends">🏖️ Weekends (Sat-Sun)</option>
                    </select>
                    <select name="training_mode" required>
                        <option value="offline">🏢 Offline</option>
                        <option value="online">💻 Online</option>
                    </select>
                    <select name="course" required>
                        <option value="">📚 Select Course</option>
                        <?php foreach ($modules as $id => $name) echo '<option value="'.$id.'">'.esc_html($name).'</option>'; ?>
                    </select>
                    <input type="date" name="start_date" required>
                    <input type="number" name="completion" placeholder="Completion %" min="0" max="100" value="0">
                </div>
                <button type="submit" name="ihd_add_student">👥 Add Student</button>
            </form>
        </div>
		<div class="ihd-trainer-tabs" id="ihdTrainerTabs">
            <div class="ihd-trainer-tab ihd-active" data-target="active-students">🎯 Active Students</div>
            <div class="ihd-trainer-tab" data-target="completed-students">✅ Completed</div>
            <div class="ihd-trainer-tab" data-target="daily-report">📊 Daily Report</div>
            <div class="ihd-trainer-tab" data-target="attendance">📅 Attendance</div>
        </div>
        <div id="attendance" class="ihd-trainer-section">
            <?php echo ihd_trainer_attendance_tab(); ?>
        </div>
        <!-- Active Students Section with Batch Grouping -->
        <div id="active-students" class="ihd-trainer-section active">
        <h3>🎯 Active Students (<?php echo count($active_students); ?>)</h3>
        
        <?php if(!empty($batch_groups)) : ?>
            <?php foreach ($batch_groups as $batch_key => $group): 
            $course_name = get_term($group['course_id'], 'module')->name ?? '';
            $batch = $group['batch'];
            $has_active_session = $batch && $batch->status === 'active';
            $student_count = count($group['students']);
            ?>
            <div class="batch-group">
                <div class="batch-header">
                    <div class="batch-info">
                        <h4>🎯 <?php echo esc_html($course_name); ?> Batch</h4>
                        <div class="batch-details">
                            Timing: <strong><?php echo esc_html($group['timing']); ?></strong> | 
                            Schedule: <strong><?php echo esc_html(ucfirst($group['schedule_type'])); ?></strong> | 
                            Students: <strong><?php echo $student_count; ?></strong>
                            <?php if ($batch): ?>
                                | Batch ID: <strong><?php echo esc_html($batch->batch_id); ?></strong>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="batch-actions">
                        <?php if (!$has_active_session): ?>
                            <form method="post">
                                <?php wp_nonce_field('ihd_start_batch_class_action','ihd_start_batch_class_nonce'); ?>
                                <input type="hidden" name="batch_key" value="<?php echo esc_attr($batch_key); ?>">
                                <button type="submit" name="ihd_start_batch_class" class="ihd-trainer-start-class-btn">
                                    🎬 Start Batch Class
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="ihd-trainer-active-session">
                                <div class="ihd-trainer-session-info">
                                    ⏱️ Batch Class in Progress...
                                    <div style="font-size: 0.9em; margin-top: 5px;">
                                        Batch ID: <strong><?php echo esc_html($batch->batch_id); ?></strong><br>
                                        Meeting ID: <strong><?php echo esc_html($batch->meeting_id); ?></strong><br>
                                        Started: <strong><?php echo date('M j, g:i A', strtotime($batch->start_time)); ?></strong>
                                    </div>
                                </div>
                                <div style="margin-bottom: 10px;">
                                    <strong>Meeting Link for Students:</strong><br>
                                    <input type="text" value="<?php echo 'https://placemen.unaux.com/join/?' . http_build_query(array(
                                        'meeting_join' => $batch->meeting_id,
                                        'batch_id' => $batch->batch_id
                                    )); ?>" 
                                    style="width: 100%; padding: 8px; font-size: 14px; margin: 5px 0;" readonly>
                                    <button onclick="copyText(this)" style="margin-top: 5px; padding: 8px 15px; background: #3498db; color: white; border: none; border-radius: 5px; cursor: pointer; width: 100%;">
                                        📋 Copy Student Link
                                    </button>
                                </div>
                                
                                <!-- Progress Notes Form for Batch End -->
                                <div class="ihd-progress-notes-form" style="overflow-x:auto;">
                                    <h4 style="margin-bottom: 15px; color: #2c3e50;">📝 Update Progress & Notes Before Ending Class</h4>
                                    <form method="post" id="endBatchForm_<?php echo esc_attr($batch->batch_id); ?>">
                                        <?php wp_nonce_field('ihd_end_batch_class_action','ihd_end_batch_class_nonce'); ?>
                                        <input type="hidden" name="batch_id" value="<?php echo esc_attr($batch->batch_id); ?>">
                                        
                                        <table class="ihd-progress-notes-table">
                                            <thead>
                                                <tr>
                                                    <th>Student Name</th>
                                                    <th>Current Progress</th>
                                                    <th>New Progress %</th>
                                                    <th>Class Notes</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($group['students'] as $s): 
                                                    $current_completion = get_post_meta($s->ID, 'completion', true) ?: 0;
                                                ?>
                                                <tr>
                                                    <td><strong><?php echo esc_html($s->post_title); ?></strong></td>
                                                    <td>
                                                        <div class="ihd-trainer-progress-container">
                                                            <span style="color:#3498db;font-weight:bold;"><?php echo intval($current_completion); ?>%</span>
                                                            <div class="ihd-trainer-progress-bar">
                                                                <div class="ihd-trainer-progress-fill" style="width: <?php echo intval($current_completion); ?>%;"></div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <input type="number" name="progress_percentage_<?php echo $s->ID; ?>" 
                                                               value="<?php echo intval($current_completion); ?>" 
                                                               min="0" max="100" class="ihd-progress-input" required>
                                                    </td>
                                                    <td>
                                                        <textarea name="progress_notes_<?php echo $s->ID; ?>" 
                                                                  class="ihd-notes-input" 
                                                                  placeholder="Enter class notes, topics covered, student performance, etc." required></textarea>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        
                                        <button type="submit" name="ihd_end_batch_class" class="ihd-trainer-end-class-btn" style="width: 100%; margin-top: 15px;" 
                                                onclick="return confirmEndBatch('<?php echo esc_js($batch->batch_id); ?>')">
                                            ⏹️ End Batch Class & Save Progress
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ihd-trainer-table-container">
                    <table class="ihd-trainer-table">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Phone</th>
                                <th>Mode</th>
                                <th>Start Date</th>
                                <th>Fee Status</th>
                                <th>Progress</th>
                                <th>Update Progress</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($group['students'] as $s): 
                                $completion = get_post_meta($s->ID,'completion',true) ?: 0;
                                $phone = get_post_meta($s->ID,'phone',true) ?: '';
                                $training_mode = get_post_meta($s->ID,'training_mode',true) ?: 'offline';
                                $start_date = get_post_meta($s->ID,'start_date',true) ?: '';
                                $fee_status = get_post_meta($s->ID, 'fee_status', true) ?: 'pending';
                                $status_class = 'ihd-trainer-status-badge status-' . $fee_status;
                                $mode_class = 'ihd-trainer-mode-badge mode-' . $training_mode;
                                $last_updated = get_post_meta($s->ID, 'completion_updated', true);
                            ?>
                            <tr>
                                <td data-label="Student Name"><strong><?php echo esc_html($s->post_title); ?></strong></td>
                                <td data-label="Phone"><?php echo esc_html($phone); ?></td>
                                <td data-label="Mode"><span class="<?php echo $mode_class; ?>"><?php echo esc_html(ucfirst($training_mode)); ?></span></td>
                                <td data-label="Start Date"><?php echo esc_html($start_date); ?></td>
                                <td data-label="Fee Status"><span class="<?php echo $status_class; ?>"><?php echo esc_html(ucfirst($fee_status)); ?></span></td>
                                <td data-label="Progress">
                                    <div class="ihd-trainer-progress-container">
                                        <span style="color:#3498db;font-weight:bold;"><?php echo intval($completion); ?>%</span>
                                        <div class="ihd-trainer-progress-bar">
                                            <div class="ihd-trainer-progress-fill" style="width: <?php echo intval($completion); ?>%;"></div>
                                        </div>
                                    </div>
                                    <?php if ($last_updated): ?>
                                        <span class="ihd-trainer-last-updated">
                                            Updated: <?php echo date('M j, Y g:i A', strtotime($last_updated)); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Update Progress">
                                    <form method="post" class="ihd-trainer-update-form">
                                        <?php wp_nonce_field('ihd_update_completion_action','ihd_update_completion_nonce'); ?>
                                        <input type="hidden" name="student_id" value="<?php echo $s->ID; ?>">
                                        <div class="ihd-trainer-update-inputs">
                                            <input type="number" name="completion" value="<?php echo intval($completion); ?>" min="0" max="100" 
                                                   <?php echo ($fee_status === 'hold') ? 'disabled' : ''; ?> placeholder="%">
                                            <button type="submit" name="ihd_update_completion" <?php echo ($fee_status === 'hold') ? 'disabled' : ''; ?>>📈 Update</button>
                                        </div>
                                        <textarea name="progress_notes" placeholder="Add progress notes (optional)" 
                                                  <?php echo ($fee_status === 'hold') ? 'disabled' : ''; ?>></textarea>
                                    </form>
                                    <?php if ($fee_status === 'hold'): ?>
                                        <span class="ihd-trainer-hold-notice">⛔ Update blocked - fees pending</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="ihd-trainer-no-students">
                <p>📭 No active students yet. Add your first student above!</p>
            </div>
        <?php endif; ?>
		</div>
        <!-- Completed Students Section -->
        <div id="completed-students" class="ihd-trainer-section">
        <?php if(!empty($completed_students)) : ?>
            <h3>✅ Completed Students (<?php echo count($completed_students); ?>)</h3>
            <div class="ihd-trainer-table-container">
                <table class="ihd-trainer-table">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Phone</th>
                            <th>Course</th>
                            <th>Timing</th>
                            <th>Schedule</th>
                            <th>Start Date</th>
                            <th>Completion</th>
                            <th>Certificate ID</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($completed_students as $s) : 
                            $course_name = get_term(get_post_meta($s->ID,'course_id',true),'module')->name ?? '';
                            $completion = get_post_meta($s->ID,'completion',true) ?: 0;
                            $cert_id = get_post_meta($s->ID,'certificate_id',true) ?: 'Pending';
                            $phone = get_post_meta($s->ID,'phone',true) ?: '';
                            $timing = get_post_meta($s->ID,'timing',true) ?: '';
                            $schedule_type = get_post_meta($s->ID,'schedule_type',true) ?: 'weekdays';
                            $start_date = get_post_meta($s->ID,'start_date',true) ?: '';
                            $last_updated = get_post_meta($s->ID, 'completion_updated', true);
                        ?>
                        <tr>
                            <td data-label="Student Name"><strong><?php echo esc_html($s->post_title); ?></strong></td>
                            <td data-label="Phone"><?php echo esc_html($phone); ?></td>
                            <td data-label="Course"><?php echo esc_html($course_name); ?></td>
                            <td data-label="Timing"><?php echo esc_html($timing); ?></td>
                            <td data-label="Schedule">
                                <?php if ($schedule_type === 'weekends'): ?>
                                    <span class="ihd-trainer-weekends-badge">Weekends</span>
                                <?php else: ?>
                                    <span class="ihd-trainer-weekdays-badge">Weekdays</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Start Date"><?php echo esc_html($start_date); ?></td>
                            <td data-label="Completion">
                                <div class="ihd-trainer-progress-container">
                                    <span style="color:#27ae60;font-weight:bold;"><?php echo intval($completion); ?>%</span>
                                    <div class="ihd-trainer-progress-bar">
                                        <div class="ihd-trainer-progress-fill" style="width: 100%; background: #27ae60;"></div>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Certificate ID">
                                <?php if($cert_id !== 'Pending'): ?>
                                    <span style="color:#27ae60;font-weight:bold;"><?php echo esc_html($cert_id); ?></span>
                                <?php else: ?>
                                    <span style="color:#e67e22; font-style: italic;"><?php echo esc_html($cert_id); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        </div>
        <!-- Daily Report Tab -->
        <div id="daily-report" class="ihd-trainer-section">
            <?php echo ihd_trainer_daily_report_tab(); ?>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
    // Tab functionality
        const trainerTabs = document.querySelectorAll('.ihd-trainer-tab');

        trainerTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                // Remove active class from all tabs
                document.querySelectorAll('.ihd-trainer-tab').forEach(t => {
                    t.classList.remove('ihd-active');
                });

                // Add active class to clicked tab
                tab.classList.add('ihd-active');

                // Hide all sections
                document.querySelectorAll('.ihd-trainer-section').forEach(sec => {
                    sec.classList.remove('active');
                });

                // Show target section
                const targetId = tab.getAttribute('data-target');
                const targetSection = document.getElementById(targetId);
                if (targetSection) {
                    targetSection.classList.add('active');
                }

                // Update URL parameter
                const url = new URL(window.location);
                url.searchParams.set('tab', targetId);
                window.history.pushState({}, '', url);
            });
        });

        // Check for tab parameter in URL
        const urlParams = new URLSearchParams(window.location.search);
        const activeTab = urlParams.get('tab');
        if (activeTab) {
            const tabElement = document.querySelector(`.ihd-trainer-tab[data-target="${activeTab}"]`);
            if (tabElement) {
                tabElement.click();
            }
        }
    });
    function copyText(button) {
        const input = button.previousElementSibling;
        input.select();
        document.execCommand('copy');
        const originalText = button.textContent;
        button.textContent = '✅ Copied!';
        setTimeout(() => {
            button.textContent = originalText;
        }, 2000);
    }

    // Add this temporary debug function
    function debugFormValidation() {
        console.log('=== DEBUG FORM VALIDATION ===');

        const progressInputs = document.querySelectorAll('input[type="number"][name^="progress_percentage_"]');
        const notesInputs = document.querySelectorAll('textarea[name^="progress_notes_"]');

        progressInputs.forEach((input, index) => {
            console.log(`Progress ${index + 1}:`, {
                name: input.name,
                value: input.value,
                parsed: parseInt(input.value),
                valid: !isNaN(parseInt(input.value)) && parseInt(input.value) >= 0 && parseInt(input.value) <= 100
            });
        });

        notesInputs.forEach((input, index) => {
            console.log(`Notes ${index + 1}:`, {
                name: input.name,
                value: input.value,
                trimmed: input.value.trim(),
                valid: input.value && input.value.trim().length > 0
            });
        });

        console.log('=== END DEBUG ===');
    }

    // Update your confirmEndBatch function to include debugging:
    function confirmEndBatch(batchId) {
        // Validate all progress inputs
        let allValid = true;
        let firstInvalidField = null;
        const invalidStudents = [];

        const progressInputs = document.querySelectorAll('input[type="number"][name^="progress_percentage_"]');
        const notesInputs = document.querySelectorAll('textarea[name^="progress_notes_"]');

        // Reset all borders first
        progressInputs.forEach(input => input.style.borderColor = '');
        notesInputs.forEach(input => input.style.borderColor = '');

        // Validate each student
        progressInputs.forEach(input => {
            const studentId = input.name.replace('progress_percentage_', '');
            const progressValue = parseInt(input.value);
            const notesInput = document.querySelector(`textarea[name="progress_notes_${studentId}"]`);
            const notesValue = notesInput ? notesInput.value.trim() : '';

            let studentValid = true;
            let issues = [];

            // Check progress
            if (isNaN(progressValue) || progressValue < 0 || progressValue > 100) {
                studentValid = false;
                input.style.borderColor = '#e74c3c';
                issues.push('Progress must be 0-100');
                if (!firstInvalidField) firstInvalidField = input;
            }

            // Check notes
            if (!notesValue) {
                studentValid = false;
                notesInput.style.borderColor = '#e74c3c';
                issues.push('Notes required');
                if (!firstInvalidField) firstInvalidField = notesInput;
            }

            if (!studentValid) {
                allValid = false;
                const studentRow = input.closest('tr');
                const studentName = studentRow ? studentRow.querySelector('td:first-child strong').textContent : `Student ${studentId}`;
                invalidStudents.push(`${studentName}: ${issues.join(', ')}`);
            }
        });

        if (!allValid) {
            const errorMessage = '❌ Please fix the following issues:\n\n' + 
                               invalidStudents.join('\n') + 
                               '\n\nAll fields must be filled before ending the class.';
            alert(errorMessage);

            // Scroll to and focus on the first problem field
            if (firstInvalidField) {
                firstInvalidField.scrollIntoView({ behavior: 'smooth', block: 'center' });
                firstInvalidField.focus();
            }

            return false;
        }

        return confirm('Are you sure you want to end the batch class session?\n\nBatch ID: ' + batchId + '\n\nThis will record attendance, progress, and notes for all students.');
    }
    function autoSaveProgressNotes() {
        // Get batch ID from active batch forms
        const batchForms = document.querySelectorAll('form[id^="endBatchForm_"]');

        batchForms.forEach(form => {
            const batchIdInput = form.querySelector('input[name="batch_id"]');
            if (batchIdInput && batchIdInput.value) {
                const batchId = batchIdInput.value;

                // Auto-save progress notes for this batch
                const progressData = {};
                const progressInputs = form.querySelectorAll('input[type="number"][name^="progress_percentage_"]');
                const notesInputs = form.querySelectorAll('textarea[name^="progress_notes_"]');

                progressInputs.forEach(input => {
                    const studentId = input.name.replace('progress_percentage_', '');
                    if (!progressData[studentId]) {
                        progressData[studentId] = {};
                    }
                    progressData[studentId].progress = input.value;
                });

                notesInputs.forEach(input => {
                    const studentId = input.name.replace('progress_notes_', '');
                    if (!progressData[studentId]) {
                        progressData[studentId] = {};
                    }
                    progressData[studentId].notes = input.value;
                });

                // Save to localStorage
                if (Object.keys(progressData).length > 0) {
                    localStorage.setItem('autoSave_batch_' + batchId, JSON.stringify(progressData));
                    console.log('Auto-saved progress for batch:', batchId, progressData);
                }
            }
        });
    }

    function loadAutoSavedProgress(batchId) {
        const savedData = localStorage.getItem('autoSave_batch_' + batchId);
        if (savedData) {
            const progressData = JSON.parse(savedData);

            Object.keys(progressData).forEach(studentId => {
                const progressInput = document.querySelector(`input[name="progress_percentage_${studentId}"]`);
                const notesInput = document.querySelector(`textarea[name="progress_notes_${studentId}"]`);

                if (progressInput && progressData[studentId].progress) {
                    progressInput.value = progressData[studentId].progress;
                }
                if (notesInput && progressData[studentId].notes) {
                    notesInput.value = progressData[studentId].notes;
                }
            });

            console.log('Loaded auto-saved progress for batch:', batchId);
        }
    }

    function clearAutoSavedProgress(batchId) {
        localStorage.removeItem('autoSave_batch_' + batchId);
        console.log('Cleared auto-saved progress for batch:', batchId);
    }
	function toggleNotes(classId) {
                const notesElement = document.getElementById('notes-' + classId);
                const button = notesElement.nextElementSibling;
                
                if (notesElement.classList.contains('expanded')) {
                    notesElement.classList.remove('expanded');
                    button.textContent = 'More';
                } else {
                    notesElement.classList.add('expanded');
                    button.textContent = 'Less';
                }
     }
            
   function exportDailyReport() {
        // Get all the data from the table
        const table = document.querySelector('.ihd-daily-report-table');
        const rows = table.querySelectorAll('tbody tr');

        // Create CSV content
        let csvContent = "Date,Student Name,Course,Timing,Mode,Class Duration (min),Class Duration (hours),Progress %,Notes\n";

        // Loop through each row in the table
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');

            // Extract data from each cell
            const dateCell = cells[0];
            const dateText = dateCell.querySelector('strong').textContent.trim();
            const timeText = dateCell.querySelector('small').textContent.trim();
            const fullDate = `${dateText} ${timeText}`;

            const studentName = cells[1].querySelector('strong').textContent.trim();
            const course = cells[2].textContent.trim();
            const timing = cells[3].textContent.trim();

            const modeElement = cells[4].querySelector('span');
            const mode = modeElement ? modeElement.textContent.trim() : cells[4].textContent.trim();

            const durationElement = cells[5];
            const durationText = durationElement.textContent.trim();
            const durationMatch = durationText.match(/(\d+)\s*min/);
            const durationMin = durationMatch ? durationMatch[1] : '0';
            const durationHours = (parseInt(durationMin) / 60).toFixed(2);

            const progressElement = cells[6];
            const progressMatch = progressElement.textContent.match(/(\d+)%/);
            const progress = progressMatch ? progressMatch[1] : '0';

            const notesElement = cells[7].querySelector('.ihd-class-notes');
            let notes = notesElement ? notesElement.textContent.trim() : cells[7].textContent.trim();

            // Escape quotes in notes for CSV
            notes = notes.replace(/"/g, '""');

            // Add row to CSV
            csvContent += `"${fullDate}","${studentName}","${course}","${timing}","${mode}","${durationMin}","${durationHours}","${progress}","${notes}"\n`;
        });

        // Create and download file
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);

        // Get date range for filename
        const startDate = document.getElementById('report_start_date').value;
        const endDate = document.getElementById('report_end_date').value;
        const filename = `daily-class-report-${startDate}-to-${endDate}.csv`;

        link.setAttribute('href', url);
        link.setAttribute('download', filename);
        link.style.visibility = 'hidden';

        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
    document.addEventListener('DOMContentLoaded', function() {
        // Set default date to today
        const today = new Date().toISOString().split('T')[0];
        const dateInput = document.querySelector('input[name="start_date"]');
        if (dateInput && !dateInput.value) {
            dateInput.value = today;
        }

        // Real-time progress bar updates
        document.addEventListener('input', function(e) {
            if (e.target.name === 'completion') {
                const form = e.target.closest('.ihd-trainer-update-form');
                if (form) {
                    const value = parseInt(e.target.value) || 0;
                    const progressBar = form.closest('tr').querySelector('.ihd-trainer-progress-fill');
                    if (progressBar) {
                        progressBar.style.width = Math.min(100, Math.max(0, value)) + '%';
                    }
                }
            }

            // Auto-save when user types in batch progress forms
            if (e.target.name.startsWith('progress_percentage_') || e.target.name.startsWith('progress_notes_')) {
                const form = e.target.closest('form[id^="endBatchForm_"]');
                if (form) {
                    const batchIdInput = form.querySelector('input[name="batch_id"]');
                    if (batchIdInput) {
                        setTimeout(() => autoSaveProgressNotes(), 500); // Debounce
                    }
                }
            }
        });

        // Load auto-saved data for active batches
        const batchForms = document.querySelectorAll('form[id^="endBatchForm_"]');
        batchForms.forEach(form => {
            const batchIdInput = form.querySelector('input[name="batch_id"]');
            if (batchIdInput && batchIdInput.value) {
                loadAutoSavedProgress(batchIdInput.value);
            }
        });

        // Clear auto-saved data when form is submitted
        const forms = document.querySelectorAll('form[id^="endBatchForm_"]');
        forms.forEach(form => {
            form.addEventListener('submit', function() {
                const batchIdInput = this.querySelector('input[name="batch_id"]');
                if (batchIdInput && batchIdInput.value) {
                    clearAutoSavedProgress(batchIdInput.value);
                }
            });
        });

        // Mobile menu toggle (if needed)
        const mobileMenuToggle = document.querySelector('.mobile-menu-toggle');
        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', function() {
                document.querySelector('.batch-actions').classList.toggle('mobile-open');
            });
        }
    });
    </script>
    <?php
    return ob_get_clean();
}


/* ---------------- JOB PORTAL WITH STUDENT ACCESS CONTROL & LINKEDIN SCRAPER ---------------- */
function ihd_job_portal_access_shortcode() {
    ob_start();
    ?>
    
    <style>
        /* Job Portal Access Styles - Traditional Professional Design */
        .job-portal-access {
            max-width: 800px;
            margin: 0 auto;
            padding: 30px 15px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            min-height: 100vh;
        }

        .access-container {
            background: white;
            padding: 35px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            border: 1px solid #e1e5e9;
            text-align: center;
        }

        .access-header {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eaeaea;
        }

        .access-header h2 {
            color: #2c3e50;
            font-size: 1.8rem;
            margin-bottom: 12px;
            font-weight: 600;
            line-height: 1.3;
        }

        .access-header p {
            color: #6c757d;
            font-size: 1rem;
            line-height: 1.5;
            margin: 0;
        }

        .access-form {
            max-width: 450px;
            margin: 0 auto;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #495057;
            font-size: 0.95rem;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            background: #fff;
            box-sizing: border-box;
        }

        .form-group input:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .access-btn {
            background: #3498db;
            color: white;
            border: none;
            padding: 14px 30px;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            width: 100%;
            box-shadow: 0 2px 4px rgba(52, 152, 219, 0.2);
        }

        .access-btn:hover {
            background: #2980b9;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(52, 152, 219, 0.3);
        }

        .access-btn:active {
            transform: translateY(0);
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 12px 16px;
            border-radius: 6px;
            margin: 15px 0;
            border-left: 4px solid #f5c6cb;
            text-align: center;
            font-size: 0.9rem;
        }

        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 12px 16px;
            border-radius: 6px;
            margin: 15px 0;
            border-left: 4px solid #c3e6cb;
            text-align: center;
            font-size: 0.9rem;
        }

        .access-info {
            background: #f8f9fa;
            padding: 18px;
            border-radius: 6px;
            margin-top: 25px;
            border-left: 4px solid #3498db;
            text-align: left;
        }

        .access-info h4 {
            color: #2c3e50;
            margin-bottom: 8px;
            font-size: 1rem;
            font-weight: 600;
        }

        .access-info p {
            color: #6c757d;
            margin: 4px 0;
            font-size: 0.9rem;
            line-height: 1.4;
        }

        /* Job Portal Styles - Traditional Table-like Layout */
        .job-portal-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 15px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            border: 1px solid #e1e5e9;
        }

        #job-filters {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin: 15px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
            border: 1px solid #e9ecef;
            flex-wrap: wrap;
        }

        #job-category, #job-experience, #job-location {
            padding: 10px 12px;
            font-size: 0.9rem;
            border: 1px solid #ced4da;
            border-radius: 4px;
            outline: none;
            cursor: pointer;
            transition: all 0.2s ease;
            background: white;
            min-width: 160px;
            box-sizing: border-box;
        }

        #job-list {
            margin: 0;
            padding: 0;
            display: block;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .job-card {
            border: 3px solid #e1e5e9;
            
            border-radius: 6px;
            padding: 15px;
            background: #fff;
            margin-bottom: 12px;
            transition: all 0.2s ease;
            display: block;
            cursor: pointer;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .job-card:hover {
            border-color: #3498db;
            box-shadow: 0 2px 8px rgba(52, 152, 219, 0.15);
            transform: translateY(-1px);
        }

        .job-card h3 {
            font-size: 1rem;
            margin-bottom: 8px;
            color: #2c3e50;
            font-weight: 600;
            line-height: 1.3;
        }

        .job-card p {
            margin: 3px 0;
            font-size: 0.85rem;
            color: #6c757d;
            line-height: 1.4;
        }

        .job-card a {
            margin-top: 10px;
            padding: 8px 16px;
            background: #0073b1;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            text-align: center;
            display: inline-block;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .job-card a:hover {
            background: #005885;
            transform: translateY(-1px);
        }

        .job-portal-header {
            text-align: center;
            margin-bottom: 20px;
            padding: 20px;
            background: #2c3e50;
            color: white;
            border-radius: 6px;
        }

        .job-portal-header h2 {
            margin: 0 0 8px 0;
            font-size: 1.5rem;
            font-weight: 600;
            line-height: 1.3;
        }

        .job-portal-header p {
            margin: 0;
            font-size: 0.95rem;
            opacity: 0.9;
            line-height: 1.4;
        }

        .logout-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 18px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.2s ease;
            margin-top: 15px;
        }

        .logout-btn:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }

        .loading-spinner {
            text-align: center;
            padding: 30px 20px;
        }

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3498db;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1.5s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Enhanced Mobile Responsiveness */
        @media (max-width: 768px) {
            .job-portal-access {
                padding: 20px 10px;
                background: white;
            }

            .access-container {
                padding: 25px 20px;
                box-shadow: none;
                border: none;
                border-radius: 0;
            }

            .access-header h2 {
                font-size: 1.5rem;
            }

            .access-header p {
                font-size: 0.95rem;
            }

            #job-filters {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
                padding: 12px;
                margin: 10px 0;
            }

            #job-category, #job-experience, #job-location {
                width: 100%;
                min-width: auto;
                font-size: 0.9rem;
                padding: 10px;
            }

            .job-portal-content {
                padding: 10px;
                box-shadow: none;
                border: none;
                border-radius: 0;
            }

            .job-portal-header {
                padding: 15px;
                margin-bottom: 15px;
            }

            .job-portal-header h2 {
                font-size: 1.3rem;
            }

            .job-portal-header p {
                font-size: 0.9rem;
            }

            .job-card {
                padding: 12px;
                margin-bottom: 10px;
            }

            .job-card h3 {
                font-size: 0.95rem;
            }

            .job-card p {
                font-size: 0.82rem;
            }

            .job-card a {
                font-size: 0.82rem;
                padding: 7px 14px;
            }

            .form-group input {
                font-size: 0.9rem;
                padding: 10px 12px;
            }

            .access-btn {
                font-size: 0.95rem;
                padding: 12px 20px;
            }

            .logout-btn {
                font-size: 0.82rem;
                padding: 7px 16px;
            }
        }

        @media (max-width: 480px) {
            .job-portal-access {
                padding: 15px 8px;
            }

            .access-container {
                padding: 20px 15px;
            }

            .access-header h2 {
                font-size: 1.3rem;
            }

            .access-header p {
                font-size: 0.9rem;
            }

            .access-info {
                padding: 15px;
            }

            .access-info h4 {
                font-size: 0.95rem;
            }

            .access-info p {
                font-size: 0.85rem;
            }

            .job-card {
                padding: 10px;
            }

            .job-card h3 {
                font-size: 0.9rem;
            }

            .job-card p {
                font-size: 0.8rem;
            }

            #job-filters {
                padding: 10px;
            }

            #job-category, #job-experience, #job-location {
                padding: 8px 10px;
                font-size: 0.85rem;
            }
        }

        /* Desktop Optimization */
        @media (min-width: 1200px) {
            .job-portal-access {
                max-width: 1000px;
            }

            .job-portal-content {
                max-width: 1400px;
            }

            #job-list {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
                gap: 15px;
            }

            .job-card {
                margin-bottom: 0;
                height: fit-content;
            }
        }

        /* Print Styles */
        @media print {
            .job-portal-access {
                background: white;
                padding: 0;
            }

            .access-container, .job-portal-content {
                box-shadow: none;
                border: 1px solid #000;
            }

            .access-btn, .logout-btn {
                display: none;
            }
        }
    </style>

    <div class="job-portal-access">
        <div class="access-container">
            <div class="access-header" id='access-header'>
                <h2>🎯 Job Portal Access</h2>
                <p>Enter your certificate details to access exclusive job opportunities tailored for our graduates</p>
            </div>
            
            <div id="access-message"></div>
            
            <div id="access-form-container">
                <form id="student-access-form" class="access-form">
                    <?php wp_nonce_field('ihd_job_portal_access_action', 'ihd_job_portal_access_nonce'); ?>
                    
                    <div class="form-group">
                        <label for="student_name">👨‍🎓 Student Name</label>
                        <input type="text" id="student_name" name="student_name" placeholder="Enter your full name as on certificate" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="certificate_id">🏆 Certificate ID</label>
                        <input type="text" id="certificate_id" name="certificate_id" placeholder="Enter your certificate ID (e.g., CERT-ABC123)" required>
                    </div>
                    
                    <button type="submit" class="access-btn" id="verify-access-btn">
                        🔑 Verify & Access Job Portal
                    </button>
                </form>
            </div>
            
            <div id="job-portal-container" style="display: none;">
                <!-- Job Portal will be loaded here -->
            </div>
            
            <div class="access-info">
                <h4>ℹ️ Access Information</h4>
                <p>• Only completed students with valid certificates can access the job portal</p>
                <p>• Your credentials will be saved for future visits</p>
                <p>• Contact support if you face any issues accessing the portal</p>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const accessForm = document.getElementById('student-access-form');
        const accessMessage = document.getElementById('access-message');
        const accessFormContainer = document.getElementById('access-form-container');
        const jobPortalContainer = document.getElementById('job-portal-container');
        const verifyBtn = document.getElementById('verify-access-btn');
        
        // Check if credentials are stored in localStorage
        function checkStoredCredentials() {
            const storedStudent = localStorage.getItem('job_portal_student_name');
            const storedCertId = localStorage.getItem('job_portal_certificate_id');
            
            if (storedStudent && storedCertId) {
                // Auto-verify with stored credentials
                document.getElementById('student_name').value = storedStudent;
                document.getElementById('certificate_id').value = storedCertId;
                verifyStudentAccess(storedStudent, storedCertId);
            }
        }
        
        // Verify student access
        function verifyStudentAccess(studentName, certificateId) {
            verifyBtn.innerHTML = '🔍 Verifying...';
            verifyBtn.disabled = true;
            
            const formData = new FormData();
            formData.append('action', 'ihd_verify_job_portal_access');
            formData.append('student_name', studentName);
            formData.append('certificate_id', certificateId);
            formData.append('nonce', '<?php echo wp_create_nonce('ihd_job_portal_access_action'); ?>');
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Store credentials in localStorage
                    localStorage.setItem('job_portal_student_name', studentName);
                    localStorage.setItem('job_portal_certificate_id', certificateId);
                    
                    // Show success message
                    showMessage('success', '✅ Access granted! Loading job portal...');
                    
                    // Hide form and show job portal
                    setTimeout(() => {
                        accessFormContainer.style.display = 'none';
                        jobPortalContainer.style.display = 'block';
                        loadJobPortal();
                    }, 1500);
                } else {
                    showMessage('error', data.data || '❌ Invalid credentials. Please check your name and certificate ID.');
                    verifyBtn.innerHTML = '🔑 Verify & Access Job Portal';
                    verifyBtn.disabled = false;
                    
                    // Clear invalid stored credentials
                    localStorage.removeItem('job_portal_student_name');
                    localStorage.removeItem('job_portal_certificate_id');
                }
            })
            .catch(error => {
                showMessage('error', '❌ Network error. Please try again.');
                verifyBtn.innerHTML = '🔑 Verify & Access Job Portal';
                verifyBtn.disabled = false;
                console.error('Error:', error);
            });
        }
        
        // Load job portal
        function loadJobPortal() {
            jobPortalContainer.innerHTML = `
                <div class="loading-spinner">
                    <div class="spinner"></div>
                    <h3>Loading Job Portal...</h3>
                    <p>Please wait while we load the latest job opportunities for you.</p>
                </div>
            `;
            
            // Load job portal content
            const jobPortalHTML = generateJobPortalContent();
            setTimeout(() => {
                jobPortalContainer.innerHTML = jobPortalHTML;
                initializeJobPortal();
            }, 1000);
        }
        
        // Generate job portal content
        function generateJobPortalContent() {
            return `
            <div class="job-portal-content">
                <div class="job-portal-header">
                    <h2 style="color:white;">🚀 Job Opportunities</h2>
                    <p>Exclusive job listings for our certified graduates</p>
                    <button class="logout-btn" onclick="jobPortalLogout()">🚪 Logout</button>
                </div>
                
                <div id="job-filters">
                    <select id="job-category">
                        <option value="Full Stack Developer">Full Stack</option>
                        <option value="Data Analyst">Data Analyst</option>
                        <option value="Data Science">Data Science</option>
                        <option value="SAP FICO">SAP FICO</option>
                        <option value="SAP MM">SAP MM</option>
                    </select>

                    <select id="job-experience">
                        <option value="fresher">Fresher</option>
                        <option value="1-3 years">1–3 Years</option>
                        <option value="3-5 years">3–5 Years</option>
                    </select>
                    
                    <select id="job-location">
                        <option value="all" selected>All Locations</option>
                        <option value="Chennai">Chennai</option>
                        <option value="Bengaluru">Bangalore</option>
                        <option value="Mumbai">Mumbai</option>
                        <option value="Delhi">Delhi</option>
                        <option value="Hyderabad">Hyderabad</option>
                        <option value="Pune">Pune</option>
                        <option value="Kolkata">Kolkata</option>
                        <option value="Remote">Remote</option>
                    </select>
                </div>

                <div id="job-list">
                    <div class="loading-spinner">
                        <div class="spinner"></div>
                        <h3>🔍 Searching for Jobs...</h3>
                        <p>We're finding the best opportunities for you</p>
                    </div>
                </div>

                <div id="job-error" style="display: none; text-align: center; padding: 20px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; margin: 20px;">
                    <h4>⚠️ Temporary Issue</h4>
                    <p>We're showing sample job listings. Real-time jobs will be back shortly.</p>
                </div>
            </div>`;
        }
        
        // Initialize job portal functionality
        function initializeJobPortal() {
            const categorySelect = document.getElementById("job-category");
            const expSelect = document.getElementById("job-experience");
            const locationSelect = document.getElementById("job-location");
            const jobList = document.getElementById("job-list");
            const jobError = document.getElementById("job-error");
            const accessheader = document.getElementById("access-header");
			accessheader.style.display='none';
            async function fetchJobs() {
                jobList.innerHTML = `
                    <div class="loading-spinner">
                        <div class="spinner"></div>
                        <h3>🔍 Searching for Jobs...</h3>
                        <p>We're finding the best opportunities for you</p>
                    </div>
                `;

                let formData = new FormData();
                formData.append("action", "ihd_fetch_linkedin_jobs");
                formData.append("category", categorySelect.value);
                formData.append("experience", expSelect.value);
                formData.append("location", locationSelect.value);

                try {
                    const res = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                        method: "POST",
                        body: formData
                    });

                    const jobs = await res.json();
                    
                    if (jobs && jobs.length > 0) {
                        displayJobs(jobs);
                        jobError.style.display = 'none';
                    } else {
                        // Fallback to sample jobs if no real jobs found
                        showFallbackJobs();
                        jobError.style.display = 'block';
                    }
                } catch (error) {
                    console.error('Error fetching jobs:', error);
                    showFallbackJobs();
                    jobError.style.display = 'block';
                }
            }

            function displayJobs(jobs) {
                jobList.innerHTML = "";
                
                let filteredJobs = jobs;
                if (locationSelect.value.toLowerCase().trim() !== "all") {
                    filteredJobs = jobs.filter(job =>
                        job.location && job.location.toLowerCase().includes(locationSelect.value.toLowerCase().trim())
                    );
                }
                
                if (filteredJobs.length > 0) {
                    filteredJobs.forEach(job => {
                        const card = document.createElement("div");
                        card.className = "job-card";
                        card.innerHTML = `
                            <h3>${job.title || 'Job Title'}</h3>
                            <p><b>Company:</b> ${job.company || 'Not specified'}</p>
                            <p><b>Location:</b> ${job.location || 'Not specified'}</p>
                            <p><b>Experience:</b> ${job.experience || 'Not specified'}</p>
                            <p><b>Posted:</b> ${job.posted || 'Recently'}</p>
                            <a href="${job.link || 'https://www.linkedin.com/jobs/'}" target="_blank" rel="noopener">Apply Now</a>
                        `;
                        jobList.appendChild(card);
                    });
                } else {
                    showFallbackJobs();
                }
            }

            function showFallbackJobs() {
                const fallbackJobs = [
                    {
                        title: "Full Stack Developer",
                        company: "Tech Solutions Inc",
                        location: "Bangalore",
                        experience: "fresher",
                        link: "https://www.linkedin.com/jobs/",
                        posted: "2 days ago"
                    },
                    {
                        title: "Data Analyst",
                        company: "Data Insights Ltd",
                        location: "Chennai",
                        experience: "1-3 years",
                        link: "https://www.linkedin.com/jobs/",
                        posted: "1 week ago"
                    },
                    {
                        title: "Junior Software Engineer",
                        company: "StartUp Ventures",
                        location: "Remote",
                        experience: "fresher",
                        link: "https://www.linkedin.com/jobs/",
                        posted: "3 days ago"
                    }
                ];
                
                jobList.innerHTML = "";
                fallbackJobs.forEach(job => {
                    const card = document.createElement("div");
                    card.className = "job-card";
                    card.innerHTML = `
                        <h3>${job.title}</h3>
                        <p><b>Company:</b> ${job.company}</p>
                        <p><b>Location:</b> ${job.location}</p>
                        <p><b>Experience:</b> ${job.experience}</p>
                        <p><b>Posted:</b> ${job.posted}</p>
                        <a href="${job.link}" target="_blank" rel="noopener">Apply Now</a>
                    `;
                    jobList.appendChild(card);
                });
            }

            // Event listeners
            if (categorySelect) categorySelect.addEventListener("change", fetchJobs);
            if (expSelect) expSelect.addEventListener("change", fetchJobs);
            if (locationSelect) locationSelect.addEventListener("change", fetchJobs);

            // Initial load
            fetchJobs();
        }
        
        // Show message
        function showMessage(type, message) {
            const className = type === 'success' ? 'success-message' : 'error-message';
            accessMessage.innerHTML = `<div class="${className}">${message}</div>`;
            
            // Auto-hide success messages after 5 seconds
            if (type === 'success') {
                setTimeout(() => {
                    accessMessage.innerHTML = '';
                }, 5000);
            }
        }
        
        // Form submission handler
        accessForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const studentName = document.getElementById('student_name').value.trim();
            const certificateId = document.getElementById('certificate_id').value.trim();
            
            if (!studentName || !certificateId) {
                showMessage('error', '❌ Please enter both your name and certificate ID.');
                return;
            }
            
            verifyStudentAccess(studentName, certificateId);
        });
        
        // Logout function (can be called from job portal)
        window.jobPortalLogout = function() {
            localStorage.removeItem('job_portal_student_name');
            localStorage.removeItem('job_portal_certificate_id');
            jobPortalContainer.style.display = 'none';
            accessFormContainer.style.display = 'block';
            accessForm.reset();
            showMessage('success', '👋 You have been logged out. You can login again anytime.');
        };
        
        // Check for stored credentials on page load
        checkStoredCredentials();
    });
    </script>
    <?php
    return ob_get_clean();
}

// AJAX handler for job portal access verification
add_action('wp_ajax_ihd_verify_job_portal_access', 'ihd_verify_job_portal_access');
add_action('wp_ajax_nopriv_ihd_verify_job_portal_access', 'ihd_verify_job_portal_access');

function ihd_verify_job_portal_access() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'ihd_job_portal_access_action')) {
        wp_send_json_error('Invalid request');
        return;
    }
    
    $student_name = sanitize_text_field($_POST['student_name'] ?? '');
    $certificate_id = sanitize_text_field($_POST['certificate_id'] ?? '');
    
    if (empty($student_name) || empty($certificate_id)) {
        wp_send_json_error('Please provide both name and certificate ID');
        return;
    }
    
    // Verify certificate exists and matches student name
    $query = new WP_Query(array(
        'post_type' => 'student',
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key' => 'status',
                'value' => 'completed',
                'compare' => '='
            ),
            array(
                'key' => 'certificate_id',
                'value' => $certificate_id,
                'compare' => '='
            )
        ),
        'posts_per_page' => 1
    ));
    
    $verified = false;
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_title = get_the_title();
            // Case-insensitive comparison
            if (strcasecmp(trim($post_title), trim($student_name)) === 0) {
                $verified = true;
                break;
            }
        }
        wp_reset_postdata();
    }
    
    if ($verified) {
        wp_send_json_success('Access granted');
    } else {
        wp_send_json_error('No matching certificate found. Please check your name and certificate ID.');
    }
}
// AJAX handler to fetch LinkedIn jobs
add_action("wp_ajax_ihd_fetch_linkedin_jobs", "ihd_fetch_linkedin_jobs");
add_action("wp_ajax_nopriv_ihd_fetch_linkedin_jobs", "ihd_fetch_linkedin_jobs");

function ihd_fetch_linkedin_jobs() {
    $category   = sanitize_text_field($_POST["category"] ?? '');
    $experience = sanitize_text_field($_POST["experience"] ?? '');
    $location   = sanitize_text_field($_POST["location"] ?? '');

    // LinkedIn jobs search URL with recent filter (past week)
    $url = "https://www.linkedin.com/jobs/search/?keywords=" . urlencode($category) . "&location=India&f_TPR=r604800";

    // Setup cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    // Fake browser headers to avoid bot detection
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36",
        "Accept-Language: en-US,en;q=0.9",
        "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8"
    ]);

    $html = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$html || $http_code !== 200) {
        // Return sample data if scraping fails
        wp_send_json(ihd_get_sample_jobs_data($category, $experience, $location));
        return;
    }

    // Load HTML into DOM parser
    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // Suppress HTML parsing errors
    @$dom->loadHTML($html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);

    $filtered_jobs = [];
    $processed_jobs = []; // Track processed job titles to avoid duplicates

    // Extract job cards - multiple selector patterns for LinkedIn
    $nodes = $xpath->query("//ul[contains(@class, 'jobs-search__results-list')]//li | //div[contains(@class, 'job-search-card')] | //li[contains(@class, 'job-result-card')]");

    foreach ($nodes as $node) {
        $titleNode   = $xpath->query(".//h3 | .//h2 | .//a[contains(@class, 'job-title')] | .//span[contains(@class, 'job-title')]", $node)->item(0);
        $companyNode = $xpath->query(".//h4 | .//a[contains(@class, 'company-name')] | .//span[contains(@class, 'company-name')]", $node)->item(0);
        $locationNode= $xpath->query(".//span[contains(@class,'location')] | .//span[contains(@class,'job-search-card__location')] | .//span[contains(@class, 'job-location')]", $node)->item(0);
        $linkNode    = $xpath->query(".//a[contains(@href, '/jobs/')]", $node)->item(0);
        $timeNode    = $xpath->query(".//time | .//span[contains(@class, 'posted-time')]", $node)->item(0);

        $jobLocation = $locationNode ? trim($locationNode->textContent) : "N/A";
        $jobTitle = $titleNode ? trim($titleNode->textContent) : "N/A";
        $jobCompany = $companyNode ? trim($companyNode->textContent) : "N/A";
        
        // Skip if essential data is missing
        if ($jobTitle === "N/A" || $jobCompany === "N/A") {
            continue;
        }

        // Create a unique identifier for the job to avoid duplicates
        $job_identifier = md5($jobTitle . $jobCompany . $jobLocation);
        
        // Skip if we've already processed this job
        if (in_array($job_identifier, $processed_jobs)) {
            continue;
        }
        
        // Add to processed jobs
        $processed_jobs[] = $job_identifier;

        // Fix the link issue - handle both relative and absolute URLs properly
        $jobLink = "#";
        if ($linkNode) {
            $href = $linkNode->getAttribute("href");
            if (!empty($href)) {
                // If it's already a full URL, use it as is
                if (strpos($href, 'http') === 0) {
                    $jobLink = $href;
                } 
                // If it's a relative URL starting with /jobs/, make it absolute
                else if (strpos($href, '/jobs/') === 0) {
                    $jobLink = 'https://www.linkedin.com' . $href;
                }
                // If it's any other relative URL, make it absolute
                else if (strpos($href, '/') === 0) {
                    $jobLink = 'https://www.linkedin.com' . $href;
                }
                // Otherwise, use as is (might be malformed)
                else {
                    $jobLink = $href;
                }
                
                // Clean up any double protocols or domains
                $jobLink = str_replace('https://www.linkedin.comhttps://', 'https://', $jobLink);
                $jobLink = str_replace('https://www.linkedin.comhttp://', 'https://', $jobLink);
            }
        }

        $job_data = [
            "title"      => $jobTitle,
            "company"    => $jobCompany,
            "location"   => $jobLocation,
            "experience" => $experience,
            "link"       => $jobLink,
            "posted"     => $timeNode ? trim($timeNode->textContent) : "Recently"
        ];

        // Filter by location if specific location is selected (not "all")
        if ($location === 'all') {
            $filtered_jobs[] = $job_data;
        } else {
            // Check if job location includes the selected location (case-insensitive)
            if (stripos($jobLocation, $location) !== false) {
                $filtered_jobs[] = $job_data;
            }
        }
    }

    // If no jobs found with current selectors, use sample data
    if (empty($filtered_jobs)) {
        $filtered_jobs = ihd_get_sample_jobs_data($category, $experience, $location);
    } else {
        // Sort jobs: recent first, then freshers on top
        usort($filtered_jobs, function($a, $b) {
            // First prioritize by recency
            $a_recency = ihd_get_recency_score($a["posted"]);
            $b_recency = ihd_get_recency_score($b["posted"]);
            
            if ($a_recency != $b_recency) {
                return $b_recency - $a_recency;
            }
            
            // Then prioritize freshers
            if (stripos($a["experience"], "fresher") !== false && stripos($b["experience"], "fresher") === false) return -1;
            if (stripos($b["experience"], "fresher") !== false && stripos($a["experience"], "fresher") === false) return 1;
            
            return 0;
        });

        // Return top 20 jobs
        $filtered_jobs = array_slice($filtered_jobs, 0, 20);
    }

    wp_send_json($filtered_jobs);
}
// Helper function to score job recency
function ihd_get_recency_score($posted_time) {
    if (stripos($posted_time, 'minute') !== false) return 100;
    if (stripos($posted_time, 'hour') !== false) return 90;
    if (stripos($posted_time, 'today') !== false) return 80;
    if (stripos($posted_time, 'day') !== false) {
        preg_match('/(\d+)/', $posted_time, $matches);
        $days = isset($matches[1]) ? (int)$matches[1] : 7;
        return max(0, 70 - $days); // More days = lower score
    }
    return 0;
}

// Fallback function to provide sample job data
function ihd_get_sample_jobs_data($category, $experience, $location) {
    $sample_jobs = [
        [
            "title" => $category . " Developer",
            "company" => "Tech Solutions Inc",
            "location" => "Bangalore",
            "experience" => $experience,
            "link" => "https://www.linkedin.com/jobs/",
            "posted" => "2 days ago"
        ],
        [
            "title" => "Junior " . $category,
            "company" => "StartUp Ventures", 
            "location" => "Remote",
            "experience" => "fresher",
            "link" => "https://www.linkedin.com/jobs/",
            "posted" => "1 day ago"
        ],
        [
            "title" => "Senior " . $category,
            "company" => "Enterprise Solutions",
            "location" => "Mumbai",
            "experience" => "3-5 years", 
            "link" => "https://www.linkedin.com/jobs/",
            "posted" => "1 week ago"
        ],
        [
            "title" => $category . " Intern",
            "company" => "Digital Innovations",
            "location" => "Hyderabad",
            "experience" => "fresher",
            "link" => "https://www.linkedin.com/jobs/",
            "posted" => "Just now"
        ]
    ];
    
    // Filter by location if needed
    if ($location !== 'all') {
        $sample_jobs = array_filter($sample_jobs, function($job) use ($location) {
            return stripos($job['location'], $location) !== false;
        });
    }
    
    return array_values($sample_jobs);
}

// Register the shortcode
add_shortcode("job_portal_access", "ihd_job_portal_access_shortcode");

// Enqueue job portal assets
function ihd_job_portal_enqueue_assets() {
    wp_enqueue_script('jquery');
}
add_action('wp_enqueue_scripts', 'ihd_job_portal_enqueue_assets');
// Register all shortcodes
add_shortcode('hr_dashboard', 'ihd_hr_dashboard_shortcode');
add_shortcode('finance_dashboard', 'ihd_finance_dashboard_shortcode');
add_shortcode('manager_dashboard', 'ihd_manager_dashboard_shortcode');
add_shortcode('certificate_verification', 'ihd_certificate_verification_shortcode');
add_shortcode('ihd_trainer_dashboard','ihd_trainer_dashboard_shortcode');
add_shortcode('sales_dashboard', 'ihd_sales_dashboard_shortcode');
// Register complete class shortcode
add_shortcode('complete_class', 'ihd_complete_class_shortcode');
//add_shortcode("job_portal_access", "ihd_job_portal_access_shortcode");
add_shortcode("job_portal", "ihd_job_portal_shortcode");
?>