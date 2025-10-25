<?php
// Dashboard Shortcodes (HR, Finance, Manager, Certificate Verification)
// Add to your PHP file
function ihd_complete_class_shortcode() {
    ob_start();
    ?>
    <div class="ihd-complete-class" style="text-align: center; padding: 50px 20px;">
        <div style="font-size: 4em; margin-bottom: 20px;">✅</div>
        <h1 style="color: #27ae60; margin-bottom: 20px;">Class Completed Successfully!</h1>
        <p style="font-size: 1.2em; margin-bottom: 30px; color: #7f8c8d;">
            Thank you for attending the class. The session has ended.
        </p>
        <div style="margin-bottom: 30px;">
            <p><strong>What's next?</strong></p>
            <ul style="list-style: none; padding: 0;">
                <li>📚 Review the class materials</li>
                <li>📝 Complete any assigned exercises</li>
                <li>🕒 Join the next scheduled class</li>
            </ul>
        </div>
        <a href="<?php echo home_url(); ?>" style="
            display: inline-block;
            background: #3498db;
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 5px;
            font-size: 1.1em;
            transition: background 0.3s;
        " onmouseover="this.style.background='#2980b9'" onmouseout="this.style.background='#3498db'">
            Return to Homepage
        </a>
    </div>
    <?php
    return ob_get_clean();
}

