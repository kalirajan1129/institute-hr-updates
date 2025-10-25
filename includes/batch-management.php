<?php
// Batch Management Functions - FIXED VERSION

// Generate batch ID
function ihd_generate_batch_id($trainer_id, $course_id, $timing, $schedule_type) {
    $base = 'BATCH-' . $trainer_id . '-' . $course_id;
    $timing_clean = preg_replace('/[^a-zA-Z0-9]/', '', $timing);
    return $base . '-' . $timing_clean . '-' . strtoupper(substr($schedule_type, 0, 3));
}
function ihd_get_or_create_batch($trainer_id, $course_id, $timing, $schedule_type) {
    global $wpdb;
    $batch_table = $wpdb->prefix . 'ihd_batch_sessions';
    
    // Check for existing active batch
    $existing_batch = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $batch_table WHERE trainer_id = %d AND course_id = %d AND timing = %s AND schedule_type = %s AND status = 'active'",
        $trainer_id, $course_id, $timing, $schedule_type
    ));
    
    if ($existing_batch) {
        return $existing_batch;
    }
    
    // Create new batch
    $batch_id = 'BATCH-' . $trainer_id . '-' . $course_id . '-' . strtoupper(substr($schedule_type, 0, 3)) . '-' . time();
    $meeting_id = 'MEET-' . strtoupper(wp_generate_password(8, false));
    
    $result = $wpdb->insert(
        $batch_table,
        array(
            'batch_id' => $batch_id,
            'meeting_id' => $meeting_id,
            'trainer_id' => $trainer_id,
            'course_id' => $course_id,
            'timing' => $timing,
            'schedule_type' => $schedule_type,
            'start_time' => current_time('mysql'),
            'status' => 'active'
        ),
        array('%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s')
    );
    
    if ($result) {
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $batch_table WHERE batch_id = %s", $batch_id));
    }
    
    return false;
}

/**
 * End batch session with progress tracking
 */
// FIXED: Correct duration calculation with proper timezone handling
function ihd_end_batch_session_with_progress($batch_id, $trainer_id, $progress_notes) {
    global $wpdb;
    $batch_table = $wpdb->prefix . 'ihd_batch_sessions';
    
    // Get batch details
    $batch = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $batch_table WHERE batch_id = %s AND trainer_id = %d AND status = 'active'",
        $batch_id, $trainer_id
    ));
    
    if (!$batch) {
        error_log("IHD: Batch not found or already ended - Batch: $batch_id, Trainer: $trainer_id");
        return false;
    }
    
    // FIXED: Use WordPress time functions for consistent timezone handling
    $start_time = strtotime($batch->start_time . ' GMT'); // Convert to GMT first
    $end_time = current_time('timestamp'); // Get GMT timestamp (second parameter = 1 for GMT)
    
    // Calculate duration in minutes
    $duration_minutes = round(($end_time - $start_time) / 60);
    
    // DEBUG: Log the calculation details
    error_log("IHD: Duration Calculation - Batch: $batch_id");
    error_log("IHD: - Batch Start (DB): " . $batch->start_time);
    error_log("IHD: - Start Time (GMT): " . gmdate('Y-m-d H:i:s', $start_time) . " ($start_time)");
    error_log("IHD: - End Time (GMT): " . gmdate('Y-m-d H:i:s', $end_time) . " ($end_time)");
    error_log("IHD: - Difference (seconds): " . ($end_time - $start_time));
    error_log("IHD: - Duration (minutes): " . $duration_minutes);
    
    // Validate duration - it should be reasonable
    if ($duration_minutes < 1) {
        error_log("IHD: Duration too low, setting to 60 minutes");
        $duration_minutes = 60; // Default to 1 hour if calculation fails
    } elseif ($duration_minutes > 480) { // 8 hours max
        error_log("IHD: Duration too high, setting to 120 minutes");
        $duration_minutes = 120; // Default to 2 hours if unreasonable
    }
    
    // Update batch status with correct duration
    $result = $wpdb->update(
        $batch_table,
        array(
            'status' => 'completed',
            'end_time' => current_time('mysql'),
            'duration_minutes' => $duration_minutes
        ),
        array('batch_id' => $batch_id),
        array('%s', '%s', '%d'),
        array('%s')
    );
    
    if ($result === false) {
        error_log("IHD: Failed to update batch - " . $wpdb->last_error);
        return false;
    }
    
    // Process progress notes for each student
    $students_updated = 0;
    $sessions_recorded = 0;
    
    foreach ($progress_notes as $student_id => $data) {
        if (is_numeric($student_id) && $student_id > 0) {
            // Use the corrected duration for class minutes
            $class_minutes = $duration_minutes;
            
            // Track progress with notes and class time
            $progress_result = ihd_safe_track_daily_progress(
                $student_id, 
                $trainer_id, 
                $data['progress'], 
                $data['notes'],
                $class_minutes
            );
            
            if ($progress_result) {
                $students_updated++;
                $sessions_recorded++;
                
                error_log("IHD: Updated student $student_id with $class_minutes minutes");
            } else {
                error_log("IHD: FAILED to update student $student_id");
            }
        }
    }
    
    // Clear current batch assignment
    $students_cleared = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->postmeta} SET meta_value = '' 
         WHERE meta_key = 'current_batch_id' AND meta_value = %s",
        $batch_id
    ));
    
    error_log("IHD: Batch ended successfully - ID: $batch_id, Duration: $duration_minutes minutes, Students: $students_updated");
    
    return array(
        'duration' => $duration_minutes,
        'students_updated' => $students_updated,
        'students_cleared' => $students_cleared,
        'sessions_recorded' => $sessions_recorded
    );
}
/**
 * Get active batches for trainer
 */