// HR Dashboard Shortcode
function ihd_hr_dashboard_shortcode($atts){
    // Capability check - only HR Managers and Administrators can access HR dashboard
    if (!is_user_logged_in() || (!current_user_can('manage_trainers') && !current_user_can('administrator'))) {
        return '<p style="text-align: center; padding: 20px; color: #e74c3c; font-size: 1.2em;">Access denied. Only HR Managers and Administrators can access this dashboard.</p>';
    }

    // Check if user has write capabilities
    $can_edit = current_user_can('edit_trainers') || current_user_can('delete_trainers');
    $can_delete = current_user_can('delete_trainers');

    // Messages collected here
    $messages = array();

    /* ---------------- HANDLE MODULE ACTIONS ---------------- */
    // Add Module - only for users with edit capabilities
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_add_module'])) {
        if (!$can_edit) {
            $messages[] = '<div class="error">You do not have permission to add modules.</div>';
        } elseif (!isset($_POST['ihd_add_module_nonce']) || !wp_verify_nonce($_POST['ihd_add_module_nonce'], 'ihd_add_module_action')) {
            $messages[] = '<div class="error">Invalid request (module add).</div>';
        } else {
            $name = sanitize_text_field($_POST['module_name'] ?? '');
            if (empty($name)) {
                $messages[] = '<div class="error">Module name required.</div>';
            } else {
                $res = wp_insert_term($name, 'module');
                if (is_wp_error($res)) $messages[] = '<div class="error">Module add error: ' . esc_html($res->get_error_message()) . '</div>';
                else $messages[] = '<div class="updated">Module "' . esc_html($name) . '" added.</div>';
            }
        }
    }

    // Edit Module - only for users with edit capabilities
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_edit_module'])) {
        if (!$can_edit) {
            $messages[] = '<div class="error">You do not have permission to edit modules.</div>';
        } elseif (!isset($_POST['ihd_edit_module_nonce']) || !wp_verify_nonce($_POST['ihd_edit_module_nonce'], 'ihd_edit_module_action')) {
            $messages[] = '<div class="error">Invalid request (module edit).</div>';
        } else {
            $term_id = intval($_POST['module_id'] ?? 0);
            $newname = sanitize_text_field($_POST['module_name_edit'] ?? '');
            if ($term_id <= 0 || empty($newname)) $messages[] = '<div class="error">Invalid module data.</div>';
            else {
                $res = wp_update_term($term_id, 'module', array('name' => $newname));
                if (is_wp_error($res)) $messages[] = '<div class="error">Module update error: ' . esc_html($res->get_error_message()) . '</div>';
                else $messages[] = '<div class="updated">Module updated.</div>';
            }
        }
    }

    // Delete Module - only for users with delete capabilities
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_delete_module'])) {
        if (!$can_delete) {
            $messages[] = '<div class="error">You do not have permission to delete modules.</div>';
        } elseif (!isset($_POST['ihd_delete_module_nonce']) || !wp_verify_nonce($_POST['ihd_delete_module_nonce'], 'ihd_delete_module_action')) {
            $messages[] = '<div class="error">Invalid request (module delete).</div>';
        } else {
            $term_id = intval($_POST['module_id_delete'] ?? 0);
            if ($term_id <= 0) $messages[] = '<div class="error">Invalid module id.</div>';
            else {
                $res = wp_delete_term($term_id, 'module');
                if (is_wp_error($res)) $messages[] = '<div class="error">Module delete error: ' . esc_html($res->get_error_message()) . '</div>';
                else $messages[] = '<div class="updated">Module deleted.</div>';
            }
        }
    }

    /* ---------------- HANDLE TRAINER ACTIONS ---------------- */
    // Add Trainer - only for users with edit capabilities
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_add_trainer'])) {
        if (!$can_edit) {
            $messages[] = '<div class="error">You do not have permission to add trainers.</div>';
        } elseif (!isset($_POST['ihd_add_trainer_nonce']) || !wp_verify_nonce($_POST['ihd_add_trainer_nonce'], 'ihd_add_trainer_action')) {
            $messages[] = '<div class="error">Invalid request (trainer add).</div>';
        } else {
            $first   = sanitize_text_field($_POST['first_name'] ?? '');
            $last    = sanitize_text_field($_POST['last_name'] ?? '');
            $email   = sanitize_email($_POST['email'] ?? '');
            $modules = array_map('intval', (array) ($_POST['modules'] ?? array()));

            if (!is_email($email)) {
                $messages[] = '<div class="error">Enter a valid email.</div>';
            } elseif (email_exists($email)) {
                $messages[] = '<div class="error">Email already exists.</div>';
            } else {
                $base_username = strtolower(str_replace(' ', '_', $first . '_' . $last));
                $username = sanitize_user($base_username);
                $i = 1;
                while (username_exists($username)) {
                    $username = $base_username . $i;
                    $i++;
                }

                $password = wp_generate_password(12, true);

                $user_data = array(
                    'user_login' => $username,
                    'user_email' => $email,
                    'user_pass'  => $password,
                    'role'       => 'trainer',
                    'first_name' => $first,
                    'last_name'  => $last,
                );

                $user_id = wp_insert_user($user_data);

                if (is_wp_error($user_id)) {
                    $messages[] = '<div class="error">' . esc_html($user_id->get_error_message()) . '</div>';
                } else {
                    $trainer_id = ihd_generate_trainer_id($user_id);
                    update_user_meta($user_id, 'trainer_unique_id', $trainer_id);
                    update_user_meta($user_id, 'assigned_modules', $modules);

                    // Send credentials email
                    $sent = ihd_send_trainer_credentials_email($email, $first, $username, $password, $trainer_id);
                    if ($sent) {
                        $messages[] = '<div class="updated">Trainer created: ' . esc_html($trainer_id) . ' and credentials emailed.</div>';
                    } else {
                        $messages[] = '<div class="error">Trainer created, but email could not be sent.</div>';
                        error_log("Trainer email failed for {$email}");
                    }
                }
            }
        }
    }

    // Edit Trainer (update details) - only for users with edit capabilities
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_edit_trainer'])) {
        if (!$can_edit) {
            $messages[] = '<div class="error">You do not have permission to edit trainers.</div>';
        } elseif (!isset($_POST['ihd_edit_trainer_nonce']) || !wp_verify_nonce($_POST['ihd_edit_trainer_nonce'], 'ihd_edit_trainer_action')) {
            $messages[] = '<div class="error">Invalid request (trainer edit).</div>';
        } else {
            $user_id = intval($_POST['trainer_user_id'] ?? 0);
            $first = sanitize_text_field($_POST['first_name_edit'] ?? '');
            $last  = sanitize_text_field($_POST['last_name_edit'] ?? '');
            $email = sanitize_email($_POST['email_edit'] ?? '');
            $modules = array_map('intval', (array) ($_POST['modules_edit'] ?? array()));

            if ($user_id <= 0 || !get_user_by('id', $user_id)) {
                $messages[] = '<div class="error">Invalid trainer.</div>';
            } elseif (!is_email($email)) {
                $messages[] = '<div class="error">Invalid email.</div>';
            } else {
                // if email changed and exists for another user, block
                $existing = get_user_by('email', $email);
                if ($existing && $existing->ID != $user_id) {
                    $messages[] = '<div class="error">Email already used by another account.</div>';
                } else {
                    // update basic profile
                    $ud = array('ID' => $user_id, 'user_email' => $email, 'first_name' => $first, 'last_name' => $last);
                    $res = wp_update_user($ud);
                    if (is_wp_error($res)) {
                        $messages[] = '<div class="error">Update error: ' . esc_html($res->get_error_message()) . '</div>';
                    } else {
                        update_user_meta($user_id, 'assigned_modules', $modules);
                        // notify trainer
                        ihd_send_trainer_update_email($email, $first, "Your profile has been updated by HR.");
                        $messages[] = '<div class="updated">Trainer updated and notified.</div>';
                    }
                }
            }
        }
    }

    // Reset Password - only for users with edit capabilities
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_reset_password'])) {
        if (!$can_edit) {
            $messages[] = '<div class="error">You do not have permission to reset passwords.</div>';
        } elseif (!isset($_POST['ihd_reset_password_nonce']) || !wp_verify_nonce($_POST['ihd_reset_password_nonce'], 'ihd_reset_password_action')) {
            $messages[] = '<div class="error">Invalid request (reset password).</div>';
        } else {
            $user_id = intval($_POST['trainer_user_id_reset'] ?? 0);
            $user    = ($user_id > 0) ? get_user_by('id', $user_id) : false;

            if (!$user) {
                $messages[] = '<div class="error">Invalid trainer for reset.</div>';
            } else {
                $newpass = wp_generate_password(12, true);
                wp_set_password($newpass, $user_id);

                $first_name = $user->first_name ?: $user->user_login;
                $unique_id  = get_user_meta($user_id, 'trainer_unique_id', true);

                $sent = ihd_send_trainer_credentials_email(
                    $user->user_email,
                    $first_name,
                    $user->user_login,
                    $newpass,
                    $unique_id
                );

                if ($sent) {
                    $messages[] = '<div class="updated">Password reset and emailed to trainer.</div>';
                } else {
                    $messages[] = '<div class="error">Password reset, but email could not be sent.</div>';
                    error_log("Trainer reset email failed for user {$user->user_email} (ID: {$user_id})");
                }
            }
        }
    }

    // Delete Trainer - only for users with delete capabilities
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_delete_trainer'])) {
        if (!$can_delete) {
            $messages[] = '<div class="error">You do not have permission to delete trainers.</div>';
        } elseif (!isset($_POST['ihd_delete_trainer_nonce']) || !wp_verify_nonce($_POST['ihd_delete_trainer_nonce'], 'ihd_delete_trainer_action')) {
            $messages[] = '<div class="error">Invalid request (delete trainer).</div>';
        } else {
            $user_id = intval($_POST['trainer_user_id_delete'] ?? 0);
            if ($user_id <= 0 || !get_user_by('id', $user_id)) {
                $messages[] = '<div class="error">Invalid trainer for deletion.</div>';
            } else {
                require_once( ABSPATH . 'wp-admin/includes/user.php' );
                $deleted = wp_delete_user($user_id);
                if ($deleted) $messages[] = '<div class="updated">Trainer deleted.</div>';
                else $messages[] = '<div class="error">Trainer deletion failed.</div>';
            }
        }
    }

    /* ---------------- Prepare data for display ---------------- */
    $modules_terms = get_terms(array('taxonomy' => 'module', 'hide_empty' => false));
    $trainers = get_users(array('role' => 'trainer'));

    // Sort modules by student count (highest to lowest)
    if (!empty($modules_terms) && !is_wp_error($modules_terms)) {
        usort($modules_terms, function($a, $b) {
            $students_a = new WP_Query(array(
                'post_type' => 'student',
                'tax_query' => array(
                    array(
                        'taxonomy' => 'module',
                        'field' => 'term_id',
                        'terms' => $a->term_id,
                    )
                ),
                'posts_per_page' => -1,
                'fields' => 'ids'
            ));
            
            $students_b = new WP_Query(array(
                'post_type' => 'student',
                'tax_query' => array(
                    array(
                        'taxonomy' => 'module',
                        'field' => 'term_id',
                        'terms' => $b->term_id,
                    )
                ),
                'posts_per_page' => -1,
                'fields' => 'ids'
            ));
            
            return $students_b->found_posts - $students_a->found_posts;
        });
    }

    /* ---------------- Render the dashboard (tabs) ---------------- */
    ob_start();
    ?>
    
    <style>
    /* HR Dashboard Traditional Responsive Styles */
    .ihd-hr-dashboard {
        max-width: 100%;
        margin: 0 auto;
        padding: 20px 15px;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: #f5f5f5;
        min-height: 100vh;
        box-sizing: border-box;
        font-size: 14px;
    }

    .ihd-hr-dashboard h2 {
        text-align: center;
        margin-bottom: 25px;
        color: #2c3e50;
        font-size: 24px;
        font-weight: 600;
        padding-bottom: 15px;
        border-bottom: 2px solid #3498db;
    }

    .ihd-hr-dashboard h3 {
        margin: 25px 0 15px 0;
        color: #34495e;
        font-size: 18px;
        font-weight: 600;
        padding-bottom: 10px;
        border-bottom: 1px solid #bdc3c7;
    }

    .ihd-hr-dashboard h4 {
        margin: 15px 0 10px 0;
        color: #2c3e50;
        font-size: 15px;
        font-weight: 600;
    }

    /* Messages */
    .ihd-hr-dashboard .error {
        background: #ffe6e6;
        border: 1px solid #ffcccc;
        border-radius: 4px;
        padding: 12px 15px;
        margin-bottom: 15px;
        color: #cc0000;
        font-size: 13px;
        border-left: 4px solid #ff3333;
    }

    .ihd-hr-dashboard .updated {
        background: #e6ffe6;
        border: 1px solid #ccffcc;
        border-radius: 4px;
        padding: 12px 15px;
        margin-bottom: 15px;
        color: #006600;
        font-size: 13px;
        border-left: 4px solid #33cc33;
    }

    .ihd-hr-dashboard .notice-info {
        background: #e6f3ff;
        border: 1px solid #b3d9ff;
        border-radius: 4px;
        padding: 12px 15px;
        margin-bottom: 15px;
        color: #004080;
        font-size: 13px;
        border-left: 4px solid #3399ff;
    }

    /* Tabs */
    .ihd-hr-tabs {
        display: flex;
        margin-bottom: 20px;
        border-radius: 6px 6px 0 0;
        overflow: hidden;
        background: #fff;
        border: 1px solid #ddd;
        border-bottom: none;
    }

    .ihd-hr-tab {
        flex: 1;
        padding: 12px 15px;
        cursor: pointer;
        text-align: center;
        background: #ecf0f1;
        transition: all 0.2s ease;
        border: none;
        font-weight: 500;
        color: #7f8c8d;
        font-size: 13px;
        border-right: 1px solid #ddd;
    }

    .ihd-hr-tab:last-child {
        border-right: none;
    }

    .ihd-hr-tab.ihd-active {
        background: #3498db;
        color: #fff;
    }

    .ihd-hr-tab:hover:not(.ihd-active) {
        background: #d5dbdb;
    }

    /* Sections */
    .ihd-hr-section {
        display: none;
        background: #fff;
        padding: 20px;
        border-radius: 0 0 6px 6px;
        border: 1px solid #ddd;
        border-top: none;
    }

    .ihd-hr-section.active {
        display: block;
    }

    /* Forms */
    .ihd-hr-dashboard input[type="text"],
    .ihd-hr-dashboard input[type="email"],
    .ihd-hr-dashboard input[type="tel"],
    .ihd-hr-dashboard input[type="date"],
    .ihd-hr-dashboard input[type="number"],
    .ihd-hr-dashboard input[type="password"],
    .ihd-hr-dashboard select,
    .ihd-hr-dashboard textarea {
        padding: 8px 10px;
        border: 1px solid #ced4da;
        border-radius: 4px;
        width: 100%;
        box-sizing: border-box;
        font-size: 13px;
        transition: all 0.2s ease;
        background: #fff;
        height: 38px;
    }

    .ihd-hr-dashboard input:focus,
    .ihd-hr-dashboard select:focus,
    .ihd-hr-dashboard textarea:focus {
        border-color: #3498db;
        outline: none;
        box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.1);
    }

    .ihd-hr-dashboard button,
    .ihd-hr-dashboard .button {
        background: #3498db;
        color: #fff;
        border: 1px solid #2980b9;
        padding: 8px 16px;
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.2s ease;
        font-size: 13px;
        font-weight: 500;
        text-decoration: none;
        display: inline-block;
        text-align: center;
        margin: 5px;
        height: 38px;
        box-sizing: border-box;
    }

    .ihd-hr-dashboard button:hover,
    .ihd-hr-dashboard .button:hover {
        background: #2980b9;
        border-color: #2471a3;
    }

    .ihd-hr-dashboard button.delete-btn {
        background: #e74c3c;
        border-color: #c0392b;
    }

    .ihd-hr-dashboard button.delete-btn:hover {
        background: #c0392b;
        border-color: #a93226;
    }

    /* Inline Forms */
    .ihd-hr-inline-form {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
        margin: 15px 0;
    }

    /* Checkbox Labels */
    .ihd-hr-dashboard label {
        display: inline-flex;
        align-items: center;
        margin: 5px 10px 5px 0;
        font-size: 13px;
        cursor: pointer;
        padding: 6px 10px;
        border-radius: 4px;
        transition: background 0.2s ease;
    }

    .ihd-hr-dashboard label:hover {
        background: #f8f9fa;
    }

    .ihd-hr-dashboard input[type="checkbox"] {
        margin-right: 8px;
        transform: scale(1.1);
        accent-color: #3498db;
    }

    /* Traditional Tables */
    .ihd-hr-table {
        width: 100%;
        border-collapse: collapse;
        margin: 15px 0;
        background: #fff;
        border: 1px solid #ddd;
        font-size: 12px;
        table-layout: fixed;
    }

    .ihd-hr-table thead {
        background: #2c3e50;
    }

    .ihd-hr-table th {
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

    .ihd-hr-table th:last-child {
        border-right: none;
    }

    .ihd-hr-table td {
        padding: 8px;
        border-bottom: 1px solid #ecf0f1;
        vertical-align: top;
        border-right: 1px solid #ecf0f1;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }

    .ihd-hr-table td:last-child {
        border-right: none;
    }

    .ihd-hr-table tbody tr:hover {
        background: #f8f9fa;
    }

    .ihd-hr-table tbody tr:nth-child(even) {
        background: #fafbfc;
    }

    /* Stats Cards */
    .ihd-hr-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin: 20px 0;
    }

    .ihd-hr-stats-card {
        background: white;
        padding: 20px;
        border-radius: 6px;
        border: 1px solid #ddd;
        border-left: 4px solid #3498db;
        text-align: center;
        transition: transform 0.2s ease;
    }

    .ihd-hr-stats-card:hover {
        transform: translateY(-2px);
    }

    .ihd-hr-stats-card h4 {
        margin: 0 0 10px 0;
        color: #7f8c8d;
        font-size: 13px;
        font-weight: 600;
    }

    .ihd-hr-stats-card p {
        font-size: 20px;
        font-weight: 700;
        margin: 0;
        color: #3498db;
    }

    /* Form Groups */
    .ihd-hr-form-group {
        margin-bottom: 20px;
    }

    .ihd-hr-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 10px;
        margin-bottom: 15px;
    }

    /* Table Container */
    .ihd-hr-table-container {
        overflow-x: auto;
        border-radius: 6px;
        margin: 15px 0;
        -webkit-overflow-scrolling: touch;
        border: 1px solid #ddd;
    }

    /* Code styling */
    code {
        background: #f8f9fa;
        padding: 2px 6px;
        border-radius: 3px;
        border: 1px solid #e9ecef;
        font-family: 'Courier New', monospace;
        font-size: 11px;
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
        .ihd-hr-table {
            font-size: 11px;
        }
        
        .ihd-hr-table th,
        .ihd-hr-table td {
            padding: 6px 4px;
        }
    }

    @media (max-width: 768px) {
        .ihd-hr-dashboard {
            padding: 15px 10px;
            font-size: 13px;
        }
        
        .ihd-hr-dashboard h2 {
            font-size: 20px;
            margin-bottom: 20px;
        }
        
        .ihd-hr-dashboard h3 {
            font-size: 16px;
            margin: 20px 0 12px 0;
        }
        
        .ihd-hr-tabs {
            flex-direction: column;
        }
        
        .ihd-hr-tab {
            flex: none;
            padding: 12px 15px;
            font-size: 13px;
            border-right: none;
            border-bottom: 1px solid #ddd;
        }
        
        .ihd-hr-tab:last-child {
            border-bottom: none;
        }
        
        .ihd-hr-section {
            padding: 15px;
        }
        
        .ihd-hr-stats-grid {
            grid-template-columns: 1fr;
            gap: 10px;
        }
        
        .ihd-hr-stats-card {
            padding: 15px;
        }
        
        .ihd-hr-stats-card p {
            font-size: 18px;
        }
        
        .ihd-hr-form-grid {
            grid-template-columns: 1fr;
            gap: 10px;
        }
        
        .ihd-hr-table {
            font-size: 11px;
            min-width: 800px;
        }
        
        .ihd-hr-table th,
        .ihd-hr-table td {
            padding: 8px 6px;
            white-space: nowrap;
        }
        
        .ihd-hr-dashboard button {
            width: 100%;
            margin: 5px 0;
            padding: 10px 12px;
            font-size: 13px;
        }
        
        .ihd-hr-inline-form {
            flex-direction: column;
            gap: 10px;
        }
        
        .ihd-hr-dashboard label {
            display: block;
            margin: 5px 0;
            padding: 8px 10px;
        }
        
        .ihd-hr-dashboard input[type="checkbox"] {
            transform: scale(1.2);
            margin-right: 10px;
        }
    }

    @media (max-width: 480px) {
        .ihd-hr-dashboard {
            padding: 10px 8px;
            font-size: 12px;
        }
        
        .ihd-hr-dashboard h2 {
            font-size: 18px;
        }
        
        .ihd-hr-dashboard h3 {
            font-size: 15px;
        }
        
        .ihd-hr-section {
            padding: 12px;
        }
        
        .ihd-hr-stats-card {
            padding: 12px;
        }
        
        .ihd-hr-stats-card p {
            font-size: 16px;
        }
        
        .ihd-hr-table {
            font-size: 10px;
        }
        
        .ihd-hr-table th,
        .ihd-hr-table td {
            padding: 6px 4px;
        }
        
        code {
            font-size: 9px;
            padding: 1px 4px;
        }
    }

    /* Print Styles */
    @media print {
        .ihd-hr-dashboard button {
            display: none;
        }
        
        .ihd-hr-dashboard {
            background: white;
            padding: 0;
        }
        
        .ihd-hr-table {
            box-shadow: none;
            border: 1px solid #000;
        }
        
        .ihd-hr-tabs {
            display: none;
        }
        
        .ihd-hr-section {
            display: block !important;
            border: 1px solid #000;
            margin-bottom: 20px;
        }
    }

    /* Animations */
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .ihd-hr-section {
        animation: fadeIn 0.3s ease;
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
    .stats-card-success { border-left-color: #27ae60; }
    .stats-card-warning { border-left-color: #e67e22; }
    .stats-card-danger { border-left-color: #e74c3c; }

    .stats-card-primary p { color: #3498db; }
    .stats-card-success p { color: #27ae60; }
    .stats-card-warning p { color: #e67e22; }
    .stats-card-danger p { color: #e74c3c; }

    /* Form Validation */
    .ihd-hr-dashboard input:invalid {
        border-color: #e74c3c;
    }

    .ihd-hr-dashboard input:valid {
        border-color: #27ae60;
    }

    /* Loading States */
    .ihd-hr-dashboard button:disabled {
        background: #95a5a6;
        border-color: #7f8c8d;
        cursor: not-allowed;
    }

    .ihd-hr-dashboard button:disabled:hover {
        background: #95a5a6;
        border-color: #7f8c8d;
        transform: none;
    }

    /* Focus States for Accessibility */
    .ihd-hr-dashboard input:focus,
    .ihd-hr-dashboard select:focus,
    .ihd-hr-dashboard textarea:focus,
    .ihd-hr-dashboard button:focus {
        outline: 2px solid #3498db;
        outline-offset: 2px;
    }

    /* Checkbox Group Styling */
    .checkbox-group {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin: 10px 0;
        padding: 10px;
        background: #f8f9fa;
        border-radius: 4px;
        border: 1px solid #e9ecef;
    }

    .checkbox-group label {
        margin: 0;
        padding: 6px 10px;
        background: white;
        border: 1px solid #dee2e6;
        border-radius: 4px;
        transition: all 0.2s ease;
    }

    .checkbox-group label:hover {
        background: #e3f2fd;
        border-color: #3498db;
    }

    .checkbox-group input[type="checkbox"]:checked + span {
        color: #3498db;
        font-weight: 600;
    }

    /* Table Column Widths */
    .col-module { width: 200px; }
    .col-slug { width: 120px; }
    .col-trainers { width: 80px; }
    .col-students { width: 80px; }
    .col-actions { width: 300px; }

    .col-trainer-id { width: 100px; }
    .col-name { width: 150px; }
    .col-email { width: 180px; }
    .col-modules { width: 200px; }
    .col-students-count { width: 80px; }
    .col-trainer-actions { width: 250px; }
    </style>
    <div class="ihd-hr-dashboard">
       <br/><br/>
        
        <?php if (!$can_edit): ?>
            <div class="notice notice-info">
                <p>👀 You have read-only access to this dashboard.</p>
            </div>
        <?php endif; ?>

        <?php foreach ($messages as $m) echo $m; ?>

        <!-- Tabs -->
        <div class="ihd-hr-tabs" id="ihdHrTabs">
            <div class="ihd-hr-tab" data-target="modules">📚 Modules</div>
            <div class="ihd-hr-tab ihd-active" data-target="trainers">👨‍🏫 Trainers</div>
        </div>

        <!-- MODULES SECTION -->
        <div id="modules" class="ihd-hr-section">
            <h3>Manage Training Modules</h3>
            
            <?php if ($can_edit): ?>
            <div class="ihd-hr-stats-card">
                <h4>Add New Module</h4>
                <form method="post" class="ihd-hr-inline-form">
                    <?php wp_nonce_field('ihd_add_module_action','ihd_add_module_nonce'); ?>
                    <input type="text" name="module_name" placeholder="Enter module name (e.g. SAP FICO)" required style="flex: 1;">
                    <button type="submit" name="ihd_add_module">➕ Add Module</button>
                </form>
            </div>
            <?php endif; ?>

            <?php if (!empty($modules_terms) && !is_wp_error($modules_terms)) : ?>
                <div class="ihd-hr-table-container">
                    <table class="ihd-hr-table">
                        <thead>
                            <tr>
                                <th>Module Name</th>
                                <th>Slug</th>
                                <th>👨‍🏫 Trainers</th>
                                <th>👥 Students</th>
                                <?php if ($can_edit): ?><th>Actions</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($modules_terms as $mterm) : 
                                // Count students for this module
                                $module_students = new WP_Query(array(
                                    'post_type' => 'student',
                                    'tax_query' => array(
                                        array(
                                            'taxonomy' => 'module',
                                            'field' => 'term_id',
                                            'terms' => $mterm->term_id,
                                        )
                                    ),
                                    'posts_per_page' => -1,
                                    'fields' => 'ids'
                                ));
                                $student_count = $module_students->found_posts;
                                
                                // Count trainers assigned to this module
                                $trainer_count = 0;
                                foreach ($trainers as $t) {
                                    $assigned = get_user_meta($t->ID, 'assigned_modules', true) ?: array();
                                    if (in_array($mterm->term_id, (array)$assigned)) {
                                        $trainer_count++;
                                    }
                                }
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($mterm->name); ?></strong></td>
                                <td><code><?php echo esc_html($mterm->slug); ?></code></td>
                                <td>
                                    <span style="color:#3498db;font-weight:bold; font-size: 1.1em;"><?php echo intval($trainer_count); ?></span>
                                </td>
                                <td>
                                    <span style="color:#27ae60;font-weight:bold; font-size: 1.1em;"><?php echo intval($student_count); ?></span>
                                </td>
                                <?php if ($can_edit): ?>
                                <td>
                                    <div class="ihd-hr-inline-form">
                                        <form method="post" style="flex: 1;">
                                            <?php wp_nonce_field('ihd_edit_module_action','ihd_edit_module_nonce'); ?>
                                            <input type="hidden" name="module_id" value="<?php echo intval($mterm->term_id); ?>">
                                            <input type="text" name="module_name_edit" value="<?php echo esc_attr($mterm->name); ?>" style="margin-bottom: 2%;" required>
                                            <button type="submit" name="ihd_edit_module">💾 Save</button>
                                        </form>
                                        <?php if ($can_delete): ?>
                                        <form method="post" onsubmit="return confirm('Are you sure you want to delete module \"<?php echo esc_js($mterm->name); ?>\"? This action cannot be undone.');">
                                            <?php wp_nonce_field('ihd_delete_module_action','ihd_delete_module_nonce'); ?>
                                            <input type="hidden" name="module_id_delete" value="<?php echo intval($mterm->term_id); ?>">
                                            <button type="submit" name="ihd_delete_module" class="delete-btn">🗑️ Delete</button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 10%; background: white; border-radius: 12px; color: #7f8c8d;">
                    <p style="font-size: 1.2em; margin: 0;">No modules found. <?php if ($can_edit): ?>Add your first module above.<?php endif; ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- TRAINERS SECTION -->
        <div id="trainers" class="ihd-hr-section active">
            <?php if ($can_edit): ?>
            <h3>Add New Trainer</h3>
            <div class="ihd-hr-stats-card">
                <form method="post">
                    <?php wp_nonce_field('ihd_add_trainer_action','ihd_add_trainer_nonce'); ?>
                    <div class="ihd-hr-form-grid">
                        <input name="first_name" placeholder="First name" required>
                        <input name="last_name" placeholder="Last name" required>
                        <input name="email" type="email" placeholder="Email address" required>
                    </div>
                    <div class="ihd-hr-form-group">
                        <h4 style="margin-bottom: 2%; color: #2c3e50;">Assign Modules:</h4>
                        <div style="display: flex; flex-wrap: wrap; gap: 2%;">
                            <?php
                            if (!empty($modules_terms)) {
                                foreach ($modules_terms as $t) {
                                    echo '<label><input type="checkbox" name="modules[]" value="'.intval($t->term_id).'"> '.esc_html($t->name).'</label>';
                                }
                            } else {
                                echo '<p style="color: #e74c3c; font-style: italic;">No modules available. Please add modules first.</p>';
                            }
                            ?>
                        </div>
                    </div>
                    <button type="submit" name="ihd_add_trainer" style="width: 100%; margin: 3% 0 0 0; padding: 4%;">👨‍🏫 Create Trainer Account</button>
                </form>
            </div>
            <?php endif; ?>

            <h3>Existing Trainers (<?php echo count($trainers); ?>)</h3>
            
            <?php if (!empty($trainers)) : ?>
                <div class="ihd-hr-stats-grid">
                    <div class="ihd-hr-stats-card">
                        <h4>Total Trainers</h4>
                        <p><?php echo count($trainers); ?></p>
                    </div>
                    <div class="ihd-hr-stats-card" style="border-left-color: #27ae60;">
                        <h4>Active Trainers</h4>
                        <p style="color: #27ae60;"><?php echo count($trainers); ?></p>
                    </div>
                    <div class="ihd-hr-stats-card" style="border-left-color: #e67e22;">
                        <h4>Total Students</h4>
                        <p style="color: #e67e22;">
                            <?php
                            $total_students = 0;
                            foreach ($trainers as $t) {
                                $q = new WP_Query(array(
                                    'post_type'=>'student',
                                    'meta_query'=>array(
                                        array('key'=>'trainer_user_id','value'=>$t->ID,'compare'=>'=')
                                    ),
                                    'posts_per_page'=>-1,
                                    'fields'=>'ids'
                                ));
                                $total_students += $q->found_posts;
                            }
                            echo $total_students;
                            ?>
                        </p>
                    </div>
                </div>

                <div class="ihd-hr-table-container">
                    <table class="ihd-hr-table" style="text-align:center;">
                        <thead style="text-align:center;">
                            <tr style="text-align:center;">
                                <th>Trainer ID</th>
                                <th>Name</th>
                                <th  style="text-align:center;">Email</th>
                                <th>Assigned Modules</th>
                                <th style="text-align:center;">Active Students</th>
                                <?php if ($can_edit): ?><th style="text-align:center;">Actions</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trainers as $t) : 
                                $tid = get_user_meta($t->ID, 'trainer_unique_id', true) ?: 'N/A';
                                $mods = get_user_meta($t->ID, 'assigned_modules', true) ?: array();
                                $mod_names = array();
                                foreach ((array)$mods as $m) {
                                    $term = get_term($m, 'module');
                                    if ($term && !is_wp_error($term)) $mod_names[] = $term->name;
                                }
                                $q = new WP_Query(array(
                                    'post_type'=>'student',
                                    'meta_query'=>array(
                                        array('key'=>'trainer_user_id','value'=>$t->ID,'compare'=>'='),
                                        array('key'=>'status','value'=>'active','compare'=>'=')
                                    ),
                                    'posts_per_page'=>-1,
                                    'fields'=>'ids'
                                ));
                                $count = $q->found_posts;
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($tid); ?></strong></td>
                                <td><strong><?php echo esc_html($t->first_name . ' ' . $t->last_name); ?></strong></td>
                                <td><?php echo esc_html($t->user_email); ?></td>
                                <td>
                                    <?php if (!empty($mod_names)): ?>
                                        <span style="color: #2c3e50;"><?php echo esc_html(implode(', ', $mod_names)); ?></span>
                                    <?php else: ?>
                                        <span style="color: #bdc3c7; font-style: italic;">No modules assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="color: #e67e22; font-weight: bold; font-size: 1.1em;"><?php echo intval($count); ?></span>
                                </td>
                                <?php if ($can_edit): ?>
                                <td>
                                    <div style="display: flex; flex-direction: column; gap: 3%;">
                                        <!-- Edit Trainer Form -->
                                        <form method="post" class="ihd-hr-inline-form">
                                            <?php wp_nonce_field('ihd_edit_trainer_action','ihd_edit_trainer_nonce'); ?>
                                            <input type="hidden" name="trainer_user_id" value="<?php echo intval($t->ID); ?>">
                                            <div class="ihd-hr-form-grid" style="margin-bottom: 3%;">
                                                <input type="text" name="first_name_edit" value="<?php echo esc_attr($t->first_name); ?>" placeholder="First Name" required>
                                                <input type="text" name="last_name_edit" value="<?php echo esc_attr($t->last_name); ?>" placeholder="Last Name" required>
                                                <input type="email" name="email_edit" value="<?php echo esc_attr($t->user_email); ?>" placeholder="Email" required>
                                            </div>
                                            <div style="margin-bottom: 3%;">
                                                <label style="display: block; margin-bottom: 2%; font-weight: bold; color: #2c3e50;">Assigned Modules:</label>
                                                <div style="display: flex; flex-wrap: wrap; gap: 2%;">
                                                    <?php
                                                    foreach ($modules_terms as $mt) {
                                                        $checked = in_array($mt->term_id, (array)$mods) ? 'checked' : '';
                                                        echo '<label><input type="checkbox" name="modules_edit[]" value="'.intval($mt->term_id).'" '.$checked.'> '.esc_html($mt->name).'</label>';
                                                    }
                                                    ?>
                                                </div>
                                            </div>
                                            <button type="submit" name="ihd_edit_trainer" style="width: 100%;">💾 Save Changes</button>
                                        </form>
                                        
                                        <!-- Action Buttons -->
                                        <div class="ihd-hr-inline-form">
                                            <form method="post" onsubmit="return confirm('Reset password for <?php echo esc_js($t->first_name . ' ' . $t->last_name); ?>? They will receive an email with new credentials.');">
                                                <?php wp_nonce_field('ihd_reset_password_action','ihd_reset_password_nonce'); ?>
                                                <input type="hidden" name="trainer_user_id_reset" value="<?php echo intval($t->ID); ?>">
                                                <button type="submit" name="ihd_reset_password">🔑 Reset Password</button>
                                            </form>
                                            <?php if ($can_delete): ?>
                                            <form method="post" onsubmit="return confirm('Permanently delete trainer <?php echo esc_js($t->first_name . ' ' . $t->last_name); ?>? THIS ACTION CANNOT BE UNDONE!');">
                                                <?php wp_nonce_field('ihd_delete_trainer_action','ihd_delete_trainer_nonce'); ?>
                                                <input type="hidden" name="trainer_user_id_delete" value="<?php echo intval($t->ID); ?>">
                                                <button type="submit" name="ihd_delete_trainer" class="delete-btn">🗑️ Delete Trainer</button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 10%; background: white; border-radius: 12px; color: #7f8c8d;">
                    <p style="font-size: 1.2em; margin: 0;">No trainers found. <?php if ($can_edit): ?>Add your first trainer above.<?php endif; ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // HR Dashboard Tabs JavaScript
    document.querySelectorAll('.ihd-hr-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            // Remove active class from all tabs
            document.querySelectorAll('.ihd-hr-tab').forEach(t => t.classList.remove('ihd-active'));
            // Add active class to clicked tab
            tab.classList.add('ihd-active');

            // Hide all sections
            document.querySelectorAll('.ihd-hr-section').forEach(sec => sec.classList.remove('active'));
            // Show target section
            document.getElementById(tab.dataset.target).classList.add('active');
        });
    });

    // Add smooth scrolling for mobile
    document.querySelectorAll('.ihd-hr-tab').forEach(tab => {
        tab.addEventListener('click', function(e) {
            if (window.innerWidth < 768) {
                e.preventDefault();
                const targetSection = document.getElementById(this.dataset.target);
                targetSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

/* ---------------- Sales Dashboard Shortcode ---------------- */
function ihd_sales_dashboard_shortcode() {
    if (!is_user_logged_in()) return '<p style="text-align: center; padding: 20px; color: #e74c3c; font-size: 1.2em;">Access denied. Only Sale Manager can access this dashboard.</p>';
    
    $user = wp_get_current_user();
    if (!current_user_can('add_students') && !current_user_can('administrator')) {
        return '<p style="text-align: center; padding: 20px; color: #e74c3c; font-size: 1.2em;">Access denied. Only Sales team and Administrators can access this dashboard.</p>';
    }

    $messages = array();

    // Handle Add Student from Sales
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_sales_add_student'])) {
        if (!isset($_POST['ihd_sales_add_student_nonce']) || !wp_verify_nonce($_POST['ihd_sales_add_student_nonce'], 'ihd_sales_add_student_action')) {
            $messages[] = '<div class="error">Invalid request.</div>';
        } else {
            $s_name = sanitize_text_field($_POST['student_name'] ?? '');
            $phone = sanitize_text_field($_POST['phone'] ?? '');
            $timing = sanitize_text_field($_POST['timing'] ?? '');
            $schedule_type = sanitize_text_field($_POST['schedule_type'] ?? 'weekdays');
            $course = intval($_POST['course'] ?? 0);
            $start_date = sanitize_text_field($_POST['start_date'] ?? '');
            $training_mode = sanitize_text_field($_POST['training_mode'] ?? 'offline');
            $fees = floatval($_POST['fees'] ?? 0);
            $fees_paid = floatval($_POST['fees_paid'] ?? 0);

            if (!$s_name || !$course || !$start_date) {
                $messages[] = '<div class="error">Please fill all required fields.</div>';
            } else {
                $student_id = wp_insert_post(array(
                    'post_type' => 'student',
                    'post_title' => $s_name,
                    'post_status' => 'publish',
                ));

                if (!is_wp_error($student_id)) {
                    // Set as unassigned student
                    update_post_meta($student_id, 'trainer_user_id', 0);
                    update_post_meta($student_id, 'phone', $phone);
                    update_post_meta($student_id, 'timing', $timing);
                    update_post_meta($student_id, 'schedule_type', $schedule_type);
                    update_post_meta($student_id, 'course_id', $course);
                    update_post_meta($student_id, 'start_date', $start_date);
                    update_post_meta($student_id, 'training_mode', $training_mode);
                    update_post_meta($student_id, 'status', 'unassigned');
                    update_post_meta($student_id, 'fee_status', 'pending');
                    update_post_meta($student_id, 'total_fees', $fees);
                    update_post_meta($student_id, 'fees_paid', $fees_paid);
                    update_post_meta($student_id, 'completion', 0);
                    update_post_meta($student_id, 'added_by_sales', $user->ID);
                    update_post_meta($student_id, 'added_date', date('Y-m-d H:i:s'));
                    
                    // Assign module taxonomy
                    wp_set_object_terms($student_id, array($course), 'module');
                    
                    $messages[] = '<div class="updated">Student added successfully and sent to Finance for processing.</div>';
                } else {
                    $messages[] = '<div class="error">Error adding student.</div>';
                }
            }
        }
    }

    // Fetch Modules for dropdown
    $modules_terms = get_terms(array('taxonomy' => 'module', 'hide_empty' => false));

    ob_start();
    ?>
    
    <style>
    /* Sales Dashboard Responsive Styles */
    .ihd-sales-dashboard {
        max-width: 100%;
        margin: 0 auto;
        padding: 2%;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: #f8f9fa;
        min-height: 100vh;
        box-sizing: border-box;
    }
    
    .ihd-sales-dashboard h2 {
        text-align: center;
        margin-bottom: 3%;
        color: #2c3e50;
        font-size: clamp(1.5rem, 4vw, 2.5rem);
        font-weight: 700;
    }
    
    .ihd-sales-dashboard h3 {
        margin: 4% 0 2% 0;
        color: #34495e;
        font-size: clamp(1.2rem, 3vw, 1.8rem);
        font-weight: 600;
        border-bottom: 2px solid #3498db;
        padding-bottom: 1%;
    }
    
    /* Messages */
    .ihd-sales-dashboard .error {
        background: #f8d7da;
        border: 1px solid #f5c6cb;
        border-radius: 8px;
        padding: 3%;
        margin-bottom: 3%;
        color: #721c24;
        font-size: clamp(0.9rem, 2.5vw, 1rem);
    }
    
    .ihd-sales-dashboard .updated {
        background: #d4edda;
        border: 1px solid #c3e6cb;
        border-radius: 8px;
        padding: 3%;
        margin-bottom: 3%;
        color: #155724;
        font-size: clamp(0.9rem, 2.5vw, 1rem);
    }
    
    /* Forms */
    .ihd-sales-dashboard input[type="text"],
    .ihd-sales-dashboard input[type="tel"],
    .ihd-sales-dashboard input[type="date"],
    .ihd-sales-dashboard input[type="number"],
    .ihd-sales-dashboard select {
        padding: 3%;
        border: 2px solid #ecf0f1;
        border-radius: 8px;
        width: 100%;
        box-sizing: border-box;
        font-size: clamp(0.9rem, 2.5vw, 1rem);
        transition: all 0.3s ease;
        background: #fff;
    }
    
    .ihd-sales-dashboard input:focus,
    .ihd-sales-dashboard select:focus {
        border-color: #3498db;
        outline: none;
        box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        transform: translateY(-2px);
    }
    
    .ihd-sales-dashboard button {
        background: linear-gradient(135deg, #3498db, #2980b9);
        color: #fff;
        border: none;
        padding: 3% 6%;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s ease;
        font-size: clamp(0.9rem, 2.5vw, 1rem);
        font-weight: 600;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
    
    .ihd-sales-dashboard button:hover {
        background: linear-gradient(135deg, #2980b9, #3498db);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    }
    
    /* Add Student Form */
    .ihd-sales-add-form {
        background: white;
        padding: 4%;
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        margin-bottom: 4%;
        border-left: 4px solid #3498db;
    }
    
    .ihd-sales-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 3%;
        margin-bottom: 3%;
    }
    
    .ihd-sales-form-group {
        margin-bottom: 4%;
    }
    
    .ihd-sales-form-group label {
        display: block;
        margin-bottom: 2%;
        font-weight: 600;
        color: #2c3e50;
        font-size: clamp(0.9rem, 2.5vw, 1rem);
    }
    
    /* Mobile-specific styles */
    @media (max-width: 768px) {
        .ihd-sales-dashboard {
            padding: 4%;
        }
        
        .ihd-sales-add-form {
            padding: 6%;
        }
        
        .ihd-sales-form-grid {
            grid-template-columns: 1fr;
            gap: 3%;
        }
        
        .ihd-sales-dashboard button {
            width: 100%;
            margin: 2% 0;
            padding: 4% 6%;
        }
    }
    
    @media (max-width: 480px) {
        .ihd-sales-dashboard {
            padding: 5%;
        }
        
        .ihd-sales-add-form {
            padding: 8%;
        }
    }
    
    /* Tablet-specific styles */
    @media (min-width: 769px) and (max-width: 1024px) {
        .ihd-sales-dashboard {
            padding: 3%;
        }
        
        .ihd-sales-form-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    
    /* Desktop enhancements */
    @media (min-width: 1025px) {
        .ihd-sales-dashboard {
            max-width: 1200px;
            padding: 2%;
        }
        
        .ihd-sales-form-grid {
            grid-template-columns: repeat(3, 1fr);
        }
    }
    </style>

    <div class="ihd-sales-dashboard">
        <h2>💰 Sales Dashboard</h2>
        <?php foreach ($messages as $m) echo $m; ?>

        <div class="ihd-sales-add-form">
            <h3>➕ Add New Student</h3>
            <form method="post">
                <?php wp_nonce_field('ihd_sales_add_student_action','ihd_sales_add_student_nonce'); ?>
                
                <div class="ihd-sales-form-grid">
                    <div class="ihd-sales-form-group">
                        <label>Student Name *</label>
                        <input type="text" name="student_name" placeholder="Enter student name" required>
                    </div>
                    
                    <div class="ihd-sales-form-group">
                        <label>Phone Number *</label>
                        <input type="tel" name="phone" placeholder="Enter phone number" required>
                    </div>
                    
                    <div class="ihd-sales-form-group">
                        <label>Course *</label>
                        <select name="course" required>
                            <option value="">Select Course</option>
                            <?php 
                            if (!empty($modules_terms)) {
                                foreach ($modules_terms as $module) {
                                    echo '<option value="' . intval($module->term_id) . '">' . esc_html($module->name) . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="ihd-sales-form-group">
                        <label>Timing *</label>
                        <input type="text" name="timing" placeholder="e.g., 7-8AM, 2-3PM, 7-8PM" required>
                    </div>
                    
                    <div class="ihd-sales-form-group">
                        <label>Schedule Type *</label>
                        <select name="schedule_type" required>
                            <option value="weekdays">📅 Weekdays (Mon-Fri)</option>
                            <option value="weekends">🏖️ Weekends (Sat-Sun)</option>
                        </select>
                    </div>
                    
                    <div class="ihd-sales-form-group">
                        <label>Training Mode *</label>
                        <select name="training_mode" required>
                            <option value="offline">🏢 Offline</option>
                            <option value="online">💻 Online</option>
                        </select>
                    </div>
                    
                    <div class="ihd-sales-form-group">
                        <label>Start Date *</label>
                        <input type="date" name="start_date" required>
                    </div>
                    
                    <div class="ihd-sales-form-group">
                        <label>Total Fees (₹)</label>
                        <input type="number" name="fees" placeholder="Total course fees" min="0" step="0.01">
                    </div>
                    
                    <div class="ihd-sales-form-group">
                        <label>Fees Paid (₹)</label>
                        <input type="number" name="fees_paid" placeholder="Fees paid so far" min="0" step="0.01">
                    </div>
                </div>
                
                <button type="submit" name="ihd_sales_add_student" style="width: 100%; margin-top: 3%; padding: 4%;">
                    👥 Add Student & Send to Finance
                </button>
            </form>
        </div>
        
        <div style="background: #e8f4fc; padding: 4%; border-radius: 12px; border-left: 4px solid #3498db;">
            <h4>📋 Process Flow</h4>
            <ol style="color: #2c3e50; line-height: 1.6;">
                <li><strong>Sales Team:</strong> Add student details using this form</li>
                <li><strong>Finance Team:</strong> Review and forward students to Manager</li>
                <li><strong>Manager:</strong> Assign students to appropriate trainers</li>
                <li><strong>Trainer:</strong> Manage student progress and completion</li>
            </ol>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-focus on first input
        const firstInput = document.querySelector('input[name="student_name"]');
        if (firstInput) {
            firstInput.focus();
        }
        
        // Set default date to today
        const today = new Date().toISOString().split('T')[0];
        const dateInput = document.querySelector('input[name="start_date"]');
        if (dateInput && !dateInput.value) {
            dateInput.value = today;
        }
    });
    </script>
    <?php
    return ob_get_clean();
}
/* ---------------- UPDATED Finance Dashboard Shortcode ---------------- */
function ihd_finance_dashboard_shortcode() {
    if (!is_user_logged_in()) return '<p style="text-align: center; padding: 20px; color: #e74c3c; font-size: 1.2em;">Access denied. Only Finance Manager can access this dashboard.</p>';
    
    $user = wp_get_current_user();
    if (!current_user_can('manage_finance') && !current_user_can('manage_fees')) return 'Access denied.';

    $messages = array();

    // Check if user has fee management capabilities
    $can_manage_fees = current_user_can('manage_fees') || current_user_can('administrator');

    // ---------------- Generate Certificate
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_generate_certificate'])) {
        if (!isset($_POST['ihd_generate_certificate_nonce']) || !wp_verify_nonce($_POST['ihd_generate_certificate_nonce'], 'ihd_generate_certificate_action')) {
            $messages[] = '<div class="error">Invalid request (generate certificate).</div>';
        } else {
            $student_id = intval($_POST['student_id']);
            if ($student_id && get_post_meta($student_id, 'status', true) === 'completed') {
                $cert_id = 'CERT-' . strtoupper(wp_generate_password(8, false, false));
                update_post_meta($student_id, 'certificate_id', $cert_id);
                $messages[] = '<div class="updated">Certificate generated: <strong>' . esc_html($cert_id) . '</strong></div>';
            } else {
                $messages[] = '<div class="error">Invalid student or not completed yet.</div>';
            }
        }
    }

    // ---------------- Update Fee Status and Amount
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_update_fee_status'])) {
        if (!$can_manage_fees) {
            $messages[] = '<div class="error">You do not have permission to update fee status.</div>';
        } elseif (!isset($_POST['ihd_update_fee_status_nonce']) || !wp_verify_nonce($_POST['ihd_update_fee_status_nonce'], 'ihd_update_fee_status_action')) {
            $messages[] = '<div class="error">Invalid request (update fee status).</div>';
        } else {
            $student_id = intval($_POST['student_id']);
            $fee_status = sanitize_text_field($_POST['fee_status'] ?? 'pending');
            $total_fees = floatval($_POST['total_fees'] ?? 0);
            $fees_paid = floatval($_POST['fees_paid'] ?? 0);
            
            if ($student_id && in_array($fee_status, array('paid', 'pending', 'hold'))) {
                update_post_meta($student_id, 'fee_status', $fee_status);
                update_post_meta($student_id, 'total_fees', $total_fees);
                update_post_meta($student_id, 'fees_paid', $fees_paid);
                
                // Auto-update fee status to paid if paid amount equals or exceeds total
                if ($fees_paid >= $total_fees && $total_fees > 0) {
                    update_post_meta($student_id, 'fee_status', 'paid');
                    $fee_status = 'paid';
                }
                
                $messages[] = '<div class="updated">Fee details updated successfully. Status: ' . esc_html(ucfirst($fee_status)) . '</div>';
            } else {
                $messages[] = '<div class="error">Invalid student or fee status.</div>';
            }
        }
    }

    // ---------------- Forward to Manager
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_forward_to_manager'])) {
        if (!$can_manage_fees) {
            $messages[] = '<div class="error">You do not have permission to forward students.</div>';
        } elseif (!isset($_POST['ihd_forward_to_manager_nonce']) || !wp_verify_nonce($_POST['ihd_forward_to_manager_nonce'], 'ihd_forward_to_manager_action')) {
            $messages[] = '<div class="error">Invalid request (forward to manager).</div>';
        } else {
            $student_id = intval($_POST['student_id']);
            
            if ($student_id) {
                update_post_meta($student_id, 'status', 'forwarded');
                update_post_meta($student_id, 'forwarded_date', date('Y-m-d H:i:s'));
                update_post_meta($student_id, 'forwarded_by', $user->ID);
                $messages[] = '<div class="updated">Student forwarded to Manager successfully.</div>';
            } else {
                $messages[] = '<div class="error">Invalid student.</div>';
            }
        }
    }

    // ---------------- Return to Finance (Manager sends student back)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_return_to_finance'])) {
        if (!current_user_can('view_manager') && !current_user_can('administrator')) {
            $messages[] = '<div class="error">You do not have permission to return students to finance.</div>';
        } elseif (!isset($_POST['ihd_return_to_finance_nonce']) || !wp_verify_nonce($_POST['ihd_return_to_finance_nonce'], 'ihd_return_to_finance_action')) {
            $messages[] = '<div class="error">Invalid request (return to finance).</div>';
        } else {
            $student_id = intval($_POST['student_id']);
            $return_reason = sanitize_text_field($_POST['return_reason'] ?? 'No reason provided');
            
            if ($student_id) {
                update_post_meta($student_id, 'status', 'unassigned');
                update_post_meta($student_id, 'returned_to_finance_date', date('Y-m-d H:i:s'));
                update_post_meta($student_id, 'returned_by', $user->ID);
                update_post_meta($student_id, 'return_reason', $return_reason);
                $messages[] = '<div class="updated">Student returned to Finance. Reason: ' . esc_html($return_reason) . '</div>';
            } else {
                $messages[] = '<div class="error">Invalid student.</div>';
            }
        }
    }

    // ---------------- Delete Unassigned Student
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_delete_unassigned_student'])) {
        if (!$can_manage_fees) {
            $messages[] = '<div class="error">You do not have permission to delete students.</div>';
        } elseif (!isset($_POST['ihd_delete_unassigned_student_nonce']) || !wp_verify_nonce($_POST['ihd_delete_unassigned_student_nonce'], 'ihd_delete_unassigned_student_action')) {
            $messages[] = '<div class="error">Invalid request (delete student).</div>';
        } else {
            $student_id = intval($_POST['student_id']);
            
            if ($student_id) {
                $status = get_post_meta($student_id, 'status', true);
                if ($status === 'completed') {
                    $messages[] = '<div class="error">Cannot delete completed student (certificate preserved).</div>';
                } else {
                    $deleted = wp_delete_post($student_id, true);
                    if ($deleted) {
                        $messages[] = '<div class="updated">Student deleted successfully.</div>';
                    } else {
                        $messages[] = '<div class="error">Failed to delete student.</div>';
                    }
                }
            } else {
                $messages[] = '<div class="error">Invalid student.</div>';
            }
        }
    }

    // ---------------- Edit Student Details (Manager)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_edit_student_details'])) {
        if (!current_user_can('view_manager') && !current_user_can('administrator')) {
            $messages[] = '<div class="error">You do not have permission to edit student details.</div>';
        } elseif (!isset($_POST['ihd_edit_student_details_nonce']) || !wp_verify_nonce($_POST['ihd_edit_student_details_nonce'], 'ihd_edit_student_details_action')) {
            $messages[] = '<div class="error">Invalid request (edit student details).</div>';
        } else {
            $student_id = intval($_POST['student_id']);
            $student_name = sanitize_text_field($_POST['student_name'] ?? '');
            $phone = sanitize_text_field($_POST['phone'] ?? '');
            $timing = sanitize_text_field($_POST['timing'] ?? '');
            $schedule_type = sanitize_text_field($_POST['schedule_type'] ?? 'weekdays');
            $training_mode = sanitize_text_field($_POST['training_mode'] ?? 'offline');
            $start_date = sanitize_text_field($_POST['start_date'] ?? '');
            
            if ($student_id && $student_name) {
                // Update post title
                wp_update_post(array(
                    'ID' => $student_id,
                    'post_title' => $student_name
                ));
                
                // Update meta fields
                update_post_meta($student_id, 'phone', $phone);
                update_post_meta($student_id, 'timing', $timing);
                update_post_meta($student_id, 'schedule_type', $schedule_type);
                update_post_meta($student_id, 'training_mode', $training_mode);
                update_post_meta($student_id, 'start_date', $start_date);
                
                $messages[] = '<div class="updated">Student details updated successfully.</div>';
            } else {
                $messages[] = '<div class="error">Invalid student data.</div>';
            }
        }
    }

    // Get all students for initial display
    $all_students = get_posts(array(
        'post_type' => 'student',
        'posts_per_page' => -1,
        'meta_query' => array(
            'relation' => 'AND'
        )
    ));

    // Process search if submitted
    $search = sanitize_text_field($_POST['finance_search'] ?? '');
    $search_type = sanitize_text_field($_POST['search_type'] ?? 'name');
    $status_filter = sanitize_text_field($_POST['status'] ?? 'all');

    $students = $all_students;

    // Apply filters if search is performed
    if (!empty($search)) {
        $filtered_students = array();
        
        foreach ($all_students as $student) {
            $matches = false;
            
            // Apply status filter
            if ($status_filter !== 'all') {
                $student_status = get_post_meta($student->ID, 'status', true) ?: 'active';
                if ($student_status !== $status_filter) {
                    continue;
                }
            }
            
            // Apply search filter
            if ($search_type === 'phone') {
                $phone = get_post_meta($student->ID, 'phone', true) ?: '';
                if (stripos($phone, $search) !== false) {
                    $matches = true;
                }
            } else {
                // Search by name
                if (stripos($student->post_title, $search) !== false) {
                    $matches = true;
                }
            }
            
            if ($matches) {
                $filtered_students[] = $student;
            }
        }
        
        $students = $filtered_students;
    } elseif ($status_filter !== 'all') {
        // Only status filter applied
        $filtered_students = array();
        foreach ($all_students as $student) {
            $student_status = get_post_meta($student->ID, 'status', true) ?: 'active';
            if ($student_status === $status_filter) {
                $filtered_students[] = $student;
            }
        }
        $students = $filtered_students;
    }

    $completed_students = array_filter($students, function($s) {
        return get_post_meta($s->ID, 'status', true) === 'completed';
    });

    // Get unassigned students (status = unassigned or returned from manager)
    $unassigned_students = array_filter($students, function($s) {
        $status = get_post_meta($s->ID, 'status', true);
        return $status === 'unassigned' || $status === 'forwarded' || get_post_meta($s->ID, 'returned_to_finance_date', true);
    });

    // Sort students: Hold first, then Pending, then Paid (with oldest pending students first)
    usort($students, function($a, $b) {
        $fee_status_a = get_post_meta($a->ID, 'fee_status', true) ?: 'pending';
        $fee_status_b = get_post_meta($b->ID, 'fee_status', true) ?: 'pending';
        
        $start_date_a = get_post_meta($a->ID, 'start_date', true) ?: '';
        $start_date_b = get_post_meta($b->ID, 'start_date', true) ?: '';
        
        // Define priority order: hold > pending > paid
        $priority = array('hold' => 1, 'pending' => 2, 'paid' => 3);
        
        if ($priority[$fee_status_a] !== $priority[$fee_status_b]) {
            return $priority[$fee_status_a] - $priority[$fee_status_b];
        }
        
        // If same fee status, sort by start date (oldest first for pending/hold)
        if ($fee_status_a === 'pending' || $fee_status_a === 'hold') {
            return strtotime($start_date_a) - strtotime($start_date_b);
        }
        
        // For paid students, keep original order or sort by name
        return strcasecmp($a->post_title, $b->post_title);
    });

    // Separate students by fee status for organized display
    $hold_students = array_filter($students, function($s) {
        return get_post_meta($s->ID, 'fee_status', true) === 'hold';
    });
    
    $pending_students = array_filter($students, function($s) {
        return get_post_meta($s->ID, 'fee_status', true) === 'pending';
    });
    
    $paid_students = array_filter($students, function($s) {
        return get_post_meta($s->ID, 'fee_status', true) === 'paid';
    });

    ob_start(); ?>
    
    <style>
    /* Finance Dashboard Traditional Responsive Styles */
    .ihd-finance-dashboard {
        max-width: 100%;
        margin: 0 auto;
        padding: 20px 15px;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: #f5f5f5;
        min-height: 100vh;
        box-sizing: border-box;
        font-size: 14px;
    }

    .ihd-finance-dashboard h2 {
        text-align: center;
        margin-bottom: 25px;
        color: #2c3e50;
        font-size: 24px;
        font-weight: 600;
        padding-bottom: 15px;
        border-bottom: 2px solid #3498db;
    }

    .ihd-finance-dashboard h3 {
        margin: 25px 0 15px 0;
        color: #34495e;
        font-size: 18px;
        font-weight: 600;
        padding-bottom: 10px;
        border-bottom: 1px solid #bdc3c7;
    }

    /* Messages */
    .ihd-finance-dashboard .error {
        background: #ffe6e6;
        border: 1px solid #ffcccc;
        border-radius: 4px;
        padding: 12px 15px;
        margin-bottom: 15px;
        color: #cc0000;
        font-size: 13px;
        border-left: 4px solid #ff3333;
    }

    .ihd-finance-dashboard .updated {
        background: #e6ffe6;
        border: 1px solid #ccffcc;
        border-radius: 4px;
        padding: 12px 15px;
        margin-bottom: 15px;
        color: #006600;
        font-size: 13px;
        border-left: 4px solid #33cc33;
    }

    /* Search Results Header */
    .ihd-finance-search-results {
        background: #2c3e50;
        color: white;
        padding: 15px 20px;
        border-radius: 6px;
        margin-bottom: 20px;
        text-align: center;
        border: 1px solid #34495e;
    }

    .ihd-finance-search-results h4 {
        margin: 0 0 8px 0;
        font-size: 16px;
        font-weight: 600;
    }

    .ihd-finance-search-results .search-terms {
        margin-top: 5px;
        font-size: 13px;
        opacity: 0.9;
    }

    .ihd-finance-search-results .reset-search {
        display: inline-block;
        margin-top: 10px;
        padding: 6px 15px;
        background: rgba(255,255,255,0.15);
        color: white;
        text-decoration: none;
        border-radius: 4px;
        font-size: 12px;
        transition: all 0.2s ease;
        border: 1px solid rgba(255,255,255,0.2);
    }

    .ihd-finance-search-results .reset-search:hover {
        background: rgba(255,255,255,0.25);
    }

    /* Tabs */
    .ihd-finance-tabs {
        display: flex;
        margin-bottom: 20px;
        border-radius: 6px 6px 0 0;
        overflow: hidden;
        background: #fff;
        border: 1px solid #ddd;
        border-bottom: none;
    }

    .ihd-finance-tab {
        flex: 1;
        padding: 12px 15px;
        cursor: pointer;
        text-align: center;
        background: #ecf0f1;
        transition: all 0.2s ease;
        border: none;
        font-weight: 500;
        color: #7f8c8d;
        font-size: 13px;
        border-right: 1px solid #ddd;
    }

    .ihd-finance-tab:last-child {
        border-right: none;
    }

    .ihd-finance-tab.ihd-active {
        background: #3498db;
        color: #fff;
    }

    .ihd-finance-tab:hover:not(.ihd-active) {
        background: #d5dbdb;
    }

    /* Sections */
    .ihd-finance-section {
        display: none;
        background: #fff;
        padding: 20px;
        border-radius: 0 0 6px 6px;
        border: 1px solid #ddd;
        border-top: none;
    }

    .ihd-finance-section.active {
        display: block;
    }

    /* Search and Filter */
    .ihd-finance-search-form {
        display: grid;
        grid-template-columns: 2fr 1fr 1fr auto auto;
        gap: 10px;
        margin-bottom: 20px;
        padding: 15px;
        background: white;
        border-radius: 6px;
        border: 1px solid #ddd;
        align-items: end;
    }

    .ihd-finance-search-group {
        display: flex;
        gap: 10px;
    }

    .ihd-finance-search-group input {
        flex: 1;
        padding: 8px 12px;
        border: 1px solid #ccc;
        border-radius: 4px;
        font-size: 13px;
        transition: all 0.2s ease;
        height: 38px;
        box-sizing: border-box;
    }

    .ihd-finance-search-group input:focus {
        border-color: #3498db;
        outline: none;
        box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.1);
    }

    .ihd-finance-search-group select {
        width: 140px;
        padding: 8px 12px;
        border: 1px solid #ccc;
        border-radius: 4px;
        font-size: 13px;
        background: white;
        height: 38px;
        box-sizing: border-box;
    }

    .ihd-finance-dashboard select {
        padding: 8px 12px;
        border: 1px solid #ccc;
        border-radius: 4px;
        font-size: 13px;
        background: white;
        width: 100%;
        height: 38px;
        box-sizing: border-box;
    }

    /* Buttons */
    .ihd-finance-dashboard button,
    .ihd-finance-dashboard .button {
        background: #3498db;
        color: #fff;
        border: 1px solid #2980b9;
        padding: 8px 16px;
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.2s ease;
        font-size: 13px;
        font-weight: 500;
        text-decoration: none;
        display: inline-block;
        text-align: center;
        height: 38px;
        box-sizing: border-box;
    }

    .ihd-finance-dashboard button:hover,
    .ihd-finance-dashboard .button:hover {
        background: #2980b9;
        border-color: #2471a3;
    }

    .ihd-finance-dashboard button.forward-btn {
        background: #e67e22;
        border-color: #d35400;
    }

    .ihd-finance-dashboard button.forward-btn:hover {
        background: #d35400;
        border-color: #ba4a00;
    }

    .ihd-finance-dashboard button.return-btn {
        background: #9b59b6;
        border-color: #8e44ad;
    }

    .ihd-finance-dashboard button.return-btn:hover {
        background: #8e44ad;
        border-color: #7d3c98;
    }

    .ihd-finance-dashboard button.delete-btn {
        background: #e74c3c;
        border-color: #c0392b;
    }

    .ihd-finance-dashboard button.delete-btn:hover {
        background: #c0392b;
        border-color: #a93226;
    }

    /* Student Sections */
    .ihd-finance-student-section {
        margin-bottom: 25px;
        background: #fff;
        padding: 20px;
        border-radius: 6px;
        border: 1px solid #ddd;
        border-left: 4px solid;
    }

    .ihd-finance-section-title {
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 2px solid;
        font-weight: 600;
        font-size: 16px;
        color: #2c3e50;
    }

    .ihd-finance-unassigned-section {
        border-left-color: #9b59b6;
    }

    .ihd-finance-unassigned-title {
        color: #9b59b6;
        border-bottom-color: #9b59b6;
    }

    .ihd-finance-hold-section {
        border-left-color: #e74c3c;
    }

    .ihd-finance-hold-title {
        color: #e74c3c;
        border-bottom-color: #e74c3c;
    }

    .ihd-finance-pending-section {
        border-left-color: #e67e22;
    }

    .ihd-finance-pending-title {
        color: #e67e22;
        border-bottom-color: #e67e22;
    }

    .ihd-finance-paid-section {
        border-left-color: #27ae60;
    }

    .ihd-finance-paid-title {
        color: #27ae60;
        border-bottom-color: #27ae60;
    }

    /* Traditional Tables */
    .ihd-finance-table {
        width: 100%;
        border-collapse: collapse;
        margin: 15px 0;
        background: #fff;
        border: 1px solid #ddd;
        font-size: 12px;
        table-layout: fixed;
    }

    .ihd-finance-table thead {
        background: #2c3e50;
    }

    .ihd-finance-table th {
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

    .ihd-finance-table th:last-child {
        border-right: none;
    }

    .ihd-finance-table td {
        padding: 8px;
        border-bottom: 1px solid #ecf0f1;
        vertical-align: top;
        border-right: 1px solid #ecf0f1;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }

    .ihd-finance-table td:last-child {
        border-right: none;
    }

    .ihd-finance-table tbody tr:hover {
        background: #f8f9fa;
    }

    .ihd-finance-table tbody tr:nth-child(even) {
        background: #fafbfc;
    }

    /* Status Badges */
    .ihd-finance-status-badge {
        padding: 4px 8px;
        border-radius: 3px;
        font-size: 11px;
        font-weight: 600;
        display: inline-block;
        text-align: center;
        min-width: 60px;
        text-transform: uppercase;
    }

    .status-unassigned {
        background: #9b59b6;
        color: white;
    }

    .status-forwarded {
        background: #e67e22;
        color: white;
    }

    .status-returned {
        background: #9b59b6;
        color: white;
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

    .status-active {
        background: #3498db;
        color: white;
    }

    .status-completed {
        background: #9b59b6;
        color: white;
    }

    /* Training Mode Badges */
    .ihd-finance-mode-badge {
        padding: 3px 6px;
        border-radius: 3px;
        font-size: 10px;
        font-weight: 600;
        display: inline-block;
        text-align: center;
    }

    .mode-online {
        background: #3498db;
        color: white;
    }

    .mode-offline {
        background: #7f8c8d;
        color: white;
    }

    /* Forms */
    .ihd-finance-fee-form {
        display: flex;
        gap: 5px;
        align-items: center;
        flex-wrap: wrap;
    }

    .ihd-finance-fee-form select,
    .ihd-finance-fee-form input {
        flex: 1;
        min-width: 60px;
        padding: 4px 6px;
        border: 1px solid #ddd;
        border-radius: 3px;
        font-size: 11px;
        height: 28px;
        box-sizing: border-box;
    }

    .ihd-finance-fee-form button {
        white-space: nowrap;
        padding: 4px 8px;
        font-size: 11px;
        height: 28px;
    }

    .ihd-finance-action-form {
        display: flex;
        gap: 5px;
        align-items: center;
        flex-wrap: wrap;
    }

    .ihd-finance-edit-form {
        background: #f8f9fa;
        padding: 10px;
        border-radius: 4px;
        margin-top: 8px;
        border: 1px solid #e9ecef;
    }

    .ihd-finance-edit-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 8px;
        margin-bottom: 8px;
    }

    .ihd-finance-certificate-form {
        display: flex;
        justify-content: center;
    }

    .ihd-finance-certificate-generated {
        color: #27ae60;
        font-weight: bold;
        text-align: center;
        display: block;
        font-size: 11px;
    }

    /* No Results Message */
    .ihd-finance-no-results {
        text-align: center;
        padding: 40px 20px;
        background: white;
        border-radius: 6px;
        color: #7f8c8d;
        border: 1px solid #ddd;
    }

    .ihd-finance-no-results p {
        font-size: 14px;
        margin: 0;
    }

    /* Table Container */
    .ihd-finance-table-container {
        overflow-x: auto;
        border-radius: 6px;
        margin: 15px 0;
        -webkit-overflow-scrolling: touch;
        border: 1px solid #ddd;
    }

    /* Return Reason */
    .return-reason {
        color: #e67e22;
        font-size: 10px;
        font-style: italic;
        margin-top: 3px;
        display: block;
    }

    /* Progress Bar */
    .ihd-progress-bar {
        width: 60px;
        height: 6px;
        background: #ecf0f1;
        border-radius: 3px;
        overflow: hidden;
        display: inline-block;
        margin-left: 5px;
    }

    .ihd-progress-fill {
        height: 100%;
        background: #3498db;
        border-radius: 3px;
        transition: width 0.3s ease;
    }

    /* Mobile-specific styles */
    @media (max-width: 1024px) {
        .ihd-finance-table {
            font-size: 11px;
        }
        
        .ihd-finance-table th,
        .ihd-finance-table td {
            padding: 6px 4px;
        }
    }

    @media (max-width: 768px) {
        .ihd-finance-dashboard {
            padding: 15px 10px;
            font-size: 13px;
        }
        
        .ihd-finance-dashboard h2 {
            font-size: 20px;
            margin-bottom: 20px;
        }
        
        .ihd-finance-dashboard h3 {
            font-size: 16px;
            margin: 20px 0 12px 0;
        }
        
        .ihd-finance-tabs {
            flex-direction: column;
        }
        
        .ihd-finance-tab {
            flex: none;
            padding: 12px 15px;
            font-size: 13px;
            border-right: none;
            border-bottom: 1px solid #ddd;
        }
        
        .ihd-finance-tab:last-child {
            border-bottom: none;
        }
        
        .ihd-finance-section {
            padding: 15px;
        }
        
        .ihd-finance-search-form {
            grid-template-columns: 1fr;
            gap: 10px;
        }
        
        .ihd-finance-search-group {
            flex-direction: column;
            gap: 8px;
        }
        
        .ihd-finance-search-group select {
            width: 100%;
        }
        
        .ihd-finance-student-section {
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .ihd-finance-table {
            font-size: 11px;
        }
        
        .ihd-finance-table th,
        .ihd-finance-table td {
            padding: 8px 6px;
            white-space: nowrap;
        }
        
        .ihd-finance-dashboard button {
            width: 100%;
            margin: 5px 0;
            padding: 10px 12px;
            font-size: 13px;
        }
        
        .ihd-finance-fee-form,
        .ihd-finance-action-form {
            flex-direction: column;
            gap: 5px;
        }
        
        .ihd-finance-fee-form select,
        .ihd-finance-fee-form input,
        .ihd-finance-fee-form button,
        .ihd-finance-action-form button {
            width: 100%;
        }
        
        .ihd-finance-edit-grid {
            grid-template-columns: 1fr;
        }
        
        /* Mobile table improvements */
        .ihd-finance-table-container {
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .ihd-finance-table {
            min-width: 800px; /* Force horizontal scroll on mobile */
        }
    }

    @media (max-width: 480px) {
        .ihd-finance-dashboard {
            padding: 10px 8px;
            font-size: 12px;
        }
        
        .ihd-finance-dashboard h2 {
            font-size: 18px;
        }
        
        .ihd-finance-dashboard h3 {
            font-size: 15px;
        }
        
        .ihd-finance-section {
            padding: 12px;
        }
        
        .ihd-finance-student-section {
            padding: 12px;
        }
        
        .ihd-finance-table {
            font-size: 10px;
        }
        
        .ihd-finance-table th,
        .ihd-finance-table td {
            padding: 6px 4px;
        }
        
        .ihd-finance-status-badge {
            font-size: 10px;
            padding: 3px 6px;
            min-width: 50px;
        }
        
        .ihd-finance-mode-badge {
            font-size: 9px;
            padding: 2px 4px;
        }
    }

    /* Print Styles */
    @media print {
        .ihd-finance-dashboard {
            background: white;
            padding: 0;
        }
        
        .ihd-finance-container, .ihd-finance-content {
            box-shadow: none;
            border: 1px solid #000;
        }
        
        .ihd-finance-btn, .ihd-finance-logout-btn {
            display: none;
        }
        
        .ihd-finance-table {
            break-inside: avoid;
        }
    }

    /* Animation */
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    .ihd-finance-section {
        animation: fadeIn 0.3s ease;
    }

    /* Utility Classes */
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .text-left { text-align: left; }
    .font-bold { font-weight: bold; }
    .font-normal { font-weight: normal; }
    .text-sm { font-size: 11px; }
    .text-xs { font-size: 10px; }

    /* Currency formatting */
    .currency {
        font-family: 'Courier New', monospace;
        font-weight: bold;
    }

    .currency.positive { color: #27ae60; }
    .currency.negative { color: #e74c3c; }
    .currency.neutral { color: #7f8c8d; }
    </style>

    <div class="ihd-finance-dashboard">
       <br/><br/>
        
        <?php foreach ($messages as $m) echo $m; ?>

        <!-- Search Results Header (shown only when searching) -->
        <?php if (!empty($search) || $status_filter !== 'all'): ?>
        <div class="ihd-finance-search-results">
            <h4>🔍 Search Results</h4>
            <div class="search-terms">
                <?php if (!empty($search)): ?>
                    Searching for "<strong><?php echo esc_html($search); ?></strong>" 
                    in <?php echo $search_type === 'phone' ? 'Phone Numbers' : 'Student Names'; ?>
                <?php endif; ?>
                <?php if ($status_filter !== 'all'): ?>
                    <?php if (!empty($search)): ?> • <?php endif; ?>
                    Status: <strong><?php echo esc_html(ucfirst($status_filter)); ?></strong>
                <?php endif; ?>
                • Found <strong><?php echo count($students); ?></strong> student(s)
            </div>
            <a href="#" class="reset-search" onclick="resetFinanceSearch()">🔄 Reset Search</a>
        </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="ihd-finance-tabs" id="ihdFinanceTabs">
            <div class="ihd-finance-tab" data-target="unassigned-students">👥 Unassigned</div>
            <div class="ihd-finance-tab ihd-active" data-target="students-list">📋 Students & Fees</div>
            <div class="ihd-finance-tab" data-target="certificates">🎓 Certificates</div>
        </div>

        <!-- Unassigned Students Tab -->
        <div id="unassigned-students" class="ihd-finance-section">
            <h3>Unassigned Students from Sales</h3>
            
            <?php if(!empty($unassigned_students)) : ?>
                <div class="ihd-finance-student-section ihd-finance-unassigned-section">
                    <h4 class="ihd-finance-section-title ihd-finance-unassigned-title">
                        👥 Unassigned Students (<?php echo count($unassigned_students); ?>)
                    </h4>
                    <div class="ihd-finance-table-container">
                        <table class="ihd-finance-table">
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Phone</th>
                                    <th>Course</th>
                                    <th>Timing</th>
                                    <th>Schedule</th>
                                    <th>Mode</th>
                                    <th>Start Date</th>
                                    <th>Total Fees</th>
                                    <th>Paid</th>
                                    <th>Fee Status</th>
                                    <th>Student Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($unassigned_students as $s) :
                                    $course_id = get_post_meta($s->ID,'course_id',true);
                                    $course_name = get_term($course_id,'module')->name ?? '';
                                    $phone = get_post_meta($s->ID,'phone',true) ?: '';
                                    $timing = get_post_meta($s->ID,'timing',true) ?: '';
                                    $schedule_type = get_post_meta($s->ID,'schedule_type',true) ?: 'weekdays';
                                    $training_mode = get_post_meta($s->ID,'training_mode',true) ?: 'offline';
                                    $start_date = get_post_meta($s->ID,'start_date',true) ?: '';
                                    $total_fees = get_post_meta($s->ID,'total_fees',true) ?: 0;
                                    $fees_paid = get_post_meta($s->ID,'fees_paid',true) ?: 0;
                                    $fee_status = get_post_meta($s->ID, 'fee_status', true) ?: 'pending';
                                    $status = get_post_meta($s->ID,'status',true) ?: 'unassigned';
                                    $return_reason = get_post_meta($s->ID, 'return_reason', true);
                                    $returned_date = get_post_meta($s->ID, 'returned_to_finance_date', true);
                                    
                                    $status_class = 'ihd-finance-status-badge status-' . $status;
                                    $fee_status_class = 'ihd-finance-status-badge status-' . $fee_status;
                                    $mode_class = 'ihd-finance-mode-badge mode-' . $training_mode;
                                ?>
                                <tr>
                                    <td data-label="Student Name"><strong><?php echo esc_html($s->post_title); ?></strong></td>
                                    <td data-label="Phone"><?php echo esc_html($phone); ?></td>
                                    <td data-label="Course"><?php echo esc_html($course_name); ?></td>
                                    <td data-label="Timing"><?php echo esc_html($timing); ?></td>
                                    <td data-label="Schedule"><?php echo esc_html(ucfirst($schedule_type)); ?></td>
                                    <td data-label="Mode"><span class="<?php echo $mode_class; ?>"><?php echo esc_html(ucfirst($training_mode)); ?></span></td>
                                    <td data-label="Start Date"><?php echo esc_html($start_date); ?></td>
                                    <td data-label="Total Fees">₹<?php echo number_format($total_fees, 2); ?></td>
                                    <td data-label="Paid">₹<?php echo number_format($fees_paid, 2); ?></td>
                                    <td data-label="Fee Status"><span class="<?php echo $fee_status_class; ?>"><?php echo esc_html(ucfirst($fee_status)); ?></span></td>
                                    <td data-label="Student Status">
                                        <span class="<?php echo $status_class; ?>"><?php echo esc_html(ucfirst($status)); ?></span>
                                        <?php if ($return_reason): ?>
                                            <span class="return-reason">Returned: <?php echo esc_html($return_reason); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Actions">
                                        <div class="ihd-finance-action-form">
                                            <?php if ($status === 'unassigned' || $returned_date): ?>
                                                <form method="post" style="flex: 1;">
                                                    <?php wp_nonce_field('ihd_forward_to_manager_action','ihd_forward_to_manager_nonce'); ?>
                                                    <input type="hidden" name="student_id" value="<?php echo $s->ID; ?>">
                                                    <button type="submit" name="ihd_forward_to_manager" class="forward-btn">📤 Forward</button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color: #e67e22; font-weight: bold; font-size: 0.8em;">Forwarded</span>
                                            <?php endif; ?>
                                            <form method="post" onsubmit="return confirm('Are you sure you want to delete this student? This action cannot be undone.');">
                                                <?php wp_nonce_field('ihd_delete_unassigned_student_action','ihd_delete_unassigned_student_nonce'); ?>
                                                <input type="hidden" name="student_id" value="<?php echo $s->ID; ?>">
                                                <button type="submit" name="ihd_delete_unassigned_student" class="delete-btn">🗑️ Delete</button>
                                            </form>
                                        </div>
                                        
                                        <!-- Fee Update Form -->
                                        <?php if ($can_manage_fees): ?>
                                        <div class="ihd-finance-edit-form">
                                            <form method="post" class="ihd-finance-fee-form">
                                                <?php wp_nonce_field('ihd_update_fee_status_action','ihd_update_fee_status_nonce'); ?>
                                                <input type="hidden" name="student_id" value="<?php echo $s->ID; ?>">
                                                <input type="number" name="total_fees" value="<?php echo number_format($total_fees, 2, '.', ''); ?>" placeholder="Total Fees" min="0" step="0.01" required>
                                                <input type="number" name="fees_paid" value="<?php echo number_format($fees_paid, 2, '.', ''); ?>" placeholder="Paid Amount" min="0" step="0.01" required>
                                                <select name="fee_status">
                                                    <option value="paid" <?php selected($fee_status, 'paid'); ?>>Paid</option>
                                                    <option value="pending" <?php selected($fee_status, 'pending'); ?>>Pending</option>
                                                    <option value="hold" <?php selected($fee_status, 'hold'); ?>>Hold</option>
                                                </select>
                                                <button type="submit" name="ihd_update_fee_status">💰 Update Fees</button>
                                            </form>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php else: ?>
                <div class="ihd-finance-no-results">
                    <p>📭 No unassigned students found.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Students List Tab -->
        <div id="students-list" class="ihd-finance-section active">
            <h3>Student Fee Management</h3>
            
            <!-- Search and Filter Form -->
            <form method="post" class="ihd-finance-search-form" id="financeSearchForm">
                <?php wp_nonce_field('finance_search_action', 'finance_search_nonce'); ?>
                <div class="ihd-finance-search-group">
                    <input type="text" name="finance_search" placeholder="Search student..." value="<?php echo esc_attr($search); ?>" id="financeSearchInput" style="min-width:150px;">
                    <select name="search_type" id="searchTypeSelect" style="height:50px;">
                        <option value="name" <?php selected($search_type, 'name'); ?>>By Name</option>
                        <option value="phone" <?php selected($search_type, 'phone'); ?>>By Phone Number</option>
                    </select>
                </div>
                <select name="status" id="statusSelect"  style="height:50px;">
                    <option value="all" <?php selected($status_filter, 'all'); ?>>All Students</option>
                    <option value="active" <?php selected($status_filter, 'active'); ?>>Active Students</option>
                    <option value="completed" <?php selected($status_filter, 'completed'); ?>>Completed Students</option>
                    <option value="unassigned" <?php selected($status_filter, 'unassigned'); ?>>Unassigned Students</option>
                    <option value="forwarded" <?php selected($status_filter, 'forwarded'); ?>>Forwarded Students</option>
                </select>
                <button type="submit" name="finance_search_submit"  style="height:50px;">🔍 Search</button>
                <button type="button" onclick="resetFinanceSearch()" class="button"  style="height:50px;">🔄 Reset</button>
            </form>

            <?php if(!empty($students)) : ?>
                
                <!-- Hold Students Section -->
                <?php if(!empty($hold_students)): ?>
                <div class="ihd-finance-student-section ihd-finance-hold-section">
                    <h4 class="ihd-finance-section-title ihd-finance-hold-title">
                        ⚠️ Hold Students (<?php echo count($hold_students); ?>)
                    </h4>
                    <div class="ihd-finance-table-container">
                        <table class="ihd-finance-table">
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Phone</th>
                                    <th>Timing</th>
                                    <th>Trainer</th>
                                    <th>Course</th>
                                    <th>Start Date</th>
                                    <th>Status</th>
                                    <th>Completion</th>
                                    <th>Total Fees</th>
                                    <th>Paid</th>
                                    <th>Fee Status</th>
                                    <?php if ($can_manage_fees): ?><th>Update Fee</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($hold_students as $s) :
                                    $trainer_id = get_post_meta($s->ID,'trainer_user_id',true);
                                    $trainer = get_user_by('id',$trainer_id);
                                    $trainer_name = $trainer ? $trainer->first_name . ' ' . $trainer->last_name : 'N/A';
                                    $course_id = get_post_meta($s->ID,'course_id',true);
                                    $course_name = get_term($course_id,'module')->name ?? '';
                                    $completion = get_post_meta($s->ID,'completion',true) ?: 0;
                                    $phone = get_post_meta($s->ID,'phone',true) ?: '';
                                    $timing = get_post_meta($s->ID,'timing',true) ?: '';
                                    $start_date = get_post_meta($s->ID,'start_date',true) ?: '';
                                    $status = get_post_meta($s->ID,'status',true) ?: 'active';
                                    $fee_status = get_post_meta($s->ID, 'fee_status', true) ?: 'pending';
                                    $total_fees = get_post_meta($s->ID,'total_fees',true) ?: 0;
                                    $fees_paid = get_post_meta($s->ID,'fees_paid',true) ?: 0;
                                    $status_class = 'ihd-finance-status-badge status-' . $status;
                                    $fee_status_class = 'ihd-finance-status-badge status-' . $fee_status;
                                ?>
                                <tr>
                                    <td data-label="Student Name"><strong><?php echo esc_html($s->post_title); ?></strong></td>
                                    <td data-label="Phone"><?php echo esc_html($phone); ?></td>
                                    <td data-label="Timing"><?php echo esc_html($timing); ?></td>
                                    <td data-label="Trainer"><?php echo esc_html($trainer_name); ?></td>
                                    <td data-label="Course"><?php echo esc_html($course_name); ?></td>
                                    <td data-label="Start Date"><?php echo esc_html($start_date); ?></td>
                                    <td data-label="Status"><span class="<?php echo $status_class; ?>"><?php echo esc_html(ucfirst($status)); ?></span></td>
                                    <td data-label="Completion">
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span style="color:#3498db;font-weight:bold;"><?php echo intval($completion); ?>%</span>
                                            <div style="width: 60px; height: 6px; background: #ecf0f1; border-radius: 3px; overflow: hidden;">
                                                <div style="width: <?php echo intval($completion); ?>%; height: 100%; background: #3498db; border-radius: 3px;"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Total Fees">₹<?php echo number_format($total_fees, 2); ?></td>
                                    <td data-label="Paid">₹<?php echo number_format($fees_paid, 2); ?></td>
                                    <td data-label="Fee Status"><span class="<?php echo $fee_status_class; ?>"><?php echo esc_html(ucfirst($fee_status)); ?></span></td>
                                    <?php if ($can_manage_fees): ?>
                                    <td data-label="Update Fee">
                                        <form method="post" class="ihd-finance-fee-form">
                                            <?php wp_nonce_field('ihd_update_fee_status_action','ihd_update_fee_status_nonce'); ?>
                                            <input type="hidden" name="student_id" value="<?php echo $s->ID; ?>">
                                            <input type="number" name="total_fees" value="<?php echo number_format($total_fees, 2, '.', ''); ?>" placeholder="Total Fees" min="0" step="0.01" style="width: 80px;">
                                            <input type="number" name="fees_paid" value="<?php echo number_format($fees_paid, 2, '.', ''); ?>" placeholder="Paid" min="0" step="0.01" style="width: 80px;">
                                            <select name="fee_status">
                                                <option value="paid" <?php selected($fee_status, 'paid'); ?>>Paid</option>
                                                <option value="pending" <?php selected($fee_status, 'pending'); ?>>Pending</option>
                                                <option value="hold" <?php selected($fee_status, 'hold'); ?>>Hold</option>
                                            </select>
                                            <button type="submit" name="ihd_update_fee_status">Update</button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Pending Students Section -->
                <?php if(!empty($pending_students)): ?>
                <div class="ihd-finance-student-section ihd-finance-pending-section">
                    <h4 class="ihd-finance-section-title ihd-finance-pending-title">
                        ⏳ Pending Fee Students (<?php echo count($pending_students); ?>)
                    </h4>
                    <div class="ihd-finance-table-container">
                        <table class="ihd-finance-table">
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Phone</th>
                                    <th>Timing</th>
                                    <th>Trainer</th>
                                    <th>Course</th>
                                    <th>Start Date</th>
                                    <th>Status</th>
                                    <th>Completion</th>
                                    <th>Total Fees</th>
                                    <th>Paid</th>
                                    <th>Fee Status</th>
                                    <?php if ($can_manage_fees): ?><th>Update Fee</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_students as $s) :
                                    $trainer_id = get_post_meta($s->ID,'trainer_user_id',true);
                                    $trainer = get_user_by('id',$trainer_id);
                                    $trainer_name = $trainer ? $trainer->first_name . ' ' . $trainer->last_name : 'N/A';
                                    $course_id = get_post_meta($s->ID,'course_id',true);
                                    $course_name = get_term($course_id,'module')->name ?? '';
                                    $completion = get_post_meta($s->ID,'completion',true) ?: 0;
                                    $phone = get_post_meta($s->ID,'phone',true) ?: '';
                                    $timing = get_post_meta($s->ID,'timing',true) ?: '';
                                    $start_date = get_post_meta($s->ID,'start_date',true) ?: '';
                                    $status = get_post_meta($s->ID,'status',true) ?: 'active';
                                    $fee_status = get_post_meta($s->ID, 'fee_status', true) ?: 'pending';
                                    $total_fees = get_post_meta($s->ID,'total_fees',true) ?: 0;
                                    $fees_paid = get_post_meta($s->ID,'fees_paid',true) ?: 0;
                                    $status_class = 'ihd-finance-status-badge status-' . $status;
                                    $fee_status_class = 'ihd-finance-status-badge status-' . $fee_status;
                                ?>
                                <tr>
                                    <td data-label="Student Name"><strong><?php echo esc_html($s->post_title); ?></strong></td>
                                    <td data-label="Phone"><?php echo esc_html($phone); ?></td>
                                    <td data-label="Timing"><?php echo esc_html($timing); ?></td>
                                    <td data-label="Trainer"><?php echo esc_html($trainer_name); ?></td>
                                    <td data-label="Course"><?php echo esc_html($course_name); ?></td>
                                    <td data-label="Start Date"><?php echo esc_html($start_date); ?></td>
                                    <td data-label="Status"><span class="<?php echo $status_class; ?>"><?php echo esc_html(ucfirst($status)); ?></span></td>
                                    <td data-label="Completion">
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span style="color:#3498db;font-weight:bold;"><?php echo intval($completion); ?>%</span>
                                            <div style="width: 60px; height: 6px; background: #ecf0f1; border-radius: 3px; overflow: hidden;">
                                                <div style="width: <?php echo intval($completion); ?>%; height: 100%; background: #3498db; border-radius: 3px;"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Total Fees">₹<?php echo number_format($total_fees, 2); ?></td>
                                    <td data-label="Paid">₹<?php echo number_format($fees_paid, 2); ?></td>
                                    <td data-label="Fee Status"><span class="<?php echo $fee_status_class; ?>"><?php echo esc_html(ucfirst($fee_status)); ?></span></td>
                                    <?php if ($can_manage_fees): ?>
                                    <td data-label="Update Fee">
                                        <form method="post" class="ihd-finance-fee-form">
                                            <?php wp_nonce_field('ihd_update_fee_status_action','ihd_update_fee_status_nonce'); ?>
                                            <input type="hidden" name="student_id" value="<?php echo $s->ID; ?>">
                                            <input type="number" name="total_fees" value="<?php echo number_format($total_fees, 2, '.', ''); ?>" placeholder="Total Fees" min="0" step="0.01" style="width: 80px;">
                                            <input type="number" name="fees_paid" value="<?php echo number_format($fees_paid, 2, '.', ''); ?>" placeholder="Paid" min="0" step="0.01" style="width: 80px;">
                                            <select name="fee_status">
                                                <option value="paid" <?php selected($fee_status, 'paid'); ?>>Paid</option>
                                                <option value="pending" <?php selected($fee_status, 'pending'); ?>>Pending</option>
                                                <option value="hold" <?php selected($fee_status, 'hold'); ?>>Hold</option>
                                            </select>
                                            <button type="submit" name="ihd_update_fee_status">Update</button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Paid Students Section -->
                <?php if(!empty($paid_students)): ?>
                <div class="ihd-finance-student-section ihd-finance-paid-section">
                    <h4 class="ihd-finance-section-title ihd-finance-paid-title">
                                        ✅ Paid Students (<?php echo count($paid_students); ?>)
                    </h4>
                    <div class="ihd-finance-table-container">
                        <table class="ihd-finance-table">
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Phone</th>
                                    <th>Timing</th>
                                    <th>Trainer</th>
                                    <th>Course</th>
                                    <th>Start Date</th>
                                    <th>Status</th>
                                    <th>Completion</th>
                                    <th>Total Fees</th>
                                    <th>Paid</th>
                                    <th>Fee Status</th>
                                    <?php if ($can_manage_fees): ?><th>Update Fee</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($paid_students as $s) :
                                    $trainer_id = get_post_meta($s->ID,'trainer_user_id',true);
                                    $trainer = get_user_by('id',$trainer_id);
                                    $trainer_name = $trainer ? $trainer->first_name . ' ' . $trainer->last_name : 'N/A';
                                    $course_id = get_post_meta($s->ID,'course_id',true);
                                    $course_name = get_term($course_id,'module')->name ?? '';
                                    $completion = get_post_meta($s->ID,'completion',true) ?: 0;
                                    $phone = get_post_meta($s->ID,'phone',true) ?: '';
                                    $timing = get_post_meta($s->ID,'timing',true) ?: '';
                                    $start_date = get_post_meta($s->ID,'start_date',true) ?: '';
                                    $status = get_post_meta($s->ID,'status',true) ?: 'active';
                                    $fee_status = get_post_meta($s->ID, 'fee_status', true) ?: 'paid';
                                    $total_fees = get_post_meta($s->ID,'total_fees',true) ?: 0;
                                    $fees_paid = get_post_meta($s->ID,'fees_paid',true) ?: 0;
                                    $status_class = 'ihd-finance-status-badge status-' . $status;
                                    $fee_status_class = 'ihd-finance-status-badge status-' . $fee_status;
                                ?>
                                <tr>
                                    <td data-label="Student Name"><strong><?php echo esc_html($s->post_title); ?></strong></td>
                                    <td data-label="Phone"><?php echo esc_html($phone); ?></td>
                                    <td data-label="Timing"><?php echo esc_html($timing); ?></td>
                                    <td data-label="Trainer"><?php echo esc_html($trainer_name); ?></td>
                                    <td data-label="Course"><?php echo esc_html($course_name); ?></td>
                                    <td data-label="Start Date"><?php echo esc_html($start_date); ?></td>
                                    <td data-label="Status"><span class="<?php echo $status_class; ?>"><?php echo esc_html(ucfirst($status)); ?></span></td>
                                    <td data-label="Completion">
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span style="color:#3498db;font-weight:bold;"><?php echo intval($completion); ?>%</span>
                                            <div style="width: 60px; height: 6px; background: #ecf0f1; border-radius: 3px; overflow: hidden;">
                                                <div style="width: <?php echo intval($completion); ?>%; height: 100%; background: #3498db; border-radius: 3px;"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Total Fees">₹<?php echo number_format($total_fees, 2); ?></td>
                                    <td data-label="Paid">₹<?php echo number_format($fees_paid, 2); ?></td>
                                    <td data-label="Fee Status"><span class="<?php echo $fee_status_class; ?>"><?php echo esc_html(ucfirst($fee_status)); ?></span></td>
                                    <?php if ($can_manage_fees): ?>
                                    <td data-label="Update Fee">
                                        <form method="post" class="ihd-finance-fee-form">
                                            <?php wp_nonce_field('ihd_update_fee_status_action','ihd_update_fee_status_nonce'); ?>
                                            <input type="hidden" name="student_id" value="<?php echo $s->ID; ?>">
                                            <input type="number" name="total_fees" value="<?php echo number_format($total_fees, 2, '.', ''); ?>" placeholder="Total Fees" min="0" step="0.01" style="width: 80px;">
                                            <input type="number" name="fees_paid" value="<?php echo number_format($fees_paid, 2, '.', ''); ?>" placeholder="Paid" min="0" step="0.01" style="width: 80px;">
                                            <select name="fee_status">
                                                <option value="paid" <?php selected($fee_status, 'paid'); ?>>Paid</option>
                                                <option value="pending" <?php selected($fee_status, 'pending'); ?>>Pending</option>
                                                <option value="hold" <?php selected($fee_status, 'hold'); ?>>Hold</option>
                                            </select>
                                            <button type="submit" name="ihd_update_fee_status">Update</button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="ihd-finance-no-results">
                    <p>📭 No students found matching your search criteria.</p>
                    <?php if ($search): ?>
                        <p style="margin-top: 2%; color: #7f8c8d; font-size: 0.9em;">
                            Search: "<strong><?php echo esc_html($search); ?></strong>" 
                            (<?php echo $search_type === 'phone' ? 'Phone Number' : 'Name'; ?>)
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Certificates Tab -->
        <div id="certificates" class="ihd-finance-section">
            <h3>🎓 Certificate Management</h3>

            <?php if(!empty($completed_students)) : ?>
                <div class="ihd-finance-table-container">
                    <table class="ihd-finance-table">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Phone</th>
                                <th>Timing</th>
                                <th>Trainer</th>
                                <th>Course</th>
                                <th>Completion</th>
                                <th>Certificate ID</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($completed_students as $s) :
                                $trainer_id = get_post_meta($s->ID,'trainer_user_id',true);
                                $trainer = get_user_by('id',$trainer_id);
                                $trainer_name = $trainer ? $trainer->first_name . ' ' . $trainer->last_name : 'N/A';
                                $course_id = get_post_meta($s->ID,'course_id',true);
                                $course_name = get_term($course_id,'module')->name ?? '';
                                $completion = get_post_meta($s->ID,'completion',true) ?: 0;
                                $cert_id = get_post_meta($s->ID,'certificate_id',true) ?: '';
                                $phone = get_post_meta($s->ID,'phone',true) ?: '';
                                $timing = get_post_meta($s->ID,'timing',true) ?: '';
                            ?>
                            <tr>
                                <td data-label="Student Name"><strong><?php echo esc_html($s->post_title); ?></strong></td>
                                <td data-label="Phone"><?php echo esc_html($phone); ?></td>
                                <td data-label="Timing"><?php echo esc_html($timing); ?></td>
                                <td data-label="Trainer"><?php echo esc_html($trainer_name); ?></td>
                                <td data-label="Course"><?php echo esc_html($course_name); ?></td>
                                <td data-label="Completion">
                                    <span style="color:#27ae60;font-weight:bold;"><?php echo intval($completion); ?>%</span>
                                </td>
                                <td data-label="Certificate ID">
                                    <?php if($cert_id): ?>
                                        <span style="color:#27ae60;font-weight:bold;"><?php echo esc_html($cert_id); ?></span>
                                    <?php else: ?>
                                        <span style="color:#e67e22; font-style: italic;">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Action">
                                    <?php if(!$cert_id) : ?>
                                    <form method="post" class="ihd-finance-certificate-form">
                                        <?php wp_nonce_field('ihd_generate_certificate_action','ihd_generate_certificate_nonce'); ?>
                                        <input type="hidden" name="student_id" value="<?php echo $s->ID; ?>">
                                        <button type="submit" name="ihd_generate_certificate">🎫 Generate</button>
                                    </form>
                                    <?php else: ?>
                                        <span class="ihd-finance-certificate-generated">✅ Generated</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="ihd-finance-no-results">
                    <p>📭 No completed students found.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // Finance Dashboard JavaScript
    document.addEventListener('DOMContentLoaded', function() {
        // Tab functionality
        const financeTabs = document.querySelectorAll('.ihd-finance-tab');
        
        financeTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                // Remove active class from all tabs
                document.querySelectorAll('.ihd-finance-tab').forEach(t => {
                    t.classList.remove('ihd-active');
                });
                
                // Add active class to clicked tab
                tab.classList.add('ihd-active');

                // Hide all sections
                document.querySelectorAll('.ihd-finance-section').forEach(sec => {
                    sec.classList.remove('active');
                });
                
                // Show target section
                const targetId = tab.getAttribute('data-target');
                const targetSection = document.getElementById(targetId);
                if (targetSection) {
                    targetSection.classList.add('active');
                }
            });
        });

        // Auto-submit form when search type or status changes
        const searchTypeSelect = document.getElementById('searchTypeSelect');
        const statusSelect = document.getElementById('statusSelect');
        
        if (searchTypeSelect) {
            searchTypeSelect.addEventListener('change', function() {
                document.getElementById('financeSearchForm').submit();
            });
        }
        
        if (statusSelect) {
            statusSelect.addEventListener('change', function() {
                document.getElementById('financeSearchForm').submit();
            });
        }

        // Add smooth scrolling for mobile
        financeTabs.forEach(tab => {
            tab.addEventListener('click', function(e) {
                if (window.innerWidth < 768) {
                    e.preventDefault();
                    const targetSection = document.getElementById(this.dataset.target);
                    if (targetSection) {
                        targetSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }
            });
        });
    });

    // Reset search function
    function resetFinanceSearch() {
        document.getElementById('financeSearchInput').value = '';
        document.getElementById('searchTypeSelect').value = 'name';
        document.getElementById('statusSelect').value = 'all';
        document.getElementById('financeSearchForm').submit();
    }
    
    // Auto-update fee status when paid amount changes
    document.addEventListener('input', function(e) {
        if (e.target.name === 'fees_paid') {
            const form = e.target.closest('form');
            const totalFeesInput = form.querySelector('input[name="total_fees"]');
            const feeStatusSelect = form.querySelector('select[name="fee_status"]');
            
            if (totalFeesInput && feeStatusSelect) {
                const totalFees = parseFloat(totalFeesInput.value) || 0;
                const feesPaid = parseFloat(e.target.value) || 0;
                
                if (feesPaid >= totalFees && totalFees > 0) {
                    feeStatusSelect.value = 'paid';
                } else if (feesPaid > 0) {
                    feeStatusSelect.value = 'pending';
                }
            }
        }
    });
    </script>
    <?php
    return ob_get_clean();
}
/* ---------------- Certificate Verification Shortcode ---------------- */

/* ---------------- Certificate Verification Shortcode ---------------- */
function ihd_certificate_verification_shortcode() {
    ob_start();

    $result = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_verify_certificate'])) {
        if (!isset($_POST['ihd_verify_certificate_nonce']) || !wp_verify_nonce($_POST['ihd_verify_certificate_nonce'], 'ihd_verify_certificate_action')) {
            $result = '<div class="error-message"><i class="fas fa-exclamation-triangle"></i> Invalid request.</div>';
        } else {
            $student_name = sanitize_text_field($_POST['student_name'] ?? '');
            $cert_id      = sanitize_text_field($_POST['certificate_id'] ?? '');

            if (!$student_name || !$cert_id) {
                $result = '<div class="error-message"><i class="fas fa-exclamation-circle"></i> Please enter both Name and Certificate ID.</div>';
            } else {
                $query = new WP_Query(array(
                    'post_type' => 'student',
                    'meta_query' => array(
                        array('key'=>'status','value'=>'completed','compare'=>'='),
                        array('key'=>'certificate_id','value'=>$cert_id,'compare'=>'='),
                    ),
                    'posts_per_page' => 1,
                ));

                $found = false;
                if ($query->have_posts()) {
                    while ($query->have_posts()) {
                        $query->the_post();
                        $name = get_the_title();
                        if (strcasecmp($name, $student_name) === 0) {
                            $found = true;
                            $course_id = get_post_meta(get_the_ID(), 'course_id', true);
                            $course_name = get_term($course_id, 'module')->name ?? '';
                            $start_date = get_post_meta(get_the_ID(), 'start_date', true) ?: '';
                            $completion_date = get_post_meta(get_the_ID(), 'completion_date', true) ?: date('Y-m-d');
                            break;
                        }
                    }
                    wp_reset_postdata();
                }

                if ($found) {
                    $result = '
                    <div class="certificate-verification-success">
                        <div class="success-header">
                            <i class="fas fa-check-circle"></i>
                            <h3>Certificate Verified Successfully!</h3>
                        </div>
                        <p>The certificate has been verified and is authentic.</p>
                    </div>
                    <div class="certificate-wrapper">
                        <div class="certificate-template">
                            <div class="certificate-content">
                                <br /><br /><br /><br /><br /><br /><br /><br /><br /><br /><br /><br /><br /><br /><br /><br /><br /><br />
                                <h2 class="student-name">'.esc_html($name).'</h2>
                                <br /><br />
                                <h3 class="course-name"> '.esc_html($course_name).'</h3>
                                <br />
                                <p class="date-text"  style="margin-left:27%">
                                    <strong>'.esc_html($start_date).'</strong>
                                    <strong style="margin-left:14%">'.esc_html($completion_date).'</strong>
                                </p>
                                <br />
                                 <p class="">
                                    <strong class="certificate-id" style="font-size:20px">'.esc_html($cert_id).'</strong>
                                    <img src="https://signature.freefire-name.com/img.php?f=2&t=Raj" alt="Signature" class=""  style="margin-left:35%;width:80px;padding-bottom:30px;">
                                </p>
                            </div>
                        </div>
                        <div class="certificate-actions">
                            <button class="print-btn" onclick="window.print()">
                                <i class="fas fa-print"></i> Print Certificate
                            </button>
                            <button class="download-btn">
                                <i class="fas fa-download"></i> Download PDF
                            </button>
                        </div>
                    </div>';
                } else {
                    $result = '<div class="error-message"><i class="fas fa-times-circle"></i> No certificate found for the provided Name and ID.</div>';
                }
            }
        }
    }
    ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Traditional Verification Page Styles */
        .certificate-verification-page {
            max-width: 100%;
            margin: 0 auto;
            padding: 20px;
            font-family: 'Georgia', 'Times New Roman', serif;
            background: linear-gradient(135deg, #f9f9f9 0%, #ffffff 100%);
            min-height: 100vh;
        }
        :root {
            --primary-color: #00e5ff;
            --secondary-color: #0066cc;
            --accent-color: #ff4081;
            --gold-color: #d4af37;
            --dark-color: #333;
            --light-color: #f4f4f4;
        }
        .verification-header {
            text-align: center;
            margin-bottom: 40px;
            padding: 30px;
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
            color: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }
        
        .verification-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,0 L100,0 L100,100 Z" fill="rgba(255,255,255,0.1)"/></svg>');
            background-size: cover;
        }
        
        .verification-header h1 {
            font-size: 2.8rem;
            margin-bottom: 15px;
            position: relative;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
            font-weight: 700;
        }
        
        .verification-header p {
            font-size: 1.3rem;
            max-width: 700px;
            margin: 0 auto;
            position: relative;
            opacity: 0.9;
        }
        
        .institute-name {
            font-size: 1.6rem;
            font-weight: 600;
            margin-top: 15px;
            color: #f1c40f;
            position: relative;
            font-style: italic;
        }
        
        .verification-form-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            margin-bottom: 40px;
            border: 1px solid #e0e0e0;
        }
        
        .form-title {
            text-align: center;
            margin-bottom: 30px;
            color: #2c3e50;
            font-size: 2rem;
            position: relative;
            padding-bottom: 15px;
        }
        
        .form-title::after {
            content: '';
            position: absolute;
            width: 100px;
            height: 4px;
            background: linear-gradient(90deg, #3498db, #2c3e50);
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            border-radius: 2px;
        }
        
        .verification-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 1.1rem;
        }
        
        .form-group input {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }
        
        .form-group input:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
            outline: none;
            background: white;
        }
        
        .verify-btn {
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
            color: white;
            border: none;
            padding: 16px 35px;
            border-radius: 8px;
            font-size: 1.2rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            grid-column: 1 / -1;
            justify-self: center;
            width: 250px;
            box-shadow: 0 4px 15px rgba(39, 174, 96, 0.3);
        }
        
        .verify-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(39, 174, 96, 0.4);
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
        }
        
        .verify-btn:active {
            transform: translateY(-1px);
        }
        
        .error-message {
            background: #ffeaea;
            color: #d63031;
            padding: 20px;
            border-radius: 8px;
            margin: 25px 0;
            border-left: 5px solid #d63031;
            font-size: 1.1rem;
        }
        
        .error-message i {
            margin-right: 10px;
            font-size: 1.3rem;
        }
        
        .certificate-verification-success {
            background: #e8f6ef;
            color: #27ae60;
            padding: 25px;
            border-radius: 8px;
            margin: 25px 0;
            border-left: 5px solid #27ae60;
            text-align: center;
        }
        
        .success-header {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
        }
        
        .success-header i {
            font-size: 2rem;
            margin-right: 15px;
        }
        
        .success-header h3 {
            margin: 0;
            font-size: 1.5rem;
        }
        
        .certificate-actions {
            text-align: center;
            margin: 30px 0;
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .print-btn, .download-btn {
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .print-btn {
            background: #3498db;
            color: white;
        }
        
        .download-btn {
            background: #9b59b6;
            color: white;
        }
        
        .print-btn:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }
        
        .download-btn:hover {
            background: #8e44ad;
            transform: translateY(-2px);
        }
        
        .verification-footer {
            text-align: center;
            margin-top: 50px;
            padding: 25px;
            color: #7f8c8d;
            font-size: 1rem;
            border-top: 1px solid #ecf0f1;
        }
        
        /* Original Certificate Styles - Keep Intact */
        .certificate-template {
            background: url('https://i0.wp.com/placementps.com/wp-content/uploads/2025/07/PPS-1.webp?resize=768%2C1086&ssl=1') no-repeat center center;
            background-size: cover;
            width: 100%;
            max-width: 794px;
            height: 1123px;
            margin: 0 auto;
            text-align:center;
            font-family: 'Times New Roman', serif;
            position: relative;
            box-sizing: border-box;
        }
        .student-name {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 15px;
            text-transform: uppercase;
            color: #000;
            text-align:center;
        }
        .course-name {
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 20px;
            margin-left:40%;
        }
        .date-text {
            font-size: 24px;
            margin-bottom: 30px;
        }
        .certificate-id {
            font-size: 20px;
        }
        
        @media (max-width: 768px) {
            .certificate-template {
                height: 800px;
                background-size: contain;
            }
            .student-name {
                font-size: 24px;
            }
            .course-name {
                font-size: 18px;
                margin-left: 35%;
            }
            .date-text {
                font-size: 18px;
            }
            .certificate-id {
                font-size: 16px;
            }
            
            .verification-header h1 {
                font-size: 2.2rem;
            }
            
            .verification-header p {
                font-size: 1.1rem;
            }
            
            .verification-form {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .certificate-template {
                height: 600px;
            }
            .student-name {
                font-size: 20px;
            }
            .course-name {
                font-size: 16px;
                margin-left: 30%;
            }
            .date-text {
                font-size: 16px;
            }
            
            .verification-header {
                padding: 20px;
            }
            
            .verification-header h1 {
                font-size: 1.8rem;
            }
            
            .verification-form-container {
                padding: 25px;
            }
            
            .certificate-actions {
                flex-direction: column;
                align-items: center;
            }
        }
    </style>

    <div class="certificate-verification-page">
        <!-- Header Section -->
        <header class="verification-header">
            <h1>Certificate Verification</h1>
            <p>Verify the authenticity of certificates issued by Placement Point Solutions</p>
            <div class="institute-name">Placement Point Solutions</div>
        </header>
        
        <!-- Verification Form -->
        <section class="verification-form-container">
            <h2 class="form-title">Verify Your Certificate</h2>
            <form class="verification-form" method="post">
                <?php wp_nonce_field('ihd_verify_certificate_action','ihd_verify_certificate_nonce'); ?>
                <div class="form-group">
                    <label for="student_name"><i class="fas fa-user-graduate"></i> Student Name</label>
                    <input type="text" id="student_name" name="student_name" placeholder="Enter Student Name" required value="<?php echo isset($_POST['student_name']) ? esc_attr($_POST['student_name']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="certificate_id"><i class="fas fa-certificate"></i> Certificate ID</label>
                    <input type="text" id="certificate_id" name="certificate_id" placeholder="Enter Certificate ID" required value="<?php echo isset($_POST['certificate_id']) ? esc_attr($_POST['certificate_id']) : ''; ?>">
                </div>
                
                <button type="submit" class="verify-btn" name="ihd_verify_certificate">
                    <i class="fas fa-search"></i> Verify Certificate
                </button>
            </form>
        </section>
        
        <!-- Display Results -->
        <?php echo $result; ?>
        
        <!-- Footer 
        <footer class="verification-footer">
            <p>© <?php echo date('Y'); ?> Placement Point Solutions. All rights reserved.</p>
            <p>For any queries, contact us at: support@placementpoints.com | Phone: +1 (555) 123-4567</p>
        </footer>-->
    </div>

    <?php
    return ob_get_clean();
}
function ihd_manager_attendance_report() {
    if (!is_user_logged_in() || !current_user_can('view_manager')) return 'Access denied.';

    global $wpdb;
    $attendance_table = $wpdb->prefix . 'ihd_trainer_attendance';
    
    // Date range filter - with proper validation
    $start_date = sanitize_text_field($_GET['manager_attendance_start'] ?? date('Y-m-d', strtotime('-30 days')));
    $end_date = sanitize_text_field($_GET['manager_attendance_end'] ?? date('Y-m-d'));
    $trainer_filter = isset($_GET['trainer_filter']) ? intval($_GET['trainer_filter']) : 0;
    
    // Validate dates
    if (!strtotime($start_date) || !strtotime($end_date)) {
        $start_date = date('Y-m-d', strtotime('-30 days'));
        $end_date = date('Y-m-d');
    }
    
    // Ensure end date is not before start date
    if (strtotime($end_date) < strtotime($start_date)) {
        $end_date = $start_date;
    }
    
    // Build query for attendance data
    $query = "SELECT a.*, u.display_name, u.user_login 
              FROM $attendance_table a 
              LEFT JOIN {$wpdb->users} u ON a.trainer_id = u.ID 
              WHERE a.attendance_date BETWEEN %s AND %s";
    
    $params = array($start_date, $end_date);
    
    if ($trainer_filter > 0) {
        $query .= " AND a.trainer_id = %d";
        $params[] = $trainer_filter;
    }
    
    $query .= " ORDER BY a.attendance_date DESC, a.attendance_time DESC";
    
    $attendance_data = $wpdb->get_results($wpdb->prepare($query, $params));
    
    // Calculate summary statistics
    $total_records = count($attendance_data);
    $unique_trainers = array();
    $present_count = 0;
    
    foreach ($attendance_data as $record) {
        $unique_trainers[$record->trainer_id] = true;
        if ($record->location_status === 'present') {
            $present_count++;
        }
    }
    
    $unique_trainer_count = count($unique_trainers);
    $absent_count = $total_records - $present_count;
    
    ob_start();
    ?>
    
    <style>
    /* Manager Attendance Report Traditional Responsive Styles */
    .ihd-manager-attendance-report {
        background: #fff;
        padding: 20px;
        border-radius: 6px;
        border: 1px solid #ddd;
        font-size: 14px;
    }

    .ihd-manager-attendance-report h3 {
        margin: 0 0 20px 0;
        color: #2c3e50;
        font-size: 18px;
        font-weight: 600;
        padding-bottom: 10px;
        border-bottom: 2px solid #3498db;
    }

    .ihd-attendance-summary {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 15px;
        margin: 20px 0;
    }

    .ihd-attendance-summary-card {
        background: white;
        padding: 15px;
        border-radius: 6px;
        border: 1px solid #ddd;
        border-left: 4px solid #3498db;
        text-align: center;
        transition: transform 0.2s ease;
    }

    .ihd-attendance-summary-card:hover {
        transform: translateY(-2px);
    }

    .ihd-attendance-summary-card h4 {
        margin: 0 0 10px 0;
        color: #7f8c8d;
        font-size: 13px;
        font-weight: 600;
    }

    .ihd-attendance-summary-card p {
        font-size: 20px;
        font-weight: 700;
        margin: 0;
        color: #3498db;
    }

    .ihd-manager-attendance-filters {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 6px;
        border: 1px solid #e9ecef;
        margin-bottom: 20px;
    }

    .ihd-manager-attendance-filters h4 {
        margin: 0 0 15px 0;
        color: #2c3e50;
        font-size: 14px;
        font-weight: 600;
    }

    .ihd-manager-filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 10px;
        align-items: end;
    }

    .ihd-filter-group {
        display: flex;
        flex-direction: column;
    }

    .ihd-filter-group label {
        margin-bottom: 5px;
        font-weight: 600;
        color: #495057;
        font-size: 12px;
    }

    .ihd-filter-group input,
    .ihd-filter-group select {
        padding: 8px 10px;
        border: 1px solid #ced4da;
        border-radius: 4px;
        font-size: 13px;
        width: 100%;
        box-sizing: border-box;
        height: 38px;
    }

    .ihd-filter-group button,
    .ihd-filter-group a {
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

    .ihd-filter-group button {
        background: #3498db;
        color: white;
        border: 1px solid #2980b9;
    }

    .ihd-filter-group button:hover {
        background: #2980b9;
    }

    .ihd-filter-group a {
        background: #95a5a6;
        color: white;
        border: 1px solid #7f8c8d;
    }

    .ihd-filter-group a:hover {
        background: #7f8c8d;
        color: white;
        text-decoration: none;
    }

    /* Traditional Table */
    .ihd-manager-attendance-table {
        width: 100%;
        border-collapse: collapse;
        margin: 15px 0;
        background: #fff;
        border: 1px solid #ddd;
        font-size: 11px;
        table-layout: fixed;
    }

    .ihd-manager-attendance-table thead {
        background: #2c3e50;
    }

    .ihd-manager-attendance-table th {
        color: white;
        padding: 10px 6px;
        text-align: left;
        font-weight: 600;
        font-size: 11px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        border-right: 1px solid #34495e;
    }

    .ihd-manager-attendance-table th:last-child {
        border-right: none;
    }

    .ihd-manager-attendance-table td {
        padding: 8px 6px;
        border-bottom: 1px solid #ecf0f1;
        vertical-align: top;
        border-right: 1px solid #ecf0f1;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }

    .ihd-manager-attendance-table td:last-child {
        border-right: none;
    }

    .ihd-manager-attendance-table tbody tr:hover {
        background: #f8f9fa;
    }

    .ihd-manager-attendance-table tbody tr:nth-child(even) {
        background: #fafbfc;
    }

    /* Attendance Badges */
    .attendance-badge {
        padding: 4px 8px;
        border-radius: 3px;
        font-size: 10px;
        font-weight: 600;
        display: inline-block;
        text-align: center;
        min-width: 60px;
    }

    .badge-present {
        background: #27ae60;
        color: white;
    }

    .badge-absent {
        background: #e74c3c;
        color: white;
    }

    /* Export Button */
    .ihd-export-attendance-btn {
        background: #27ae60;
        color: white;
        padding: 10px 20px;
        border: 1px solid #229954;
        border-radius: 4px;
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s ease;
        margin-bottom: 15px;
    }

    .ihd-export-attendance-btn:hover {
        background: #229954;
        border-color: #1e8449;
    }

    /* Report Date Range */
    .ihd-report-date-range {
        background: #e8f6f3;
        padding: 12px 15px;
        border-radius: 6px;
        margin-bottom: 15px;
        border-left: 4px solid #27ae60;
        border: 1px solid #d1f2eb;
    }

    .ihd-report-date-range h4 {
        margin: 0 0 8px 0;
        color: #27ae60;
        font-size: 13px;
        font-weight: 600;
    }

    .ihd-report-date-range p {
        margin: 0;
        font-size: 13px;
        color: #2c3e50;
    }

    /* Table Container */
    .table-container {
        overflow-x: auto;
        border-radius: 6px;
        margin: 15px 0;
        -webkit-overflow-scrolling: touch;
        border: 1px solid #ddd;
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
        margin: 0 0 10px 0;
    }

    .no-results small {
        font-size: 12px;
        color: #95a5a6;
    }

    /* Code styling for IP addresses */
    code {
        background: #f8f9fa;
        padding: 2px 6px;
        border-radius: 3px;
        border: 1px solid #e9ecef;
        font-family: 'Courier New', monospace;
        font-size: 10px;
        color: #e74c3c;
    }

    /* Small text for IDs */
    small {
        font-size: 10px;
        color: #7f8c8d;
    }

    /* Mobile-specific styles */
    @media (max-width: 1024px) {
        .ihd-manager-attendance-table {
            font-size: 10px;
        }
        
        .ihd-manager-attendance-table th,
        .ihd-manager-attendance-table td {
            padding: 6px 4px;
        }
    }

    @media (max-width: 768px) {
        .ihd-manager-attendance-report {
            padding: 15px;
        }
        
        .ihd-manager-attendance-report h3 {
            font-size: 16px;
        }
        
        .ihd-attendance-summary {
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
        
        .ihd-attendance-summary-card {
            padding: 12px;
        }
        
        .ihd-attendance-summary-card p {
            font-size: 18px;
        }
        
        .ihd-manager-filter-grid {
            grid-template-columns: 1fr;
        }
        
        .ihd-manager-attendance-table {
            font-size: 9px;
            min-width: 1000px;
        }
        
        .ihd-manager-attendance-table th,
        .ihd-manager-attendance-table td {
            padding: 6px 4px;
            white-space: nowrap;
        }
        
        .attendance-badge {
            font-size: 9px;
            padding: 3px 6px;
            min-width: 50px;
        }
        
        .ihd-export-attendance-btn {
            padding: 12px 20px;
            font-size: 12px;
            width: 100%;
        }
    }

    @media (max-width: 480px) {
        .ihd-manager-attendance-report {
            padding: 12px;
        }
        
        .ihd-attendance-summary {
            grid-template-columns: 1fr;
        }
        
        .ihd-attendance-summary-card {
            padding: 10px;
        }
        
        .ihd-attendance-summary-card p {
            font-size: 16px;
        }
        
        .ihd-manager-attendance-table {
            font-size: 8px;
        }
        
        .ihd-manager-attendance-table th,
        .ihd-manager-attendance-table td {
            padding: 4px 3px;
        }
        
        .attendance-badge {
            font-size: 8px;
            padding: 2px 4px;
            min-width: 40px;
        }
        
        .ihd-report-date-range {
            padding: 10px 12px;
        }
        
        .ihd-report-date-range h4 {
            font-size: 12px;
        }
        
        .ihd-report-date-range p {
            font-size: 11px;
        }
    }

    /* Print Styles */
    @media print {
        .ihd-manager-attendance-report {
            background: white;
            padding: 0;
            border: none;
        }
        
        .ihd-manager-attendance-filters,
        .ihd-export-attendance-btn {
            display: none;
        }
        
        .ihd-manager-attendance-table {
            break-inside: avoid;
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

    /* Column Widths for Better Readability */
    .col-date { width: 90px; }
    .col-trainer { width: 120px; }
    .col-time { width: 80px; }
    .col-status { width: 80px; }
    .col-ip { width: 100px; }
    .col-location { width: 150px; }
    .col-marked { width: 120px; }
    .col-login { width: 80px; }
    .col-logout { width: 80px; }
    .col-session { width: 80px; }

    /* Status Colors */
    .status-logged-in { color: #e67e22; font-weight: 600; }
    .status-completed { color: #27ae60; font-weight: 600; }
    .status-pending { color: #e74c3c; font-weight: 600; }

    /* Hover Effects */
    .ihd-manager-attendance-table tbody tr {
        transition: background-color 0.2s ease;
    }

    .ihd-manager-attendance-table tbody tr:hover {
        background-color: #e3f2fd !important;
    }

    /* Focus States for Accessibility */
    .ihd-filter-group input:focus,
    .ihd-filter-group select:focus {
        border-color: #3498db;
        outline: none;
        box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.1);
    }

    .ihd-export-attendance-btn:focus {
        outline: 2px solid #3498db;
        outline-offset: 2px;
    }
    </style>

    <div class="ihd-manager-attendance-report">
        <h3>📊 Trainer Attendance Report</h3>
        
        <!-- Report Date Range Display -->
        <div class="ihd-report-date-range">
            <h4>📅 Report Period</h4>
            <p><strong>From:</strong> <?php echo date('M j, Y', strtotime($start_date)); ?> 
               <strong>To:</strong> <?php echo date('M j, Y', strtotime($end_date)); ?>
               <?php if ($trainer_filter > 0): ?>
                   | <strong>Trainer:</strong> 
                   <?php 
                   $trainer = get_user_by('id', $trainer_filter);
                   echo $trainer ? esc_html(trim($trainer->first_name . ' ' . $trainer->last_name) ?: $trainer->user_login) : 'Unknown';
                   ?>
               <?php endif; ?>
            </p>
        </div>

        <!-- Filters -->
        <div class="ihd-manager-attendance-filters">
            <h4>🔍 Filter Attendance Report</h4>
            <form method="get" class="ihd-manager-filter-grid">
                <input type="hidden" name="tab" value="attendance-report">
                
                <div class="ihd-filter-group">
                    <label for="manager_attendance_start">Start Date</label>
                    <input type="date" id="manager_attendance_start" name="manager_attendance_start" value="<?php echo esc_attr($start_date); ?>" required>
                </div>
                
                <div class="ihd-filter-group">
                    <label for="manager_attendance_end">End Date</label>
                    <input type="date" id="manager_attendance_end" name="manager_attendance_end" value="<?php echo esc_attr($end_date); ?>" required>
                </div>
                
                <div class="ihd-filter-group">
                    <label for="trainer_filter">Trainer</label>
                    <select id="trainer_filter" name="trainer_filter">
                        <option value="0">All Trainers</option>
                        <?php
                        $trainers = get_users(array('role' => 'trainer'));
                        foreach ($trainers as $trainer) {
                            $selected = $trainer_filter == $trainer->ID ? 'selected' : '';
                            $trainer_name = trim($trainer->first_name . ' ' . $trainer->last_name) ?: $trainer->user_login;
                            echo '<option value="' . $trainer->ID . '" ' . $selected . '>' . esc_html($trainer_name) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                
                <div class="ihd-filter-group">
                    <button type="submit" name="generate_attendance_report" style="width: 100%; padding: 3%; background: #3498db; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600;">
                        🔍 Generate Report
                    </button>
                </div>
                
                <div class="ihd-filter-group">
                    <a href="?tab=attendance-report" style="display: block; padding: 3%; background: #95a5a6; color: white; text-align: center; text-decoration: none; border-radius: 8px; font-weight: 600;">
                        🔄 Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Summary Cards -->
        <div class="ihd-attendance-summary">
            <div class="ihd-attendance-summary-card">
                <h4>Total Records</h4>
                <p><?php echo intval($total_records); ?></p>
            </div>
            <div class="ihd-attendance-summary-card">
                <h4>Trainers</h4>
                <p><?php echo intval($unique_trainer_count); ?></p>
            </div>
            <div class="ihd-attendance-summary-card">
                <h4>Present</h4>
                <p style="color: #27ae60;"><?php echo intval($present_count); ?></p>
            </div>
            <div class="ihd-attendance-summary-card">
                <h4>Absent</h4>
                <p style="color: #e74c3c;"><?php echo intval($absent_count); ?></p>
            </div>
        </div>

        <!-- Export Button -->
        <?php if (!empty($attendance_data)): ?>
            <button class="ihd-export-attendance-btn" id="ihd-export-attendance-btn">
                📥 Export to Excel
            </button>
        <?php endif; ?>

        <!-- Attendance Table -->
        <?php if (!empty($attendance_data)): ?>
            <div class="table-container">
                <table class="ihd-manager-attendance-table" style="font-size:8pt;">
                    <thead>
                        <tr>
                            <th style="width:110px;">Date</th>
                            <th style="width:110px;">Trainer</th>
                            <th style="width:110px;">Time</th>
                            <th style="width:110px;">Status</th>
                            <th style="width:110px;">IP Address</th>
                            <th style="width:240px;">Location Info</th>
                            <th style="width:110px;">Marked At</th>
                            <th style="width:110px;">Login Time</th>
                            <th style="width:110px;">Logout Time</th>
                            <th style="width:110px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendance_data as $record): 
                            $trainer_name = trim(get_user_meta($record->trainer_id, 'first_name', true) . ' ' . get_user_meta($record->trainer_id, 'last_name', true));
                            $trainer_name = $trainer_name ?: $record->display_name ?: $record->user_login;
                            $login_time = date('g:i A', strtotime($record->attendance_time));
                            $logout_time = $record->logout_time ? date('g:i A', strtotime($record->logout_time)) : 'Pending';
                            $status = $record->logout_time ? 'Completed' : 'Logged In';
                            $status_class = $record->logout_time ? 'badge-present' : 'badge-absent';	
                        ?>
                        <tr>
                            <td><strong><?php echo date('M j, Y', strtotime($record->attendance_date)); ?></strong></td>
                            <td>
                                <strong><?php echo esc_html($trainer_name); ?></strong>
                                <br><small style="color: #7f8c8d;">ID: <?php echo esc_html($record->trainer_id); ?></small>
                            </td>
                            <td><?php echo date('g:i A', strtotime($record->attendance_time)); ?></td>
                            <td>
                                <span class="attendance-badge <?php echo $status_class; ?>">
                                    <?php echo ucfirst($record->location_status); ?>
                                </span>
                            </td>
                            <td><code><?php echo esc_html($record->ip_address); ?></code></td>
                            <td><?php echo esc_html($record->notes ?: 'Office premises'); ?></td>
                            <td><?php echo date('M j, g:i A', strtotime($record->created_at)); ?></td>
                            <td><?php echo $login_time; ?></td>
                            <td><?php echo $logout_time; ?></td>
                            <td><span class="attendance-badge <?php echo $status_class; ?>"><?php echo $status; ?></span></td>
                            
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 8%; color: #7f8c8d;">
                <p>📭 No attendance records found for the selected criteria.</p>
                <p style="margin-top: 2%; font-size: 0.9em;">
                    Date Range: <?php echo date('M j, Y', strtotime($start_date)); ?> to <?php echo date('M j, Y', strtotime($end_date)); ?>
                    <?php if ($trainer_filter > 0): ?>
                        <br>Trainer: 
                        <?php 
                        $trainer = get_user_by('id', $trainer_filter);
                        echo $trainer ? esc_html(trim($trainer->first_name . ' ' . $trainer->last_name) ?: $trainer->user_login) : 'Unknown';
                        ?>
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const exportButton = document.getElementById('ihd-export-attendance-btn');

        if (exportButton) {
            exportButton.addEventListener('click', function() {
                // Get the table
                const table = document.querySelector('.ihd-manager-attendance-table');
                if (!table) {
                    alert('No attendance data found to export.');
                    return;
                }

                // Create CSV content with updated columns
                let csvContent = "Date,Trainer Name,Trainer ID,Login Time,Logout Time,Status,IP Address,Location Info\n";

                // Get all table rows except the header
                const rows = table.querySelectorAll('tbody tr');

                rows.forEach(row => {
                    const cells = row.querySelectorAll('td');

                    if (cells.length >= 7) {
                        // Extract data based on the new table structure
                        const date = cells[0].textContent.trim().replace(/"/g, '""');

                        // Trainer info (cells[1])
                        const trainerName = cells[1].querySelector('strong') ? 
                            cells[1].querySelector('strong').textContent.trim().replace(/"/g, '""') : 
                            cells[1].textContent.trim().replace(/"/g, '""');
                        const trainerId = cells[1].querySelector('small') ? 
                            cells[1].querySelector('small').textContent.replace('ID:', '').trim().replace(/"/g, '""') : '';

                        // Login Time (cells[2])
                        const loginTime = cells[2].textContent.trim().replace(/"/g, '""');

                        // Logout Time (cells[3])
                        let logoutTime = cells[8].textContent.trim().replace(/"/g, '""');
                        // Handle the logout time extraction from span
                        if (logoutTime.includes('Pending')) {
                            logoutTime = 'Pending';
                        } else {
                            const logoutSpan = cells[8].querySelector('.logout-time');
                            if (logoutSpan) {
                                logoutTime = logoutSpan.textContent.trim().replace(/"/g, '""');
                            }
                        }

                        // Status (cells[4])
                        const status = cells[3].textContent.trim().replace(/"/g, '""');

                        // IP Address (cells[5])
                        const ipAddress = cells[4].textContent.trim().replace(/"/g, '""');

                        // Location Info (cells[6])
                        const locationInfo = cells[5].textContent.trim().replace(/"/g, '""');

                        const rowData = [
                            `"${date}"`,
                            `"${trainerName}"`,
                            `"${trainerId}"`,
                            `"${loginTime}"`,
                            `"${logoutTime}"`,
                            `"${status}"`,
                            `"${ipAddress}"`,
                            `"${locationInfo}"`
                        ];
                        csvContent += rowData.join(',') + '\n';
                    }
                });

                // Create and download file
                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const link = document.createElement('a');

                // Get date range for filename
                const startDate = document.getElementById('manager_attendance_start')?.value || 'start';
                const endDate = document.getElementById('manager_attendance_end')?.value || 'end';
                const trainerFilter = document.getElementById('trainer_filter')?.value || 'all';

                let filename = `trainer-attendance-${startDate}-to-${endDate}`;
                if (trainerFilter !== '0') {
                    const trainerSelect = document.getElementById('trainer_filter');
                    const trainerName = trainerSelect?.options[trainerSelect.selectedIndex]?.text || 'trainer';
                    filename += `-${trainerName.replace(/\s+/g, '-')}`;
                }
                filename += '.csv';

                // Create download link
                const url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', filename);
                link.style.display = 'none';

                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);
            });
        }

        // Add this function to handle the export button visibility and functionality
        function setupAttendanceExport() {
            const exportButton = document.getElementById('ihd-export-attendance-btn');
            const table = document.querySelector('.ihd-manager-attendance-table tbody');

            if (exportButton && table) {
                const hasData = table.querySelector('tr');
                if (!hasData) {
                    exportButton.style.display = 'none';
                } else {
                    exportButton.style.display = 'inline-block';
                }
            }
        }

        // Call setup when page loads
        setupAttendanceExport();
    });
    </script>
    <?php
    return ob_get_clean();
}
/* ---------------- UPDATED Manager Dashboard Shortcode ---------------- */
function ihd_manager_dashboard_shortcode() {
    if (!is_user_logged_in()) return '<p style="text-align: center; padding: 20px; color: #e74c3c; font-size: 1.2em;">Access denied. Only Manager can access this dashboard.</p>';
    
    $user = wp_get_current_user();
    if (!current_user_can('view_manager')) return 'Access denied.';

    // Check if user has delete capabilities
    $can_delete = current_user_can('delete_students');

    $messages = array();
	// Handle attendance report filter
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['generate_attendance_report'])) {
        // The attendance report filtering is handled in the ihd_manager_attendance_report() function
        // No additional processing needed here as it uses the GET parameters directly
        $messages[] = '<div class="updated">📊 Attendance report generated for the selected date range.</div>';
    }
    // Handle student deletion - only for users with delete capabilities
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_delete_student'])) {
        if (!$can_delete) {
            $messages[] = '<div class="error">You do not have permission to delete students.</div>';
        } elseif (!isset($_POST['ihd_delete_student_nonce']) || !wp_verify_nonce($_POST['ihd_delete_student_nonce'],'ihd_delete_student_action')) {
            $messages[] = '<div class="error">Invalid request.</div>';
        } else {
            $student_id = intval($_POST['student_id']);
            $status = get_post_meta($student_id,'status',true) ?: 'active';
            
            if ($status === 'completed') {
                $messages[] = '<div class="error">Cannot delete completed student (certificate preserved).</div>';
            } else {
                $deleted = wp_delete_post($student_id,true);
                if ($deleted) {
                    $messages[] = '<div class="updated">Student deleted successfully.</div>';
                } else {
                    $messages[] = '<div class="error">Student deletion failed.</div>';
                }
            }
        }
    }

    // Handle assign student to trainer
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_assign_student'])) {
        if (!isset($_POST['ihd_assign_student_nonce']) || !wp_verify_nonce($_POST['ihd_assign_student_nonce'], 'ihd_assign_student_action')) {
            $messages[] = '<div class="error">Invalid request.</div>';
        } else {
            $student_id = intval($_POST['student_id']);
            $trainer_id = intval($_POST['trainer_id']);
            $completion = intval($_POST['completion'] ?? 0);

            if ($student_id && $trainer_id) {
                update_post_meta($student_id, 'trainer_user_id', $trainer_id);
                update_post_meta($student_id, 'status', 'active');
                update_post_meta($student_id, 'completion', $completion);
                update_post_meta($student_id, 'assigned_date', date('Y-m-d H:i:s'));
                update_post_meta($student_id, 'assigned_by', $user->ID);
                
                $messages[] = '<div class="updated">Student assigned to trainer successfully.</div>';
            } else {
                $messages[] = '<div class="error">Invalid student or trainer.</div>';
            }
        }
    }

    // Handle return student to finance
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_return_to_finance'])) {
        if (!isset($_POST['ihd_return_to_finance_nonce']) || !wp_verify_nonce($_POST['ihd_return_to_finance_nonce'], 'ihd_return_to_finance_action')) {
            $messages[] = '<div class="error">Invalid request.</div>';
        } else {
            $student_id = intval($_POST['student_id']);
            $return_reason = sanitize_text_field($_POST['return_reason'] ?? 'Schedule not available');
            
            if ($student_id) {
                update_post_meta($student_id, 'status', 'unassigned');
                update_post_meta($student_id, 'returned_to_finance_date', date('Y-m-d H:i:s'));
                update_post_meta($student_id, 'returned_by', $user->ID);
                update_post_meta($student_id, 'return_reason', $return_reason);
                $messages[] = '<div class="updated">Student returned to Finance. Reason: ' . esc_html($return_reason) . '</div>';
            } else {
                $messages[] = '<div class="error">Invalid student.</div>';
            }
        }
    }

    // Handle edit student details
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ihd_edit_student_details'])) {
        if (!isset($_POST['ihd_edit_student_details_nonce']) || !wp_verify_nonce($_POST['ihd_edit_student_details_nonce'], 'ihd_edit_student_details_action')) {
            $messages[] = '<div class="error">Invalid request.</div>';
        } else {
            $student_id = intval($_POST['student_id']);
            $student_name = sanitize_text_field($_POST['student_name'] ?? '');
            $phone = sanitize_text_field($_POST['phone'] ?? '');
            $timing = sanitize_text_field($_POST['timing'] ?? '');
            $schedule_type = sanitize_text_field($_POST['schedule_type'] ?? 'weekdays');
            $training_mode = sanitize_text_field($_POST['training_mode'] ?? 'offline');
            $start_date = sanitize_text_field($_POST['start_date'] ?? '');
            
            if ($student_id && $student_name) {
                // Update post title
                wp_update_post(array(
                    'ID' => $student_id,
                    'post_title' => $student_name
                ));
                
                // Update meta fields
                update_post_meta($student_id, 'phone', $phone);
                update_post_meta($student_id, 'timing', $timing);
                update_post_meta($student_id, 'schedule_type', $schedule_type);
                update_post_meta($student_id, 'training_mode', $training_mode);
                update_post_meta($student_id, 'start_date', $start_date);
                
                $messages[] = '<div class="updated">Student details updated successfully.</div>';
            } else {
                $messages[] = '<div class="error">Invalid student data.</div>';
            }
        }
    }

    // Check if trainer selected
    $trainer_id = isset($_GET['trainer_id']) ? intval($_GET['trainer_id']) : 0;

    // Get forwarded students (status = forwarded)
    $forwarded_students = get_posts(array(
        'post_type' => 'student',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => 'status',
                'value' => 'forwarded',
                'compare' => '='
            )
        )
    ));

    // Determine active tab from URL parameter
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'trainers-overview';
    
    ob_start(); ?>
    
    <style>
    /* Manager Dashboard Traditional Responsive Styles */
    .ihd-manager-dashboard {
        max-width: 100%;
        margin: 0 auto;
        padding: 20px 15px;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: #f5f5f5;
        min-height: 100vh;
        box-sizing: border-box;
        font-size: 14px;
    }

    .ihd-manager-dashboard h2 {
        text-align: center;
        margin-bottom: 25px;
        color: #2c3e50;
        font-size: 24px;
        font-weight: 600;
        padding-bottom: 15px;
        border-bottom: 2px solid #3498db;
    }

    .ihd-manager-dashboard h3 {
        margin: 25px 0 15px 0;
        color: #34495e;
        font-size: 18px;
        font-weight: 600;
        padding-bottom: 10px;
        border-bottom: 1px solid #bdc3c7;
    }

    /* Messages */
    .ihd-manager-dashboard .notice-info {
        background: #e6f3ff;
        border: 1px solid #b3d9ff;
        border-radius: 4px;
        padding: 12px 15px;
        margin-bottom: 15px;
        color: #004080;
        font-size: 13px;
        border-left: 4px solid #3399ff;
    }

    .ihd-manager-dashboard .error {
        background: #ffe6e6;
        border: 1px solid #ffcccc;
        border-radius: 4px;
        padding: 12px 15px;
        margin-bottom: 15px;
        color: #cc0000;
        font-size: 13px;
        border-left: 4px solid #ff3333;
    }

    .ihd-manager-dashboard .updated {
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
    .ihd-manager-tabs {
        display: flex;
        margin-bottom: 20px;
        border-radius: 6px 6px 0 0;
        overflow: hidden;
        background: #fff;
        border: 1px solid #ddd;
        border-bottom: none;
    }

    .ihd-manager-tab {
        flex: 1;
        padding: 12px 15px;
        cursor: pointer;
        text-align: center;
        background: #ecf0f1;
        transition: all 0.2s ease;
        border: none;
        font-weight: 500;
        color: #7f8c8d;
        font-size: 13px;
        border-right: 1px solid #ddd;
    }

    .ihd-manager-tab:last-child {
        border-right: none;
    }

    .ihd-manager-tab.ihd-active {
        background: #3498db;
        color: #fff;
    }

    .ihd-manager-tab:hover:not(.ihd-active) {
        background: #d5dbdb;
    }

    /* Sections */
    .ihd-manager-section {
        display: none;
        background: #fff;
        padding: 20px;
        border-radius: 0 0 6px 6px;
        border: 1px solid #ddd;
        border-top: none;
    }

    .ihd-manager-section.active {
        display: block;
    }

    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin: 20px 0;
    }

    .stats-card {
        background: white;
        padding: 20px;
        border-radius: 6px;
        border: 1px solid #ddd;
        border-left: 4px solid #3498db;
        text-align: center;
        transition: transform 0.2s ease;
    }

    .stats-card:hover {
        transform: translateY(-2px);
    }

    .stats-card h4 {
        margin: 0 0 10px 0;
        color: #7f8c8d;
        font-size: 13px;
        font-weight: 600;
    }

    .stats-card p {
        font-size: 24px;
        font-weight: 700;
        margin: 0;
        color: #3498db;
    }

    /* Traditional Tables */
    .ihd-manager-table {
        width: 100%;
        border-collapse: collapse;
        margin: 15px 0;
        background: #fff;
        border: 1px solid #ddd;
        font-size: 12px;
        table-layout: fixed;
    }

    .ihd-manager-table thead {
        background: #2c3e50;
    }

    .ihd-manager-table th {
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

    .ihd-manager-table th:last-child {
        border-right: none;
    }

    .ihd-manager-table td {
        padding: 8px;
        border-bottom: 1px solid #ecf0f1;
        vertical-align: top;
        border-right: 1px solid #ecf0f1;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }

    .ihd-manager-table td:last-child {
        border-right: none;
    }

    .ihd-manager-table tbody tr:hover {
        background: #f8f9fa;
    }

    .ihd-manager-table tbody tr:nth-child(even) {
        background: #fafbfc;
    }

    /* Badges */
    .weekdays-badge {
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

    .weekends-badge {
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

    .status-badge {
        padding: 4px 8px;
        border-radius: 3px;
        font-size: 11px;
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
        background: #e67e22;
        color: white;
    }

    .status-hold {
        background: #e74c3c;
        color: white;
    }

    .mode-badge {
        padding: 3px 6px;
        border-radius: 3px;
        font-size: 10px;
        font-weight: 600;
        display: inline-block;
        text-align: center;
    }

    .mode-online {
        background: #3498db;
        color: white;
    }

    .mode-offline {
        background: #7f8c8d;
        color: white;
    }

    /* Buttons */
    .button {
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

    .button:hover {
        background: #2980b9;
        border-color: #2471a3;
        color: white;
        text-decoration: none;
    }

    .assign-btn {
        background: #27ae60;
        color: white;
        padding: 6px 12px;
        border: 1px solid #229954;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-block;
        text-align: center;
        min-width: 80px;
        height: 28px;
        box-sizing: border-box;
    }

    .assign-btn:hover {
        background: #229954;
        border-color: #1e8449;
    }

    .return-btn {
        background: #9b59b6;
        color: white;
        padding: 6px 12px;
        border: 1px solid #8e44ad;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-block;
        text-align: center;
        min-width: 80px;
        height: 28px;
        box-sizing: border-box;
    }

    .return-btn:hover {
        background: #8e44ad;
        border-color: #7d3c98;
    }

    .delete-btn {
        background: #e74c3c;
        color: white;
        padding: 6px 12px;
        border: 1px solid #c0392b;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-block;
        text-align: center;
        min-width: 70px;
        height: 28px;
        box-sizing: border-box;
    }

    .delete-btn:hover {
        background: #c0392b;
        border-color: #a93226;
    }

    /* Back button */
    .back-button {
        display: inline-block;
        margin: 20px 0;
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

    .back-button:hover {
        background: #7f8c8d;
        border-color: #6c7a7d;
        color: white;
        text-decoration: none;
    }

    /* Forms */
    .assign-form {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 4px;
        border: 1px solid #e9ecef;
        margin-top: 10px;
    }

    .assign-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 10px;
        margin-bottom: 10px;
    }

    .assign-form select,
    .assign-form input {
        padding: 6px 8px;
        border: 1px solid #ced4da;
        border-radius: 4px;
        font-size: 12px;
        width: 100%;
        box-sizing: border-box;
        height: 32px;
    }

    /* Edit Form */
    .edit-form {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 4px;
        border: 1px solid #e9ecef;
        margin-top: 10px;
    }

    .edit-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 8px;
        margin-bottom: 10px;
    }

    .edit-form input,
    .edit-form select {
        padding: 6px 8px;
        border: 1px solid #ced4da;
        border-radius: 4px;
        font-size: 12px;
        width: 100%;
        box-sizing: border-box;
        height: 32px;
    }

    /* Return Form */
    .return-form {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 4px;
        border: 1px solid #e9ecef;
        margin-top: 10px;
    }

    .return-form select {
        width: 100%;
        padding: 6px 8px;
        border: 1px solid #ced4da;
        border-radius: 4px;
        font-size: 12px;
        height: 32px;
        box-sizing: border-box;
        margin-bottom: 10px;
    }

    /* Progress Bar */
    .progress-container {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .progress-text {
        color: #3498db;
        font-weight: bold;
        font-size: 11px;
        min-width: 30px;
    }

    .progress-bar {
        width: 60px;
        height: 6px;
        background: #ecf0f1;
        border-radius: 3px;
        overflow: hidden;
    }

    .progress-fill {
        height: 100%;
        background: #3498db;
        border-radius: 3px;
        transition: width 0.3s ease;
    }

    /* Table Container */
    .table-container {
        overflow-x: auto;
        border-radius: 6px;
        margin: 15px 0;
        -webkit-overflow-scrolling: touch;
        border: 1px solid #ddd;
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
        .ihd-manager-table {
            font-size: 11px;
        }
        
        .ihd-manager-table th,
        .ihd-manager-table td {
            padding: 6px 4px;
        }
    }

    @media (max-width: 768px) {
        .ihd-manager-dashboard {
            padding: 15px 10px;
            font-size: 13px;
        }
        
        .ihd-manager-dashboard h2 {
            font-size: 20px;
            margin-bottom: 20px;
        }
        
        .ihd-manager-dashboard h3 {
            font-size: 16px;
            margin: 20px 0 12px 0;
        }
        
        .ihd-manager-tabs {
            flex-direction: column;
        }
        
        .ihd-manager-tab {
            flex: none;
            padding: 12px 15px;
            font-size: 13px;
            border-right: none;
            border-bottom: 1px solid #ddd;
        }
        
        .ihd-manager-tab:last-child {
            border-bottom: none;
        }
        
        .ihd-manager-section {
            padding: 15px;
        }
        
        .stats-grid {
            grid-template-columns: 1fr;
            gap: 10px;
        }
        
        .stats-card {
            padding: 15px;
        }
        
        .stats-card p {
            font-size: 20px;
        }
        
        .ihd-manager-table {
            font-size: 11px;
            min-width: 800px;
        }
        
        .ihd-manager-table th,
        .ihd-manager-table td {
            padding: 8px 6px;
            white-space: nowrap;
        }
        
        .button {
            padding: 10px 12px;
            min-width: 120px;
            font-size: 12px;
            height: 36px;
        }
        
        .assign-btn,
        .return-btn,
        .delete-btn {
            padding: 8px 12px;
            min-width: 80px;
            font-size: 11px;
            height: 32px;
        }
        
        .assign-form-grid,
        .edit-form-grid {
            grid-template-columns: 1fr;
        }
        
        .back-button {
            padding: 12px 20px;
            font-size: 13px;
        }
    }

    @media (max-width: 480px) {
        .ihd-manager-dashboard {
            padding: 10px 8px;
            font-size: 12px;
        }
        
        .ihd-manager-dashboard h2 {
            font-size: 18px;
        }
        
        .ihd-manager-dashboard h3 {
            font-size: 15px;
        }
        
        .ihd-manager-section {
            padding: 12px;
        }
        
        .stats-card {
            padding: 12px;
        }
        
        .stats-card p {
            font-size: 18px;
        }
        
        .ihd-manager-table {
            font-size: 10px;
        }
        
        .ihd-manager-table th,
        .ihd-manager-table td {
            padding: 6px 4px;
        }
        
        .status-badge {
            font-size: 10px;
            padding: 3px 6px;
            min-width: 50px;
        }
        
        .mode-badge {
            font-size: 9px;
            padding: 2px 4px;
        }
        
        .weekdays-badge,
        .weekends-badge {
            font-size: 10px;
            padding: 3px 6px;
            min-width: 60px;
        }
        
        .progress-bar {
            width: 50px;
        }
        
        .progress-text {
            font-size: 10px;
        }
    }

    /* Print Styles */
    @media print {
        .ihd-manager-dashboard {
            background: white;
            padding: 0;
        }
        
        .ihd-manager-container, .ihd-manager-content {
            box-shadow: none;
            border: 1px solid #000;
        }
        
        .button, .back-button {
            display: none;
        }
        
        .ihd-manager-table {
            break-inside: avoid;
        }
    }

    /* Animation */
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    .ihd-manager-section {
        animation: fadeIn 0.3s ease;
    }

    /* Utility Classes */
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .text-left { text-align: left; }
    .font-bold { font-weight: bold; }
    .font-normal { font-weight: normal; }
    .text-sm { font-size: 11px; }
    .text-xs { font-size: 10px; }

    /* Form Labels */
    .form-label {
        display: block;
        margin-bottom: 5px;
        font-weight: 600;
        color: #495057;
        font-size: 12px;
    }

    /* Section Headers with Colors */
    .section-header-new {
        color: #e67e22;
        border-bottom: 2px solid #e67e22;
        padding-bottom: 10px;
    }

    .section-header-active {
        color: #e67e22;
        border-bottom: 2px solid #e67e22;
        padding-bottom: 10px;
    }

    .section-header-completed {
        color: #27ae60;
        border-bottom: 2px solid #27ae60;
        padding-bottom: 10px;
    }
    </style>

    <div class="ihd-manager-dashboard">
        <h2>📊 Manager Dashboard</h2>
        
        <?php if (!$can_delete): ?>
            <div class="notice notice-info">
                <p>👀 You have read-only access to this dashboard.</p>
            </div>
        <?php endif; ?>
        
        <?php foreach ($messages as $m) echo $m; ?>

        <!-- Tabs -->
        <div class="ihd-manager-tabs" id="ihdManagerTabs">
            <div class="ihd-manager-tab <?php echo $active_tab === 'new-students' ? 'ihd-active' : ''; ?>" data-target="new-students">🆕 New Students</div>
            <div class="ihd-manager-tab <?php echo $active_tab === 'trainers-overview' ? 'ihd-active' : ''; ?>" data-target="trainers-overview">👨‍🏫 Trainers</div>
            <div class="ihd-manager-tab <?php echo $active_tab === 'attendance-report' ? 'ihd-active' : ''; ?>" data-target="attendance-report">📊 Attendance Report</div>
        </div>

        <!-- New Students Tab -->
        <div id="new-students" class="ihd-manager-section <?php echo $active_tab === 'new-students' ? 'active' : ''; ?>">
            <h3>🆕 New Students from Finance</h3>
            
            <?php if(!empty($forwarded_students)) : ?>
                <div class="table-container">
                    <table class="ihd-manager-table">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Phone</th>
                                <th>Course</th>
                                <th>Timing</th>
                                <th>Schedule</th>
                                <th>Mode</th>
                                <th>Start Date</th>
                                <th>Fee Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($forwarded_students as $s) :
                                $course_id = get_post_meta($s->ID,'course_id',true);
                                $course_name = get_term($course_id,'module')->name ?? '';
                                $phone = get_post_meta($s->ID,'phone',true) ?: '';
                                $timing = get_post_meta($s->ID,'timing',true) ?: '';
                                $schedule_type = get_post_meta($s->ID,'schedule_type',true) ?: 'weekdays';
                                $training_mode = get_post_meta($s->ID,'training_mode',true) ?: 'offline';
                                $start_date = get_post_meta($s->ID,'start_date',true) ?: '';
                                $fee_status = get_post_meta($s->ID, 'fee_status', true) ?: 'pending';
                                $fee_status_class = 'status-badge status-' . $fee_status;
                                $mode_class = 'mode-badge mode-' . $training_mode;
                                
                                // Get trainers for this course
                                $trainers = get_users(array(
                                    'role' => 'trainer',
                                    'meta_query' => array(
                                        array(
                                            'key' => 'assigned_modules',
                                            'value' => $course_id,
                                            'compare' => 'LIKE'
                                        )
                                    )
                                ));
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($s->post_title); ?></strong></td>
                                <td><?php echo esc_html($phone); ?></td>
                                <td><?php echo esc_html($course_name); ?></td>
                                <td><?php echo esc_html($timing); ?></td>
                                <td>
                                    <?php if ($schedule_type === 'weekends'): ?>
                                        <span class="weekends-badge">Weekends</span>
                                    <?php else: ?>
                                        <span class="weekdays-badge">Weekdays</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="<?php echo $mode_class; ?>"><?php echo esc_html(ucfirst($training_mode)); ?></span></td>
                                <td><?php echo esc_html($start_date); ?></td>
                                <td><span class="<?php echo $fee_status_class; ?>"><?php echo esc_html(ucfirst($fee_status)); ?></span></td>
                                <td>
                                    <div class="assign-form">
                                        <form method="post">
                                            <?php wp_nonce_field('ihd_assign_student_action','ihd_assign_student_nonce'); ?>
                                            <input type="hidden" name="student_id" value="<?php echo $s->ID; ?>">
                                            <input type="hidden" name="tab" value="new-students">
                                            <div class="assign-form-grid">
                                                <div>
                                                    <label style="display: block; margin-bottom: 2%; font-weight: 600;">Assign to Trainer:</label>
                                                    <select name="trainer_id" required>
                                                        <option value="">Select Trainer</option>
                                                        <?php 
                                                        if (!empty($trainers)) {
                                                            foreach ($trainers as $trainer) {
                                                                $trainer_name = trim($trainer->first_name . ' ' . $trainer->last_name) ?: $trainer->user_login;
                                                                echo '<option value="' . $trainer->ID . '">' . esc_html($trainer_name) . '</option>';
                                                            }
                                                        } else {
                                                            echo '<option value="">No trainers for this course</option>';
                                                        }
                                                        ?>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label style="display: block; margin-bottom: 2%; font-weight: 600;">Initial Completion %:</label>
                                                    <input type="number" name="completion" min="0" max="100" value="0" required>
                                                </div>
                                            </div>
                                            <button type="submit" name="ihd_assign_student" class="assign-btn" style="width: 100%; margin-top: 3%;">
                                                ✅ Assign to Trainer
                                            </button>
                                        </form>
                                    </div>
                                    
                                    <!-- Edit Student Details Form -->
                                    <div class="edit-form">
                                        <h4 style="margin-bottom: 2%; color: #2c3e50;">✏️ Edit Student Details</h4>
                                        <form method="post">
                                            <?php wp_nonce_field('ihd_edit_student_details_action','ihd_edit_student_details_nonce'); ?>
                                            <input type="hidden" name="student_id" value="<?php echo $s->ID; ?>">
                                            <input type="hidden" name="tab" value="new-students">
                                            <div class="edit-form-grid">
                                                <input type="text" name="student_name" value="<?php echo esc_attr($s->post_title); ?>" placeholder="Student Name" required>
                                                <input type="tel" name="phone" value="<?php echo esc_attr($phone); ?>" placeholder="Phone Number">
                                                <input type="text" name="timing" value="<?php echo esc_attr($timing); ?>" placeholder="Timing" required>
                                                <select name="schedule_type" required>
                                                    <option value="weekdays" <?php selected($schedule_type, 'weekdays'); ?>>Weekdays</option>
                                                    <option value="weekends" <?php selected($schedule_type, 'weekends'); ?>>Weekends</option>
                                                </select>
                                                <select name="training_mode" required>
                                                    <option value="offline" <?php selected($training_mode, 'offline'); ?>>Offline</option>
                                                    <option value="online" <?php selected($training_mode, 'online'); ?>>Online</option>
                                                </select>
                                                <input type="date" name="start_date" value="<?php echo esc_attr($start_date); ?>" required>
                                            </div>
                                            <button type="submit" name="ihd_edit_student_details" class="button" style="width: 100%; margin-top: 2%;">
                                                💾 Save Changes
                                            </button>
                                        </form>
                                    </div>
                                    
                                    <!-- Return to Finance Form -->
                                    <div class="return-form">
                                        <h4 style="margin-bottom: 2%; color: #e67e22;">↩️ Return to Finance</h4>
                                        <form method="post">
                                            <?php wp_nonce_field('ihd_return_to_finance_action','ihd_return_to_finance_nonce'); ?>
                                            <input type="hidden" name="student_id" value="<?php echo $s->ID; ?>">
                                            <input type="hidden" name="tab" value="new-students">
                                            <div style="margin-bottom: 3%;">
                                                <label style="display: block; margin-bottom: 2%; font-weight: 600;">Return Reason:</label>
                                                <select name="return_reason" required style="width: 100%; padding: 3%; border: 2px solid #dee2e6; border-radius: 6px;">
                                                    <option value="Schedule not available">Schedule not available</option>
                                                    <option value="No suitable trainer">No suitable trainer</option>
                                                    <option value="Student request">Student request</option>
                                                    <option value="Other">Other reason</option>
                                                </select>
                                            </div>
                                            <button type="submit" name="ihd_return_to_finance" class="return-btn" style="width: 100%;">
                                                ↩️ Return to Finance
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 10%; background: white; border-radius: 12px; color: #7f8c8d;">
                    <p style="font-size: 1.2em; margin: 0;">No new students waiting for assignment.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Trainers Overview Tab -->
        <div id="trainers-overview" class="ihd-manager-section <?php echo $active_tab === 'trainers-overview' ? 'active' : ''; ?>">
            <?php if ($trainer_id <= 0): ?>
                <!-- Trainers List with Statistics -->
                <h3>👨‍🏫 All Trainers Overview</h3>
                <?php
                $trainers = get_users(array('role' => 'trainer'));
                if (!empty($trainers)): 
                ?>
                    <div class="table-container">
                        <table class="ihd-manager-table">
                            <thead>
                                <tr>
                                    <th>Trainer Name</th>
                                    <th>Email</th>
                                    <th>Assigned Modules</th>
                                    <th>Active</th>
                                    <th>Completed</th>
                                    <th>Total</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($trainers as $t): 
                                    $name = trim($t->first_name . ' ' . $t->last_name) ?: $t->user_login;
                                    $trainer_id_val = $t->ID;
                                    
                                    // Get assigned modules
                                    $modules_ids = get_user_meta($trainer_id_val, 'assigned_modules', true) ?: array();
                                    $module_names = array();
                                    foreach ((array)$modules_ids as $m) {
                                        $term = get_term($m, 'module');
                                        if ($term && !is_wp_error($term)) {
                                            $module_names[] = $term->name;
                                        }
                                    }
                                    
                                    // Count active students
                                    $active_students_count = new WP_Query(array(
                                        'post_type' => 'student',
                                        'meta_query' => array(
                                            array('key' => 'trainer_user_id', 'value' => $trainer_id_val, 'compare' => '='),
                                            array('key' => 'status', 'value' => 'active', 'compare' => '=')
                                        ),
                                        'posts_per_page' => -1,
                                        'fields' => 'ids'
                                    ));
                                    
                                    // Count completed students
                                    $completed_students_count = new WP_Query(array(
                                        'post_type' => 'student',
                                        'meta_query' => array(
                                            array('key' => 'trainer_user_id', 'value' => $trainer_id_val, 'compare' => '='),
                                            array('key' => 'status', 'value' => 'completed', 'compare' => '=')
                                        ),
                                        'posts_per_page' => -1,
                                        'fields' => 'ids'
                                    ));
                                    
                                    $total_students = $active_students_count->found_posts + $completed_students_count->found_posts;
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html($name); ?></strong></td>
                                    <td><?php echo esc_html($t->user_email); ?></td>
                                    <td>
                                        <?php if (!empty($module_names)): ?>
                                            <span style="color:#2c3e50;"><?php echo esc_html(implode(', ', $module_names)); ?></span>
                                        <?php else: ?>
                                            <span style="color:#bdc3c7; font-style: italic;">No modules assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="color:#e67e22;font-weight:bold; font-size: 1.1em;"><?php echo intval($active_students_count->found_posts); ?></span>
                                    </td>
                                    <td>
                                        <span style="color:#27ae60;font-weight:bold; font-size: 1.1em;"><?php echo intval($completed_students_count->found_posts); ?></span>
                                    </td>
                                    <td>
                                        <span style="color:#3498db;font-weight:bold; font-size: 1.1em;"><?php echo intval($total_students); ?></span>
                                    </td>
                                    <td>
                                        <a href="?tab=trainers-overview&trainer_id=<?php echo $trainer_id_val; ?>" class="button">View Students</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 10%; background: white; border-radius: 12px; color: #7f8c8d;">
                        <p style="font-size: 1.2em; margin: 0;">No trainers found in the system.</p>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <?php 
                $trainer = get_user_by('id',$trainer_id);
                if (!$trainer) {
                    echo '<div class="error" style="text-align: center; padding: 5%;">Invalid trainer selected.</div>';
                } else {
                    $trainer_name = trim($trainer->first_name.' '.$trainer->last_name) ?: $trainer->user_login;
                    echo '<h3>👥 Students of ' . esc_html($trainer_name) . '</h3>';
                    
                    // Get trainer statistics
                    $active_students_count = new WP_Query(array(
                        'post_type' => 'student',
                        'meta_query' => array(
                            array('key' => 'trainer_user_id', 'value' => $trainer_id, 'compare' => '='),
                            array('key' => 'status', 'value' => 'active', 'compare' => '=')
                        ),
                        'posts_per_page' => -1,
                        'fields' => 'ids'
                    ));
                    
                    $completed_students_count = new WP_Query(array(
                        'post_type' => 'student',
                        'meta_query' => array(
                            array('key' => 'trainer_user_id', 'value' => $trainer_id, 'compare' => '='),
                            array('key' => 'status', 'value' => 'completed', 'compare' => '=')
                        ),
                        'posts_per_page' => -1,
                        'fields' => 'ids'
                    ));
                    
                    $total_students = $active_students_count->found_posts + $completed_students_count->found_posts;
                    ?>
                    
                    <div class="stats-grid">
                        <div class="stats-card">
                            <h4>📊 Total Students</h4>
                            <p style="color: #3498db;"><?php echo intval($total_students); ?></p>
                        </div>
                        <div class="stats-card" style="border-left-color: #e67e22;">
                            <h4>🎯 Active Students</h4>
                            <p style="color: #e67e22;"><?php echo intval($active_students_count->found_posts); ?></p>
                        </div>
                      
                        <div class="stats-card" style="border-left-color: #27ae60;">
                            <h4>✅ Completed Students</h4>
                            <p style="color: #27ae60;"><?php echo intval($completed_students_count->found_posts); ?></p>
                        </div>
                    </div>

                    <!-- Active Students Table -->
                    <h3 style="color: #e67e22; border-bottom: 2px solid #e67e22; padding-bottom: 2%;">
                        🎯 Active Students (<?php echo intval($active_students_count->found_posts); ?>)
                    </h3>
                    <?php
                    $active_students = get_posts(array(
                        'post_type'=>'student',
                        'posts_per_page'=>-1,
                        'meta_query'=>array(
                            array('key'=>'trainer_user_id','value'=>$trainer_id,'compare'=>'='),
                            array('key'=>'status','value'=>'active','compare'=>'=')
                        )
                    ));
                    
                    // Sort active students: Weekdays first, then Weekends, then by timing
                    usort($active_students, function($a, $b) {
                        $schedule_a = get_post_meta($a->ID, 'schedule_type', true) ?: 'weekdays';
                        $schedule_b = get_post_meta($b->ID, 'schedule_type', true) ?: 'weekdays';
                        
                        $timing_a = get_post_meta($a->ID, 'timing', true) ?: '';
                        $timing_b = get_post_meta($b->ID, 'timing', true) ?: '';
                        
                        // First sort by schedule type (weekdays first)
                        if ($schedule_a !== $schedule_b) {
                            return $schedule_a === 'weekdays' ? -1 : 1;
                        }
                        
                        // Then sort by timing (convert to sortable format)
                        $time_a = ihd_convert_timing_to_sortable($timing_a);
                        $time_b = ihd_convert_timing_to_sortable($timing_b);
                        
                        return $time_a <=> $time_b;
                    });
                    ?>

                    <?php if(!empty($active_students)) : ?>
                        <div class="table-container">
                            <table class="ihd-manager-table">
                                <thead>
                                    <tr>
                                        <th>Student Name</th>
                                        <th>Phone</th>
                                        <th>Schedule</th>
                                        <th>Timing</th>
                                        <th>Course</th>
                                        <th>Start Date</th>
                                        <th>Fee Status</th>
                                        <th>Progress</th>
                                        <th>Mode</th>
                                        <?php if ($can_delete): ?><th>Actions</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($active_students as $s): 
                                        $course_name = get_term(get_post_meta($s->ID,'course_id',true),'module')->name ?? '';
                                        $completion = get_post_meta($s->ID,'completion',true) ?: 0;
                                        $phone = get_post_meta($s->ID,'phone',true) ?: '';
                                        $timing = get_post_meta($s->ID,'timing',true) ?: '';
                                        $start_date = get_post_meta($s->ID,'start_date',true) ?: '';
                                        $schedule_type = get_post_meta($s->ID,'schedule_type',true) ?: 'weekdays';
                                        $training_mode = get_post_meta($s->ID,'training_mode',true) ?: 'offline';
                                        $fee_status = get_post_meta($s->ID, 'fee_status', true) ?: 'pending';
                                        $fee_status_class = 'status-badge status-' . $fee_status;
                                        $mode_class = 'mode-badge mode-' . $training_mode;
                                    ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($s->post_title); ?></strong></td>
                                        <td><?php echo esc_html($phone); ?></td>
                                        <td>
                                            <?php if ($schedule_type === 'weekends'): ?>
                                                <span class="weekends-badge">Weekends</span>
                                            <?php else: ?>
                                                <span class="weekdays-badge">Weekdays</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html($timing); ?></td>
                                        <td><?php echo esc_html($course_name); ?></td>
                                        <td><?php echo esc_html($start_date); ?></td>
                                        <td><span class="<?php echo $fee_status_class; ?>"><?php echo esc_html(ucfirst($fee_status)); ?></span></td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <span style="color:#3498db;font-weight:bold; font-size: 1.1em;"><?php echo intval($completion); ?>%</span>
                                                <div style="width: 60px; height: 8px; background: #ecf0f1; border-radius: 4px; overflow: hidden;">
                                                    <div style="width: <?php echo intval($completion); ?>%; height: 100%; background: #3498db; border-radius: 4px;"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><span class="<?php echo $mode_class; ?>"><?php echo esc_html(ucfirst($training_mode)); ?></span></td>
                                        <?php if ($can_delete): ?>
                                        <td>
                                            <form method="post" onsubmit="return confirm('Are you sure you want to delete student <?php echo esc_js($s->post_title); ?>? This action cannot be undone.');">
                                                <?php wp_nonce_field('ihd_delete_student_action','ihd_delete_student_nonce'); ?>
                                                <input type="hidden" name="student_id" value="<?php echo $s->ID; ?>">
                                                <input type="hidden" name="tab" value="trainers-overview">
                                                <input type="hidden" name="trainer_id" value="<?php echo $trainer_id; ?>">
                                                <button type="submit" name="ihd_delete_student" class="delete-btn">🗑️ Delete</button>
                                            </form>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 8%; background: white; border-radius: 12px; color: #7f8c8d;">
                            <p style="font-size: 1.1em; margin: 0;">No active students found for this trainer.</p>
                        </div>
                    <?php endif; ?>

                    <!-- Completed Students Table -->
                    <h3 style="color: #27ae60; border-bottom: 2px solid #27ae60; padding-bottom: 2%; margin-top: 8%;">
                        ✅ Completed Students (<?php echo intval($completed_students_count->found_posts); ?>)
                    </h3>
                    <?php
                    $completed_students = get_posts(array(
                        'post_type'=>'student',
                        'posts_per_page'=>-1,
                        'meta_query'=>array(
                            array('key'=>'trainer_user_id','value'=>$trainer_id,'compare'=>'='),
                            array('key'=>'status','value'=>'completed','compare'=>'=')
                        )
                    ));
                    
                    // Sort completed students: Weekdays first, then Weekends, then by timing
                    usort($completed_students, function($a, $b) {
                        $schedule_a = get_post_meta($a->ID, 'schedule_type', true) ?: 'weekdays';
                        $schedule_b = get_post_meta($b->ID, 'schedule_type', true) ?: 'weekdays';
                        
                        $timing_a = get_post_meta($a->ID, 'timing', true) ?: '';
                        $timing_b = get_post_meta($b->ID, 'timing', true) ?: '';
                        
                        // First sort by schedule type (weekdays first)
                        if ($schedule_a !== $schedule_b) {
                            return $schedule_a === 'weekdays' ? -1 : 1;
                        }
                        
                        // Then sort by timing (convert to sortable format)
                        $time_a = ihd_convert_timing_to_sortable($timing_a);
                        $time_b = ihd_convert_timing_to_sortable($timing_b);
                        
                        return $time_a <=> $time_b;
                    });
                    ?>

                    <?php if(!empty($completed_students)) : ?>
                        <div class="table-container">
                            <table class="ihd-manager-table">
                                <thead>
                                    <tr>
                                        <th>Student Name</th>
                                        <th>Phone</th>
                                        <th>Schedule</th>
                                        <th>Timing</th>
                                        <th>Course</th>
                                        <th>Start Date</th>
                                        <th>Completion Date</th>
                                        <th>Mode</th>
                                        <th>Certificate ID</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($completed_students as $s): 
                                        $course_name = get_term(get_post_meta($s->ID,'course_id',true),'module')->name ?? '';
                                        $completion = get_post_meta($s->ID,'completion',true) ?: 0;
                                        $cert_id = get_post_meta($s->ID,'certificate_id',true) ?: 'Pending';
                                        $phone = get_post_meta($s->ID,'phone',true) ?: '';
                                        $timing = get_post_meta($s->ID,'timing',true) ?: '';
                                        $start_date = get_post_meta($s->ID,'start_date',true) ?: '';
                                        $completion_date = get_post_meta($s->ID,'completion_date',true) ?: '';
                                        $schedule_type = get_post_meta($s->ID,'schedule_type',true) ?: 'weekdays';
                                        $training_mode = get_post_meta($s->ID,'training_mode',true) ?: 'offline';
                                        $mode_class = 'mode-badge mode-' . $training_mode;
                                    ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($s->post_title); ?></strong></td>
                                        <td><?php echo esc_html($phone); ?></td>
                                        <td>
                                            <?php if ($schedule_type === 'weekends'): ?>
                                                <span class="weekends-badge">Weekends</span>
                                            <?php else: ?>
                                                <span class="weekdays-badge">Weekdays</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html($timing); ?></td>
                                        <td><?php echo esc_html($course_name); ?></td>
                                        <td><?php echo esc_html($start_date); ?></td>
                                        <td><?php echo esc_html($completion_date); ?></td>
                                        <td><span class="<?php echo $mode_class; ?>"><?php echo esc_html(ucfirst($training_mode)); ?></span></td>
                                        <td>
                                            <?php if($cert_id !== 'Pending'): ?>
                                                <span style="color:#27ae60;font-weight:bold; font-size: 1.1em;"><?php echo esc_html($cert_id); ?></span>
                                            <?php else: ?>
                                                <span style="color:#e67e22; font-style: italic;"><?php echo esc_html($cert_id); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 8%; background: white; border-radius: 12px; color: #7f8c8d;">
                            <p style="font-size: 1.1em; margin: 0;">No completed students found for this trainer.</p>
                        </div>
                    <?php endif; ?>

                    <a href="?tab=trainers-overview" class="back-button">← Back to All Trainers</a>
                <?php } ?>
            <?php endif; ?>
        </div>

        <!-- Attendance Report Tab -->
        <div id="attendance-report" class="ihd-manager-section <?php echo $active_tab === 'attendance-report' ? 'active' : ''; ?>">
            <?php echo ihd_manager_attendance_report(); ?>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Tab functionality
        const managerTabs = document.querySelectorAll('.ihd-manager-tab');

        managerTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                // Remove active class from all tabs
                document.querySelectorAll('.ihd-manager-tab').forEach(t => {
                    t.classList.remove('ihd-active');
                });

                // Add active class to clicked tab
                tab.classList.add('ihd-active');

                // Hide all sections
                document.querySelectorAll('.ihd-manager-section').forEach(sec => {
                    sec.classList.remove('active');
                });

                // Show target section
                const targetId = tab.getAttribute('data-target');
                const targetSection = document.getElementById(targetId);
                if (targetSection) {
                    targetSection.classList.add('active');
                }

                // Update URL parameter without page reload
                const url = new URL(window.location);
                url.searchParams.set('tab', targetId);
                window.history.pushState({}, '', url);
            });
        });

        // Add hidden tab field to attendance report form
        const attendanceForm = document.querySelector('#attendance-report form');
        if (attendanceForm) {
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'tab';
            hiddenInput.value = 'attendance-report';
            attendanceForm.appendChild(hiddenInput);
        }

        // Add tab parameter to all form submissions in the manager dashboard
        document.querySelectorAll('form').forEach(form => {
            // Skip if already has tab parameter
            if (form.querySelector('input[name="tab"]')) {
                return;
            }
            
            // Get current active tab
            const activeTab = document.querySelector('.ihd-manager-tab.ihd-active');
            if (activeTab) {
                const tabValue = activeTab.getAttribute('data-target');
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'tab';
                hiddenInput.value = tabValue;
                form.appendChild(hiddenInput);
            }
        });
    });
    </script>
    <?php
    return ob_get_clean();
}
?>