function ihd_get_active_batches($trainer_id) {
    global $wpdb;
    $batch_table = $wpdb->prefix . 'ihd_batch_sessions';
    
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $batch_table WHERE trainer_id = %d AND status = 'active' ORDER BY start_time DESC",
        $trainer_id
    ));
}

// Debug duration setting
function ihd_debug_duration_source() {
    if (isset($_GET['debug_duration'])) {
        error_log("IHD: === DURATION DEBUG ===");
        
        // Check if there's any code multiplying by 60
        $plugins_dir = WP_PLUGIN_DIR;
        $results = array();
        
        // Search your plugin files for *60
        $plugin_files = glob($plugins_dir . '/institute-hr*/*.php');
        foreach ($plugin_files as $file) {
            $content = file_get_contents($file);
            if (preg_match('/\*\\s*60|\\*60|duration.*60|minutes.*60/i', $content)) {
                $lines = explode("\n", $content);
                foreach ($lines as $line_num => $line) {
                    if (preg_match('/\*\\s*60|\\*60|duration.*60|minutes.*60/i', $line)) {
                        $results[] = basename($file) . " line " . ($line_num + 1) . ": " . trim($line);
                    }
                }
            }
        }
        
        echo "<div class='notice notice-info'>";
        echo "<h3>Duration Multiplication Debug:</h3>";
        if (!empty($results)) {
            echo "<p>Found potential duration multiplication in these files:</p>";
            foreach ($results as $result) {
                echo "<p>$result</p>";
            }
        } else {
            echo "<p>No obvious duration multiplication found.</p>";
        }
        echo "</div>";
        
        error_log("IHD: Duration debug completed");
    }
}
add_action('init', 'ihd_debug_duration_source');
// Debug current batch data
function ihd_debug_current_batches() {
    if (isset($_GET['debug_current_batches'])) {
        global $wpdb;
        $batch_table = $wpdb->prefix . 'ihd_batch_sessions';
        $session_table = $wpdb->prefix . 'ihd_class_sessions';
        
        echo "<div class='notice notice-info'>";
        echo "<h3>Current Batches Debug</h3>";
        
        // Check batches
        $batches = $wpdb->get_results("SELECT * FROM $batch_table ORDER BY id DESC LIMIT 5");
        echo "<h4>Last 5 Batches:</h4>";
        foreach ($batches as $batch) {
            echo "<p>Batch ID: {$batch->batch_id} | Start: {$batch->start_time} | Status: {$batch->status} | Duration: {$batch->duration_minutes}min</p>";
        }
        
        // Check sessions
        $sessions = $wpdb->get_results("SELECT * FROM $session_table ORDER BY id DESC LIMIT 5");
        echo "<h4>Last 5 Sessions:</h4>";
        foreach ($sessions as $session) {
            echo "<p>Session ID: {$session->id} | Student: {$session->student_id} | Start: {$session->start_time} | Duration: {$session->duration_minutes}min</p>";
        }
        
        echo "<p>Current WordPress Time: " . current_time('mysql') . "</p>";
        echo "<p>Current PHP Time: " . date('Y-m-d H:i:s') . "</p>";
        echo "</div>";
    }
}
add_action('init', 'ihd_debug_current_batches');
// FIXED VERSION - Use datetime instead of just date
function ihd_update_student_progress_with_class_time($student_id, $trainer_id, $completion_percentage, $notes = '', $class_minutes = 0, $date = null) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ihd_student_progress';
    
    if (!$date) {
        $date = current_time('mysql'); // Use full datetime instead of just date
    }
    
    error_log("IHD: Updating progress - Student: $student_id, Completion: $completion_percentage%, Minutes: $class_minutes, DateTime: $date");
    
    // Use datetime for unique entries instead of just date
    $existing_entry = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE student_id = %d AND DATE(completion_date) = DATE(%s) ORDER BY id DESC LIMIT 1",
        $student_id, $date
    ));
    
    if ($existing_entry) {
        // Update existing entry for today
        $new_class_minutes = $existing_entry->class_minutes + $class_minutes;
        $result = $wpdb->update(
            $table_name,
            array(
                'completion_percentage' => $completion_percentage,
                'notes' => $notes,
                'class_minutes' => $new_class_minutes,
                'updated_by' => $trainer_id,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $existing_entry->id),
            array('%d', '%s', '%d', '%d', '%s'),
            array('%d')
        );
    } else {
        // Create new entry
        $result = $wpdb->insert(
            $table_name,
            array(
                'student_id' => $student_id,
                'trainer_id' => $trainer_id,
                'completion_date' => $date,
                'completion_percentage' => $completion_percentage,
                'notes' => $notes,
                'class_minutes' => $class_minutes,
                'updated_by' => $trainer_id,
                'updated_at' => current_time('mysql')
            ),
            array('%d', '%d', '%s', '%d', '%s', '%d', '%d', '%s')
        );
    }
    
    // Update student's overall completion
    update_post_meta($student_id, 'completion', $completion_percentage);
    update_post_meta($student_id, 'completion_updated', current_time('mysql'));
    
    return $result !== false;
}


// Update student batch assignment
function ihd_assign_student_to_batch($student_id, $batch_id) {
    $result = update_post_meta($student_id, 'current_batch_id', $batch_id);
    return $result;
}

// Clear student batch assignments
function ihd_clear_student_batch_assignments($batch_id) {
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
    
    $cleared_count = 0;
    foreach ($students as $student) {
        if (delete_post_meta($student->ID, 'current_batch_id')) {
            $cleared_count++;
        }
    }
    
    return $cleared_count;
}

// Enhanced function to get batch students with better name matching
function ihd_get_batch_students_enhanced($batch_id) {
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
    
    return $students;
}

// Get batch by ID with detailed debug
function ihd_get_batch_by_id($batch_id) {
    global $wpdb;
    $batch_table = $wpdb->prefix . 'ihd_batch_sessions';
    
    error_log("IHD: Looking up batch by ID: " . $batch_id);
    
    $batch = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $batch_table WHERE batch_id = %s",
        $batch_id
    ));
    
    if ($batch) {
        error_log("IHD: Batch found - ID: " . $batch->batch_id . ", Status: " . $batch->status . ", Trainer: " . $batch->trainer_id);
    } else {
        error_log("IHD: Batch NOT found with ID: " . $batch_id);
    }
    
    return $batch;
}

// Debug function to check all batches
function ihd_debug_all_batches() {
    global $wpdb;
    $batch_table = $wpdb->prefix . 'ihd_batch_sessions';
    
    $batches = $wpdb->get_results("SELECT * FROM $batch_table");
    
    error_log("IHD: === ALL BATCHES DEBUG ===");
    foreach ($batches as $batch) {
        error_log("IHD: Batch - ID: " . $batch->batch_id . 
                 ", Status: " . $batch->status . 
                 ", Trainer: " . $batch->trainer_id .
                 ", Start: " . $batch->start_time .
                 ", End: " . $batch->end_time);
    }
    error_log("IHD: === END BATCHES DEBUG ===");
    
    return $batches;
}

// FIXED: AJAX handler for ending batch with progress
add_action('wp_ajax_ihd_end_batch_with_progress', 'ihd_end_batch_with_progress_ajax');
function ihd_end_batch_with_progress_ajax() {
    check_ajax_referer('ihd_video_nonce', 'nonce');
    
    $batch_id = sanitize_text_field($_POST['batch_id']);
    $trainer_id = get_current_user_id();
    $progress_notes = array();
    
    error_log("IHD: AJAX - Received POST data keys: " . implode(', ', array_keys($_POST)));
    
    // Parse progress notes from POST data - FIXED VERSION
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'progress_') === 0) {
            $student_id = str_replace('progress_', '', $key);
            $student_id = intval($student_id);
            
            if ($student_id > 0) {
                $notes_key = 'notes_' . $student_id;
                $student_notes = isset($_POST[$notes_key]) ? sanitize_text_field($_POST[$notes_key]) : 'Class completed';
                
                $progress_notes[$student_id] = array(
                    'progress' => intval($value),
                    'notes' => $student_notes
                );
                
                error_log("IHD: Found progress for student $student_id - Progress: " . intval($value) . "%, Notes: '$student_notes'");
            }
        }
    }
    
    error_log("IHD: AJAX - Final progress notes: " . print_r($progress_notes, true));
    
    if (empty($progress_notes)) {
        error_log("IHD: ERROR - No progress notes found in POST data");
        wp_send_json_error('No progress data received');
        return;
    }
    
    $result = ihd_end_batch_session_with_progress($batch_id, $trainer_id, $progress_notes);
    
    if ($result) {
        wp_send_json_success(array(
            'message' => 'Batch ended successfully!',
            'duration' => $result['duration'],
            'sessions_recorded' => $result['sessions_recorded'],
            'progress_updated' => $result['progress_updated'],
            'details' => "Duration: {$result['duration']} minutes, Progress updated for {$result['progress_updated']} students"
        ));
    } else {
        wp_send_json_error('Failed to end batch session');
    }
}
?>