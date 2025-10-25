<?php

// Trainer Meeting Interface Shortcode - Screen Share Only
function ihd_trainer_meeting_shortcode() {
    if (!is_user_logged_in()) return 'Please login to access this page.';
    
    $user = wp_get_current_user();
    if (!in_array('trainer', $user->roles) && !current_user_can('administrator')) {
        return '<p style="text-align: center; padding: 20px; color: #e74c3c; font-size: 1.2em;">Access denied. Only Trainers can access this interface.</p>';
    }

    if (!isset($_GET['meeting_host'])) {
        return '<div class="error">Invalid meeting link. Missing meeting ID.</div>';
    }

    $meeting_id = sanitize_text_field($_GET['meeting_host']);
    $batch_id = sanitize_text_field($_GET['batch_id'] ?? '');
    
    global $wpdb;
    $batch_table = $wpdb->prefix . 'ihd_batch_sessions';
    
    error_log("IHD: Trainer meeting access attempt - Meeting: $meeting_id, Batch: $batch_id, Trainer: $user->ID");
    
    if (!empty($batch_id)) {
        $batch = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $batch_table WHERE meeting_id = %s AND batch_id = %s AND trainer_id = %d AND status = 'active'",
            $meeting_id, $batch_id, $user->ID
        ));
    } 
    
    if (empty($batch) && !empty($meeting_id)) {
        $batch = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $batch_table WHERE meeting_id = %s AND trainer_id = %d AND status = 'active'",
            $meeting_id, $user->ID
        ));
        
        if ($batch) $batch_id = $batch->batch_id;
    }
    
    if (empty($batch) && !empty($meeting_id)) {
        $batch = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $batch_table WHERE meeting_id = %s AND status = 'active'",
            $meeting_id
        ));
        
        if ($batch) {
            $batch_id = $batch->batch_id;
            if ($batch->trainer_id != $user->ID && !current_user_can('administrator')) {
                return '<div class="error">Access denied. This batch session belongs to another trainer.</div>';
            }
        }
    }
    
    if (!$batch) {
        return '<div class="error">Invalid batch session or access denied.</div>';
    }

    $batch_id = $batch->batch_id;
    
    $batch_students = get_posts(array(
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

    $course_name = get_term($batch->course_id, 'module')->name ?? '';

    ob_start();
    ?>
    
    <div class="ihd-video-conference" id="ihdVideoConference">
        <div class="ihd-conference-interface">
            <!-- Header -->
            <div class="ihd-video-header ihd-trainer-controls">
                <div class="ihd-meeting-info">
                    <h2>👨‍🏫 Screen Share Teaching Session - <?php echo esc_html($course_name); ?></h2>
                    <p>Meeting ID: <strong><?php echo esc_html($meeting_id); ?></strong> | Batch: <strong><?php echo esc_html($batch_id); ?></strong> | Students: <strong><?php echo count($batch_students); ?></strong></p>
                </div>
                <div class="ihd-meeting-controls">
                    <button class="ihd-control-btn" id="recordBtn" onclick="toggleRecording()">
                        ⏺️ Start Recording
                    </button>
                    <button class="ihd-control-btn" id="muteAllBtn" onclick="toggleMuteAll()">
                        🔇 Mute All
                    </button>
                    <button class="ihd-control-btn" onclick="toggleFullscreen()">
                        🖥️ Fullscreen
                    </button>
                </div>
            </div>

            <div class="ihd-video-container">
                <!-- Main Screen Share Area -->
                <div class="ihd-screen-share-container" id="screenShareContainer">
                    <div class="ihd-screen-placeholder" id="screenPlaceholder">
                        <div class="ihd-screen-placeholder-content">
                            <div class="ihd-screen-icon">🖥️</div>
                            <h3>Ready to Share Your Screen</h3>
                            <p>Click "Share Screen" below to start teaching</p>
                            <p class="ihd-screen-note">Your screen and audio will be shared with students</p>
                        </div>
                    </div>
                    <video id="screenShareVideo" autoplay playsinline style="display: none;"></video>
                    <div class="ihd-screen-overlay" id="screenOverlay" style="display: none;">
                        <div class="ihd-screen-info">
                            <span class="ihd-screen-indicator">🔴 LIVE</span>
                            <span class="ihd-screen-text">You are sharing your screen</span>
                        </div>
                        <div class="ihd-recording-indicator" id="recordingIndicator" style="display: none;">
                            ⏺️ RECORDING
                        </div>
                    </div>
                </div>

                <div class="ihd-sidebar">
                    <div class="ihd-chat-container">
                        <div class="ihd-chat-header">
                            <h3 style="margin: 0; color: #e67e22;">💬 Class Chat</h3>
                        </div>
                        <div class="ihd-chat-messages" id="chatMessages">
                        </div>
                        <div class="ihd-chat-input-container">
                            <input type="text" class="ihd-chat-input" id="chatInput" 
                                   placeholder="Type your message..." 
                                   onkeypress="handleChatInput(event)">
                        </div>
                    </div>

                    <div class="ihd-participants-container">
                        <div class="ihd-participants-header">
                            <h3 style="margin: 0; color: #e67e22;">👥 Participants (<?php echo count($batch_students); ?>)</h3>
                            <div class="ihd-participants-actions">
                                <button class="ihd-participant-action-btn" onclick="trainerConference.muteAllStudents()" title="Mute All Students">
                                    🔇 All
                                </button>
                                <button class="ihd-participant-action-btn" onclick="trainerConference.unmuteAllStudents()" title="Unmute All Students">
                                    🔊 All
                                </button>
                            </div>
                        </div>
                        <div class="ihd-participants-list" id="participantsList">
                            <div class="ihd-participant trainer">
                                <div class="ihd-participant-avatar">
                                    <?php echo strtoupper(substr($user->first_name, 0, 1)); ?>
                                </div>
                                <div class="ihd-participant-info">
                                    <div class="ihd-participant-name"><?php echo esc_html($user->first_name . ' ' . $user->last_name); ?></div>
                                    <div class="ihd-participant-role">Trainer (You)</div>
                                </div>
                                <div class="ihd-participant-status" id="trainerStatus">
                                    Online
                                </div>
                                <div class="ihd-participant-controls">
                                    <span class="ihd-audio-status">🎤</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Controls Bar -->
            <div class="ihd-controls-bar ihd-trainer-controls">
                <button class="ihd-main-control" id="screenShareBtn" onclick="toggleScreenShare()">
                    🖥️ Share Screen
                </button>
                <button class="ihd-main-control" id="audioBtn" onclick="toggleAudio()">
                    🎤 Mute Audio
                </button>
                <button class="ihd-main-control end-call" onclick="endClass()">
                    🏁 End Class
                </button>
            </div>
        </div>
    </div>

    <script>
    // Enhanced Trainer Screen Share Conference Class
    class TrainerScreenShareConference {
        constructor() {
            this.screenStream = null;
            this.isScreenSharing = false;
            this.isAudioEnabled = true;
            this.recordedChunks = [];
            this.mediaRecorder = null;
            this.isRecording = false;
            this.peer = null;
            this.connections = new Map();
            this.studentInfo = new Map();
            this.calls = new Map();
            this.dataConnections = new Map();
            this.incomingStudentCalls = new Map();
            this.studentAudios = new Map();
            this.audioContexts = new Map();
            this.otherStudents = new Map(); 
            this.mutedStudents = new Set(); // Track muted students
            this.isAllMuted = false; // Track if all students are muted
            this.config = {
                meetingId: '<?php echo esc_js($meeting_id); ?>',
                batchId: '<?php echo esc_js($batch_id); ?>',
                role: 'trainer',
                trainerName: '<?php echo esc_js($user->first_name . " " . $user->last_name); ?>'
            };
            this.initializeFullscreenListeners();
        }

        // NEW: Mute individual student
        muteStudent(studentId) {
            const studentInfo = this.studentInfo.get(studentId);
            if (!studentInfo) return;

            // Send mute command to student
            if (this.sendToStudent(studentId, {
                type: 'control',
                action: 'mute',
                sender: 'trainer'
            })) {
                this.mutedStudents.add(studentId);
                this.updateStudentMuteUI(studentId, true);
                this.showNotification(`Muted ${studentInfo.name}`, 'success');
                
                // Also mute any active audio call from this student
                if (this.studentAudios.has(studentId)) {
                    const audio = this.studentAudios.get(studentId);
                    audio.volume = 0;
                    audio.muted = true;
                }
            }
        }

        // NEW: Unmute individual student
        unmuteStudent(studentId) {
            const studentInfo = this.studentInfo.get(studentId);
            if (!studentInfo) return;

            // Send unmute command to student
            if (this.sendToStudent(studentId, {
                type: 'control',
                action: 'unmute',
                sender: 'trainer'
            })) {
                this.mutedStudents.delete(studentId);
                this.updateStudentMuteUI(studentId, false);
                this.showNotification(`Unmuted ${studentInfo.name}`, 'success');
                
                // Restore audio volume
                if (this.studentAudios.has(studentId)) {
                    const audio = this.studentAudios.get(studentId);
                    audio.volume = 0.8;
                    audio.muted = false;
                }
            }
        }

        // NEW: Mute all students
        muteAllStudents() {
            this.isAllMuted = true;
            this.mutedStudents.clear();

            // Send mute command to all students
            this.broadcastToAllStudents({
                type: 'control',
                action: 'mute',
                sender: 'trainer'
            });

            // Update all student UIs
            this.studentInfo.forEach((info, studentId) => {
                this.mutedStudents.add(studentId);
                this.updateStudentMuteUI(studentId, true);
                
                // Mute all student audios
                if (this.studentAudios.has(studentId)) {
                    const audio = this.studentAudios.get(studentId);
                    audio.volume = 0;
                    audio.muted = true;
                }
            });

            // Update mute all button
            this.updateMuteAllButton(true);
            this.showNotification('All students muted', 'success');
        }

        // NEW: Unmute all students
        unmuteAllStudents() {
            this.isAllMuted = false;

            // Send unmute command to all students
            this.broadcastToAllStudents({
                type: 'control',
                action: 'unmute',
                sender: 'trainer'
            });

            // Update all student UIs
            this.studentInfo.forEach((info, studentId) => {
                this.mutedStudents.delete(studentId);
                this.updateStudentMuteUI(studentId, false);
                
                // Unmute all student audios
                if (this.studentAudios.has(studentId)) {
                    const audio = this.studentAudios.get(studentId);
                    audio.volume = 0.8;
                    audio.muted = false;
                }
            });

            // Update mute all button
            this.updateMuteAllButton(false);
            this.showNotification('All students unmuted', 'success');
        }

        // NEW: Remove student from meeting
        removeStudent(studentId) {
            const studentInfo = this.studentInfo.get(studentId);
            if (!studentInfo) return;

            if (confirm(`Are you sure you want to remove ${studentInfo.name} from the class?`)) {
                // Send remove command to student
                this.sendToStudent(studentId, {
                    type: 'control',
                    action: 'remove',
                    sender: 'trainer',
                    message: 'You have been removed from the class by the trainer'
                });

                // Close connections
                if (this.calls.has(studentId)) {
                    this.calls.get(studentId).close();
                    this.calls.delete(studentId);
                }

                if (this.connections.has(studentId)) {
                    this.connections.get(studentId).close();
                    this.connections.delete(studentId);
                }

                if (this.dataConnections.has(studentId)) {
                    this.dataConnections.get(studentId).close();
                    this.dataConnections.delete(studentId);
                }

                // Remove from maps
                this.studentInfo.delete(studentId);
                this.mutedStudents.delete(studentId);

                // Remove from UI
                this.removeStudentParticipant(studentId);

                // Broadcast to other students
                this.broadcastStudentLeft(studentId);

                this.showNotification(`Removed ${studentInfo.name} from class`, 'warning');
            }
        }

        // NEW: Update student mute UI
        updateStudentMuteUI(studentId, isMuted) {
            const participantElement = document.getElementById('participant_' + studentId);
            if (!participantElement) return;

            const audioStatus = participantElement.querySelector('.ihd-participant-audio-status');
            const muteButton = participantElement.querySelector('.ihd-mute-btn');
            const removeButton = participantElement.querySelector('.ihd-remove-btn');

            if (audioStatus) {
                audioStatus.textContent = isMuted ? '🔇' : '🎤';
                audioStatus.className = `ihd-participant-audio-status ${isMuted ? 'muted' : ''}`;
            }

            if (muteButton) {
                muteButton.innerHTML = isMuted ? '🔊' : '🔇';
                muteButton.title = isMuted ? 'Unmute Student' : 'Mute Student';
            }
        }

        // NEW: Update mute all button
        updateMuteAllButton(isAllMuted) {
            const muteAllBtn = document.getElementById('muteAllBtn');
            if (muteAllBtn) {
                if (isAllMuted) {
                    muteAllBtn.innerHTML = '🔊 Unmute All';
                    muteAllBtn.style.background = '#27ae60';
                } else {
                    muteAllBtn.innerHTML = '🔇 Mute All';
                    muteAllBtn.style.background = '';
                }
            }
        }

        // Add this method to handle incoming student connections
        handleIncomingConnection(conn) {
            const studentId = conn.peer;
            console.log('Incoming connection from student:', studentId);

            conn.on('open', () => {
                console.log('Student data connection opened:', studentId);
                this.connections.set(studentId, conn);
                this.dataConnections.set(studentId, conn);

                // Request student info immediately
                conn.send({
                    type: 'request_info',
                    sender: 'trainer'
                });

                // If screen is already sharing, call the student
                if (this.isScreenSharing && this.screenStream) {
                    this.callStudentWithScreen(studentId);
                }

                // Apply current mute state to new student
                if (this.isAllMuted) {
                    setTimeout(() => {
                        this.sendToStudent(studentId, {
                            type: 'control',
                            action: 'mute',
                            sender: 'trainer'
                        });
                    }, 1000);
                }
            });

            conn.on('data', (data) => {
                console.log('Received data from student:', studentId, data);
                this.handleMessage(data, studentId);
            });

            conn.on('close', () => {
                console.log('Student connection closed:', studentId);
                this.connections.delete(studentId);
                this.dataConnections.delete(studentId);
                this.removeStudentParticipant(studentId);
            });

            conn.on('error', (error) => {
                console.error('Student connection error:', studentId, error);
                this.connections.delete(studentId);
                this.dataConnections.delete(studentId);
                this.removeStudentParticipant(studentId);
            });
        }

        // In TrainerScreenShareConference class - Add student broadcasting methods

        /**
         * Broadcast student info to all other students when a new student joins
         */
        broadcastStudentJoined(studentId, studentInfo) {
            this.connections.forEach((conn, targetStudentId) => {
                if (targetStudentId !== studentId && conn && conn.open) {
                    conn.send({
                        type: 'student_joined',
                        student_id: studentId,
                        student_name: studentInfo.name,
                        is_admin: studentInfo.is_admin || false
                    });
                }
            });
        }

        /**
         * Broadcast student left to all other students
         */
        broadcastStudentLeft(studentId) {
            this.connections.forEach((conn, targetStudentId) => {
                if (targetStudentId !== studentId && conn && conn.open) {
                    conn.send({
                        type: 'student_left',
                        student_id: studentId
                    });
                }
            });
        }

        /**
         * Broadcast student screen share to all participants
         */
        broadcastStudentScreenShare(studentId, action, hasAudio = false) {
            const studentInfo = this.studentInfo.get(studentId);

            this.connections.forEach((conn, targetStudentId) => {
                if (conn && conn.open) {
                    conn.send({
                        type: 'student_screen_share',
                        action: action,
                        student_id: studentId,
                        student_name: studentInfo?.name || 'Student',
                        has_audio: hasAudio,
                        // Include trainer's peer ID so students can connect to each other
                        trainer_peer_id: this.config.meetingId
                    });
                }
            });
        }

        // Replace the current handleStudentInfo method:
        handleStudentInfo(data, studentId) {
            console.log('Processing student info:', data);

            this.studentInfo.set(studentId, {
                name: data.student_name,
                is_admin: data.is_admin || false,
                peer_id: data.student_peer_id,
                added: false
            });

            this.addStudentParticipant(studentId);
            this.showNotification(data.student_name + ' joined the class', 'success');

            // Broadcast to all other students
            this.broadcastStudentJoined(studentId, this.studentInfo.get(studentId));

            // Send current participant list to the new student
            this.sendParticipantListToStudent(studentId);

            // CRITICAL: Also add to otherStudents map for student-to-student communication
            this.otherStudents.set(studentId, {
                name: data.student_name,
                peer_id: data.student_peer_id,
                is_admin: data.is_admin || false
            });

            // Apply mute state if all are muted
            if (this.isAllMuted) {
                this.mutedStudents.add(studentId);
                this.updateStudentMuteUI(studentId, true);
                
                // Send mute command to new student
                setTimeout(() => {
                    this.sendToStudent(studentId, {
                        type: 'control',
                        action: 'mute',
                        sender: 'trainer'
                    });
                }, 500);
            }
        }

        /**
         * Send current participant list to a specific student
         */
        // Replace the current sendParticipantListToStudent method:
        sendParticipantListToStudent(studentId) {
            const conn = this.connections.get(studentId);
            if (!conn || !conn.open) return;

            const participants = [];

            // Add trainer
            participants.push({
                student_id: this.config.meetingId,
                student_name: this.config.trainerName,
                role: 'trainer',
                is_admin: false
            });

            // Add all students (including the requesting student)
            this.studentInfo.forEach((info, id) => {
                participants.push({
                    student_id: id,
                    student_name: info.name,
                    role: info.is_admin ? 'administrator' : 'student',
                    is_admin: info.is_admin || false
                });
            });

            conn.send({
                type: 'participant_list',
                participants: participants,
                trainer_peer_id: this.config.meetingId
            });

            console.log(`Sent participant list to ${studentId}:`, participants);
        }
        // In TrainerScreenShareConference - Update initializeConference method
        async initializeConference() {
            try {
                this.peer = new Peer(this.config.meetingId, {
                    config: {
                        'iceServers': [
                            { urls: 'stun:stun.l.google.com:19302' },
                            { urls: 'stun:global.stun.twilio.com:3478' }
                        ]
                    },
                    debug: 2
                });

                // Wait for peer to be fully ready
                await new Promise((resolve, reject) => {
                    this.peer.on('open', (id) => {
                        console.log('✅ Trainer connected with ID:', id);
                        this.isTrainerReady = true;
                        this.showNotification('Ready to share screen with students', 'info');
                        resolve(id);
                    });

                    this.peer.on('error', (err) => {
                        console.error('❌ Peer connection failed:', err);
                        reject(err);
                    });
                });

                // Set up connection handlers AFTER peer is ready
                this.peer.on('connection', (conn) => {
                    console.log('🔗 Incoming connection from:', conn.peer);
                    this.handleIncomingConnection(conn);
                });

                this.peer.on('call', (call) => {
                    console.log('📞 Incoming call from:', call.peer);
                    this.handleIncomingStudentCall(call);
                });

            } catch (error) {
                console.error('Failed to initialize trainer conference:', error);
                this.showNotification('Failed to initialize connection: ' + error.message, 'error');

                // Retry after 3 seconds
                setTimeout(() => {
                    this.initializeConference();
                }, 3000);
            }
        }

        // Add connection state management
        isConnectionReady(conn) {
            return conn && conn.open && !conn.disconnected;
        }

        // Safe message sending method
        sendToStudent(studentId, data) {
            const conn = this.connections.get(studentId);
            if (this.isConnectionReady(conn)) {
                try {
                    conn.send(data);
                    return true;
                } catch (error) {
                    console.error('Failed to send to student:', studentId, error);
                    return false;
                }
            } else {
                console.warn('Connection not ready for student:', studentId);
                return false;
            }
        }

        // Broadcast to all students safely
        broadcastToAllStudents(data) {
            let successCount = 0;
            this.connections.forEach((conn, studentId) => {
                if (this.sendToStudent(studentId, data)) {
                    successCount++;
                }
            });
            console.log(`Broadcasted to ${successCount}/${this.connections.size} students`);
        }
        // Add this method to TrainerScreenShareConference class:
        handleStudentDataConnection(conn) {
            const studentId = conn.peer;
            console.log('Student data connection from:', studentId);

            conn.on('open', () => {
                console.log('Student data connection opened:', studentId);
                this.dataConnections.set(studentId, conn);

                // Request student info
                conn.send({
                    type: 'request_info',
                    sender: 'trainer'
                });
            });

            conn.on('data', (data) => {
                console.log('Received data from student:', studentId, data);
                this.handleMessage(data, studentId);
            });

            conn.on('close', () => {
                console.log('Student data connection closed:', studentId);
                this.dataConnections.delete(studentId);
                this.removeStudentParticipant(studentId);
            });

            conn.on('error', (err) => {
                console.error('Student connection error:', studentId, err);
                this.dataConnections.delete(studentId);
                this.removeStudentParticipant(studentId);
            });
        }
        // NEW: Proper data connection handler for chat
        handleDataConnection(conn) {
            const studentId = conn.peer;
			// Check if we already have this connection to prevent duplicates
            if (this.dataConnections.has(studentId)) {
                console.log('⚠️ Duplicate connection detected for student:', studentId);
                conn.close(); // Close the duplicate connection
                return;
            }
            conn.on('open', () => {
                console.log('✅ Data connection opened with student:', studentId);
                this.dataConnections.set(studentId, conn);
				this.connections.set(studentId, conn);
                // Request student info
                conn.send({
                    type: 'request_info',
                    sender: 'trainer'
                });
            });

            conn.on('data', (data) => {
                console.log('📨 Received data from student:', studentId, data);
                this.handleMessage(data, studentId);
            });

            conn.on('close', () => {
                console.log('❌ Data connection closed with student:', studentId);
                this.dataConnections.delete(studentId);
                this.connections.delete(studentId);
                this.removeStudentParticipant(studentId);
            });

            conn.on('error', (err) => {
                console.error('❌ Data connection error with student:', studentId, err);
                this.dataConnections.delete(studentId);
                this.connections.delete(studentId);
                this.removeStudentParticipant(studentId);
            });
        }
        // Add this method to handle incoming chat messages:
        handleIncomingChat(data, studentId) {
            console.log('Received chat from student:', studentId, data);

            // Display the message in trainer's chat
            this.displayChatMessage({
                sender: 'student',
                sender_name: data.sender_name || this.studentInfo.get(studentId)?.name || 'Student',
                message: data.message
            });

            // Broadcast to all other students (optional)
            this.broadcastChatToOtherStudents(data, studentId);
        }
		// In TrainerScreenShareConference class
        broadcastChatToStudents(messageData, excludeStudentId = null) {
            this.connections.forEach((conn, studentId) => {
                if (studentId !== excludeStudentId && conn && conn.open) {
                    conn.send(messageData);
                }
            });
        }
        broadcastChatToOtherStudents(data, senderStudentId) {
            this.connections.forEach((conn, studentId) => {
                if (studentId !== senderStudentId && conn && conn.open) {
                    conn.send({
                        type: 'chat',
                        message: data.message,
                        sender: 'student',
                        sender_name: data.sender_name
                    });
                }
            });
        }
        // In TrainerScreenShareConference class - Add fullscreen methods for student screens
        initializeStudentScreenFullscreen() {
            // This will be called when student screens are created
            document.addEventListener('click', (e) => {
                if (e.target.closest('.ihd-student-screen')) {
                    const studentScreen = e.target.closest('.ihd-student-screen');
                    if (e.detail === 2) { // Double click
                        this.toggleStudentScreenFullscreen(studentScreen);
                    }
                }
            });
        }

        toggleStudentScreenFullscreen(studentScreen) {
            if (!studentScreen) return;

            if (!document.fullscreenElement) {
                // Enter fullscreen
                if (studentScreen.requestFullscreen) {
                    studentScreen.requestFullscreen();
                } else if (studentScreen.webkitRequestFullscreen) {
                    studentScreen.webkitRequestFullscreen();
                } else if (studentScreen.msRequestFullscreen) {
                    studentScreen.msRequestFullscreen();
                }

                studentScreen.classList.add('fullscreen-active');
                this.createExitFullscreenButton(studentScreen);

            } else {
                // Exit fullscreen
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                } else if (document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                } else if (document.msExitFullscreen) {
                    document.msExitFullscreen();
                }
            }
        }
		// NEW: Cleanup audio contexts
        cleanupAudioContexts() {
            if (this.audioContexts) {
                this.audioContexts.forEach((audioData, audioElement) => {
                    try {
                        if (audioData.context && audioData.context.state !== 'closed') {
                            audioData.context.close();
                        }
                    } catch (error) {
                        console.log('Error closing audio context:', error);
                    }
                });
                this.audioContexts.clear();
            }
        }
        createExitFullscreenButton(studentScreen) {
            // Remove existing button if any
            const existingBtn = document.querySelector('.ihd-exit-fullscreen-btn');
            if (existingBtn) {
                existingBtn.remove();
            }

            // Create exit button
            const exitBtn = document.createElement('button');
            exitBtn.className = 'ihd-exit-fullscreen-btn';
            exitBtn.innerHTML = '✕ Exit Fullscreen';
            exitBtn.onclick = () => this.exitStudentScreenFullscreen(studentScreen);

            document.body.appendChild(exitBtn);
        }

        exitStudentScreenFullscreen(studentScreen) {
            if (document.exitFullscreen) {
                document.exitFullscreen();
            } else if (document.webkitExitFullscreen) {
                document.webkitExitFullscreen();
            } else if (document.msExitFullscreen) {
                document.msExitFullscreen();
            }

            studentScreen.classList.remove('fullscreen-active');

            // Remove exit button
            const exitBtn = document.querySelector('.ihd-exit-fullscreen-btn');
            if (exitBtn) {
                exitBtn.remove();
            }
        }

        // Update the handleStudentScreenShare method to include fullscreen initialization
        handleStudentScreenShare(studentId, remoteStream) {
            console.log('Handling student screen share from:', studentId);

            // Create or update student screen share container
            let studentScreenContainer = document.getElementById('studentScreenContainer');

            if (!studentScreenContainer) {
                studentScreenContainer = document.createElement('div');
                studentScreenContainer.id = 'studentScreenContainer';
                studentScreenContainer.className = 'ihd-student-screens-container';

                const sidebar = document.querySelector('.ihd-sidebar');
                const videoContainer = document.querySelector('.ihd-video-container');

                if (videoContainer && sidebar) {
                    videoContainer.insertBefore(studentScreenContainer, sidebar);
                }

                // Initialize fullscreen functionality
                this.initializeStudentScreenFullscreen();
            }

            // Create or update individual student screen
            let studentScreen = document.getElementById(`studentScreen_${studentId}`);

            if (!studentScreen) {
                studentScreen = document.createElement('div');
                studentScreen.id = `studentScreen_${studentId}`;
                studentScreen.className = 'ihd-student-screen';

                studentScreen.innerHTML = `
                    <div class="ihd-student-screen-header">
                        <span class="ihd-student-name">${this.studentInfo.get(studentId)?.name || 'Student'}'s Screen</span>
                        <button class="ihd-close-student-screen" onclick="trainerConference.closeStudentScreen('${studentId}')">×</button>
                    </div>
                    <video class="ihd-student-screen-video" autoplay playsinline></video>
                `;

                studentScreenContainer.appendChild(studentScreen);

                // Add double-click event listener
                studentScreen.addEventListener('dblclick', () => {
                    this.toggleStudentScreenFullscreen(studentScreen);
                });
            }

            // Set the video stream
            const videoElement = studentScreen.querySelector('.ihd-student-screen-video');
            if (videoElement) {
                videoElement.srcObject = remoteStream;
            }

            this.showNotification(`${this.studentInfo.get(studentId)?.name || 'Student'} started screen sharing`, 'success');
        }

        // Add fullscreen change event listener
        initializeFullscreenListeners() {
            document.addEventListener('fullscreenchange', () => this.handleFullscreenChange());
            document.addEventListener('webkitfullscreenchange', () => this.handleFullscreenChange());
            document.addEventListener('mozfullscreenchange', () => this.handleFullscreenChange());
            document.addEventListener('MSFullscreenChange', () => this.handleFullscreenChange());
        }

        handleFullscreenChange() {
            const studentScreens = document.querySelectorAll('.ihd-student-screen');
            const exitBtn = document.querySelector('.ihd-exit-fullscreen-btn');

            if (!document.fullscreenElement) {
                // Exited fullscreen
                studentScreens.forEach(screen => {
                    screen.classList.remove('fullscreen-active');
                });

                if (exitBtn) {
                    exitBtn.remove();
                }
            }
        
            // Create or update individual student screen
            let studentScreen = document.getElementById(`studentScreen_${studentId}`);

            if (!studentScreen) {
                studentScreen = document.createElement('div');
                studentScreen.id = `studentScreen_${studentId}`;
                studentScreen.className = 'ihd-student-screen';

                studentScreen.innerHTML = `
                    <div class="ihd-student-screen-header">
                        <span class="ihd-student-name">${this.studentInfo.get(studentId)?.name || 'Student'}'s Screen</span>
                        <button class="ihd-close-student-screen" onclick="trainerConference.closeStudentScreen('${studentId}')">×</button>
                    </div>
                    <video class="ihd-student-screen-video" autoplay playsinline></video>
                `;

                studentScreenContainer.appendChild(studentScreen);
            }

            // Set the video stream
            const videoElement = studentScreen.querySelector('.ihd-student-screen-video');
            if (videoElement) {
                videoElement.srcObject = remoteStream;
            }

            this.showNotification(`${this.studentInfo.get(studentId)?.name || 'Student'} started screen sharing`, 'success');
        }

        // Add method to close student screen
        closeStudentScreen(studentId) {
            const studentScreen = document.getElementById(`studentScreen_${studentId}`);
            if (studentScreen) {
                studentScreen.remove();
            }

            // Close the call if it exists
            if (this.incomingStudentCalls && this.incomingStudentCalls.has(studentId)) {
                const call = this.incomingStudentCalls.get(studentId);
                call.close();
                this.incomingStudentCalls.delete(studentId);
            }

            this.showNotification('Student screen sharing closed', 'info');
        }
		
        // Replace the current getOptimizedAudioStream method with this:
        async getOptimizedAudioStream() {
            try {
                const audioConstraints = {
                    audio: {
                        // Basic constraints
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true,
                        googEchoCancellation: true,
                        googNoiseSuppression: true,
                        googAutoGainControl: true,
                        googHighpassFilter: true,

                        // Critical: Prevent audio mirroring and feedback
                        googAudioMirroring: false,
                        googEchoCancellation2: true,
                        googNoiseSuppression2: true,

                        // Optimize for voice
                        sampleRate: 16000, // Lower sample rate for voice
                        channelCount: 1,   // Mono is better for voice
                        sampleSize: 16,

                        // Latency optimization
                        latency: 0.01,

                        // Advanced constraints
                        advanced: [
                            { echoCancellation: true },
                            { googEchoCancellation: true },
                            { googEchoCancellation2: true },
                            { googAutoGainControl: true },
                            { googNoiseSuppression: true },
                            { googNoiseSuppression2: true },
                            { googHighpassFilter: true },
                            { googAudioMirroring: false }
                        ]
                    },
                    video: false
                };

                const audioStream = await navigator.mediaDevices.getUserMedia(audioConstraints);

                // Apply additional constraints to tracks
                const audioTracks = audioStream.getAudioTracks();
                audioTracks.forEach(track => {
                    if (track) {
                        track.applyConstraints({
                            echoCancellation: true,
                            noiseSuppression: true,
                            autoGainControl: true,
                            channelCount: 1,
                            sampleRate: 16000,
                            latency: 0.01
                        });

                        console.log('Audio track settings:', track.getSettings());
                    }
                });

                return audioStream;
            } catch (error) {
                console.error('Failed to get optimized audio:', error);
                throw error;
            }
        }
        // Add these methods to TrainerScreenShareConference class:

        // Improved audio setup method
        async setupTrainerAudio() {
            try {
                // First, stop any existing audio tracks
                if (this.audioStream) {
                    this.audioStream.getTracks().forEach(track => track.stop());
                }

                const audioStream = await navigator.mediaDevices.getUserMedia({
                    audio: {
                        // Voice-optimized settings
                        echoCancellation: { ideal: true },
                        noiseSuppression: { ideal: true },
                        autoGainControl: { ideal: true },
                        channelCount: 1,
                        sampleRate: 16000,
                        latency: 0.01,

                        // Chrome-specific optimizations
                        googEchoCancellation: true,
                        googNoiseSuppression: true,
                        googAutoGainControl: true,
                        googHighpassFilter: true,
                        googAudioMirroring: false,

                        // Prevent multiple processing
                        advanced: [
                            { echoCancellation: true },
                            { googEchoCancellation: true }
                        ]
                    },
                    video: false
                });

                // Mute local playback immediately
                const audioTracks = audioStream.getAudioTracks();
                audioTracks.forEach(track => {
                    if (track) {
                        // Create hidden audio element to capture track without playback
                        const hiddenAudio = document.createElement('audio');
                        hiddenAudio.srcObject = new MediaStream([track]);
                        hiddenAudio.volume = 0;
                        hiddenAudio.muted = true;
                        hiddenAudio.style.display = 'none';
                        document.body.appendChild(hiddenAudio);
                    }
                });

                this.audioStream = audioStream;
                return audioStream;

            } catch (error) {
                console.error('Failed to setup trainer audio:', error);
                return null;
            }
        }

        handleIncomingStudentCall(call) {
            console.log('Incoming call from student:', call.peer);

            // Answer the call
            call.answer();

            call.on('stream', (remoteStream) => {
                console.log('Received stream from student:', call.peer);

                const audioTracks = remoteStream.getAudioTracks();
                const videoTracks = remoteStream.getVideoTracks();

                if (audioTracks.length > 0) {
                    console.log('Student audio track received');

                    // FIXED: Create optimized audio element
                    const studentAudio = document.createElement('audio');
                    studentAudio.srcObject = new MediaStream([audioTracks[0]]);
                    studentAudio.autoplay = true;
                    
                    // Check if student is muted and apply mute state
                    if (this.mutedStudents.has(call.peer)) {
                        studentAudio.volume = 0;
                        studentAudio.muted = true;
                    } else {
                        studentAudio.volume = 0.7; // Reasonable volume
                    }

                    // FIXED: Apply audio processing to improve quality
                    this.optimizeIncomingAudio(studentAudio);

                    // Store reference
                    if (!this.studentAudios) this.studentAudios = new Map();
                    this.studentAudios.set(call.peer, studentAudio);

                    // Add to hidden container
                    const audioContainer = document.getElementById('studentAudiosContainer') || 
                        (() => {
                            const container = document.createElement('div');
                            container.id = 'studentAudiosContainer';
                            container.style.display = 'none';
                            document.body.appendChild(container);
                            return container;
                        })();

                    audioContainer.appendChild(studentAudio);

                    this.showNotification('Student audio connected', 'success');
                }

                if (videoTracks.length > 0) {
                    this.handleStudentScreenShare(call.peer, remoteStream);
                }
            });

            call.on('close', () => {
                console.log('Student call closed:', call.peer);
                // Clean up audio element
                if (this.studentAudios && this.studentAudios.has(call.peer)) {
                    const audio = this.studentAudios.get(call.peer);
                    audio.srcObject = null;
                    audio.remove();
                    this.studentAudios.delete(call.peer);
                }
            });

            // Track the call
            if (!this.incomingStudentCalls) this.incomingStudentCalls = new Map();
            this.incomingStudentCalls.set(call.peer, call);
        }

        // ENHANCED: Process incoming student audio with better quality
        optimizeIncomingAudio(audioElement) {
            if (!audioElement) return;

            try {
                // Set optimal volume only if not muted
                if (!this.mutedStudents.has(audioElement._studentId)) {
                    audioElement.volume = 0.8;
                }

                // FIXED: Enhanced Web Audio API processing for student audio
                if (window.AudioContext || window.webkitAudioContext) {
                    const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                    const source = audioContext.createMediaStreamSource(audioElement.srcObject);

                    // Create audio processing chain for better voice quality
                    const highPassFilter = audioContext.createBiquadFilter();
                    highPassFilter.type = 'highpass';
                    highPassFilter.frequency.value = 80; // Remove low frequencies

                    const lowPassFilter = audioContext.createBiquadFilter();
                    lowPassFilter.type = 'lowpass';
                    lowPassFilter.frequency.value = 8000; // Remove very high frequencies

                    const compressor = audioContext.createDynamicsCompressor();
                    compressor.threshold.value = -24;
                    compressor.knee.value = 30;
                    compressor.ratio.value = 12;
                    compressor.attack.value = 0.003;
                    compressor.release.value = 0.25;

                    const gainNode = audioContext.createGain();
                    gainNode.gain.value = 1.2; // Slight boost

                    // Connect the processing chain
                    source.connect(highPassFilter);
                    highPassFilter.connect(lowPassFilter);
                    lowPassFilter.connect(compressor);
                    compressor.connect(gainNode);
                    gainNode.connect(audioContext.destination);

                    console.log('Enhanced audio processing applied to student audio');

                    // Store reference for cleanup
                    this.audioContexts = this.audioContexts || new Map();
                    this.audioContexts.set(audioElement, {
                        context: audioContext,
                        source: source,
                        nodes: [highPassFilter, lowPassFilter, compressor, gainNode]
                    });
                }

            } catch (error) {
                console.log('Advanced audio processing not available, using basic audio');
                // Fallback: basic volume adjustment
                if (!this.mutedStudents.has(audioElement._studentId)) {
                    audioElement.volume = 0.8;
                }
            }
        }
        async toggleScreenShare() {
            try {
                if (!this.isScreenSharing) {
                    console.log('Starting screen share...');

                    // FIXED: Use optimized audio constraints for screen sharing
                    const displayMediaOptions = {
                        video: {
                            cursor: "always",
                            displaySurface: "window",
                            width: { ideal: 1280 }, // Lower resolution for better performance
                            height: { ideal: 720 },
                            frameRate: { ideal: 24 } // Lower frame rate
                        },
                        audio: {
                            // FIXED: Optimized audio constraints to prevent echo
                            echoCancellation: true,
                            noiseSuppression: true,
                            autoGainControl: true,
                            channelCount: 1, // MONO - critical for echo cancellation
                            sampleRate: 16000, // Lower sample rate for voice
                            sampleSize: 16,
                            latency: 0.01,
                            googEchoCancellation: true,
                            googNoiseSuppression: true,
                            googAutoGainControl: true,
                            googHighpassFilter: true,
                            googAudioMirroring: false // Critical: prevent audio feedback
                        }
                    };

                    this.screenStream = await navigator.mediaDevices.getDisplayMedia(displayMediaOptions);

                    if (!this.screenStream) {
                        throw new Error('Failed to get screen stream');
                    }

                    this.isScreenSharing = true;

                    // FIXED: Enhanced audio processing
                    this.processAudioTracksForEchoCancellation();

                    // CRITICAL: Mute local audio playback immediately
                    const screenVideo = document.getElementById('screenShareVideo');
                    if (screenVideo) {
                        screenVideo.muted = true;
                        screenVideo.volume = 0;
                        screenVideo.setAttribute('muted', 'true');
                    }

                    // Check audio tracks
                    const audioTracks = this.screenStream.getAudioTracks();
                    const videoTracks = this.screenStream.getVideoTracks();

                    console.log('Screen share tracks:', {
                        video: videoTracks.length,
                        audio: audioTracks.length
                    });

                    if (audioTracks.length === 0) {
                        this.showNotification('⚠️ No audio detected. Make sure to check "Share audio" when sharing screen.', 'warning');
                        this.isAudioEnabled = false;
                    } else {
                        this.isAudioEnabled = true;

                        // FIXED: Apply additional audio constraints
                        audioTracks.forEach(track => {
                            track.applyConstraints({
                                echoCancellation: true,
                                noiseSuppression: true,
                                autoGainControl: true,
                                channelCount: 1,
                                sampleRate: 16000
                            });
                        });

                        this.showNotification('🎤 Screen audio detected! Students can hear your system audio', 'success');
                    }

                    // Update UI
                    this.updateScreenShareUI(true);
                    this.updateAudioUI(this.isAudioEnabled);

                    // Setup track ended handlers
                    this.screenStream.getTracks().forEach((track) => {
                        if (track) {
                            track.onended = () => {
                                console.log(`${track.kind} track ended`);
                                if (track.kind === 'video') {
                                    this.stopScreenShare();
                                }
                            };
                        }
                    });

                    // Initialize systems
                    this.initializeRecording();
                    this.callAllStudents();
                    this.broadcastScreenStream();

                } else {
                    console.log('Stopping screen share...');
                    this.stopScreenShare();
                }
            } catch (error) {
                console.error('Screen share error:', error);
                this.handleScreenShareError(error);
            }
        }
        // Replace the current processAudioTracksForEchoCancellation method:
        processAudioTracksForEchoCancellation() {
            if (!this.screenStream) return;

            const audioTracks = this.screenStream.getAudioTracks();

            audioTracks.forEach(track => {
                if (track) {
                    // FIXED: Enhanced audio constraints
                    track.applyConstraints({
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true,
                        channelCount: 1, // Critical: mono audio
                        sampleRate: 16000, // Voice-optimized sample rate
                        sampleSize: 16,
                        latency: 0.01,
                        googEchoCancellation: true,
                        googNoiseSuppression: true,
                        googAutoGainControl: true,
                        googHighpassFilter: true,
                        googAudioMirroring: false // Critical: no audio mirroring
                    });

                    // FIXED: Create a proper audio context for monitoring
                    this.setupAudioMonitoring(track);

                    console.log('Audio track configured:', track.getSettings());
                }
            });
        }
		// Add this method to handle incoming data connections properly
        handleStudentConnection(conn) {
            const studentId = conn.peer;
            console.log('Student connection established:', studentId);

            conn.on('open', () => {
                console.log('Student data connection opened:', studentId);
                this.connections.set(studentId, conn);

                // Request student info
                conn.send({
                    type: 'request_info',
                    sender: 'trainer'
                });

                // If screen is already sharing, call the student immediately
                if (this.isScreenSharing && this.screenStream) {
                    this.callStudentWithScreen(studentId);
                }
            });

            conn.on('data', (data) => {
                console.log('Received data from student:', data);
                this.handleMessage(data, studentId);
            });

            conn.on('close', () => {
                console.log('Student data connection closed:', studentId);
                this.connections.delete(studentId);
                this.removeStudentParticipant(studentId);
            });

            conn.on('error', (error) => {
                console.error('Student connection error:', error);
                this.connections.delete(studentId);
                this.removeStudentParticipant(studentId);
            });
        }
        // Add this new method for audio monitoring
        setupAudioMonitoring(track) {
            try {
                // Create audio context for monitoring (without playback)
                const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                const source = audioContext.createMediaStreamSource(new MediaStream([track]));
                const analyser = audioContext.createAnalyser();

                analyser.fftSize = 256;
                source.connect(analyser);

                // Monitor for potential echo/feedback
                const checkAudioLevels = () => {
                    const dataArray = new Uint8Array(analyser.frequencyBinCount);
                    analyser.getByteFrequencyData(dataArray);

                    const average = dataArray.reduce((a, b) => a + b) / dataArray.length;

                    // If audio levels are too high, it might indicate feedback
                    if (average > 180) {
                        console.warn('High audio levels detected - potential feedback');
                        // You could automatically adjust constraints here
                    }
                };

                // Check every 2 seconds
                this.audioMonitorInterval = setInterval(checkAudioLevels, 2000);

            } catch (error) {
                console.log('Audio monitoring not available:', error);
            }
        }
        // UPDATED: Call all connected students with proper error handling
        callAllStudents() {
            if (!this.screenStream) {
                console.error('No screen stream available to call students');
                return;
            }

            console.log('Calling all connected students. Total:', this.connections.size);

            this.connections.forEach((conn, studentId) => {
                if (conn && conn.open) {
                    console.log(`Calling student: ${studentId}`);
                    this.callStudentWithScreen(studentId);
                } else {
                    console.warn(`Skipping student ${studentId} - connection not open`);
                }
            });
        }
        // Enhanced error handling
        handleScreenShareError(error) {
            let errorMessage = 'Failed to share screen';
            
            switch (error.name) {
                case 'NotAllowedError':
                    errorMessage = 'Screen sharing permission denied by user';
                    break;
                case 'NotFoundError':
                    errorMessage = 'No screen sharing source found';
                    break;
                case 'NotReadableError':
                    errorMessage = 'Screen source is not readable or already in use';
                    break;
                case 'OverconstrainedError':
                    errorMessage = 'Screen sharing constraints cannot be satisfied';
                    break;
                case 'AbortError':
                    errorMessage = 'Screen sharing was aborted';
                    break;
                default:
                    errorMessage = `Screen sharing failed: ${error.message}`;
            }
            
            this.showNotification(errorMessage, 'error');
            console.error('Screen share failed:', error);
        }
		// Update cleanupAudioElements method:
        cleanupAudioElements() {
            // Clean up audio monitoring
            if (this.audioMonitorInterval) {
                clearInterval(this.audioMonitorInterval);
                this.audioMonitorInterval = null;
            }

            // Clean up temporary audio elements
            if (this.tempAudioElements) {
                this.tempAudioElements.forEach(audio => {
                    if (audio && audio.parentNode) {
                        audio.srcObject = null;
                        audio.remove();
                    }
                });
                this.tempAudioElements = [];
            }

            // Clean up student audio elements
            if (this.studentAudios) {
                this.studentAudios.forEach((audio, studentId) => {
                    if (audio && audio.parentNode) {
                        audio.srcObject = null;
                        audio.remove();
                    }
                });
                this.studentAudios.clear();
            }

            // Clean up audio stream
            if (this.audioStream) {
                this.audioStream.getTracks().forEach(track => track.stop());
                this.audioStream = null;
            }
        }
        
        stopScreenShare() {
            this.cleanupAudioElements();
            this.cleanupAudioContexts(); 
            if (this.screenStream) {
                this.screenStream.getTracks().forEach(track => {
                    if (track) {
                        track.stop();
                    }
                });
                this.screenStream = null;
            }

            // Clear feedback monitoring
            if (this.feedbackInterval) {
                clearInterval(this.feedbackInterval);
                this.feedbackInterval = null;
            }

            this.isScreenSharing = false;
            this.isAudioEnabled = false;

            // Stop recording if active
            if (this.isRecording) {
                this.stopRecording();
            }

            // Update UI
            this.updateScreenShareUI(false);
            this.updateAudioUI(false);

            // Notify students
            this.connections.forEach((conn, studentId) => {
                if (conn && conn.open) {
                    conn.send({
                        type: 'screen_share',
                        action: 'stopped',
                        sender: 'trainer'
                    });
                }
            });

            this.showNotification('Screen sharing stopped', 'info');
        }
        updateScreenShareUI(isSharing) {
            const screenShareBtn = document.getElementById('screenShareBtn');
            const screenPlaceholder = document.getElementById('screenPlaceholder');
            const screenVideo = document.getElementById('screenShareVideo');
            const screenOverlay = document.getElementById('screenOverlay');

            if (isSharing) {
                screenShareBtn.innerHTML = '🖥️ Stop Sharing';
                screenShareBtn.style.background = '#e74c3c';
                screenPlaceholder.style.display = 'none';
                screenVideo.style.display = 'block';
                screenOverlay.style.display = 'flex';
                
                // Set the screen stream to video element
                if (this.screenStream) {
                    screenVideo.srcObject = this.screenStream;
                }
            } else {
                screenShareBtn.innerHTML = '🖥️ Share Screen';
                screenShareBtn.style.background = '';
                screenPlaceholder.style.display = 'flex';
                screenVideo.style.display = 'none';
                screenOverlay.style.display = 'none';
                screenVideo.srcObject = null;
            }
        }
		// NEW: Add microphone audio when screen audio is not available
        async addMicrophoneAudio() {
            try {
                this.showNotification('Setting up microphone...', 'info');

                // Get microphone stream
                const microphoneStream = await navigator.mediaDevices.getUserMedia({
                    audio: {
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true,
                        channelCount: 1,
                        sampleRate: 44100
                    },
                    video: false
                });

                const microphoneTracks = microphoneStream.getAudioTracks();

                if (microphoneTracks.length === 0) {
                    throw new Error('No microphone tracks available');
                }

                // Add microphone tracks to screen stream
                microphoneTracks.forEach(track => {
                    if (track) {
                        this.screenStream.addTrack(track);
                        console.log('Added microphone track to screen stream');
                    }
                });

                this.isAudioEnabled = true;
                this.updateAudioUI(true);

                // Update all student connections with the new stream
                this.updateStudentConnectionsWithNewStream();

                this.showNotification('🎤 Microphone audio added! Students can hear you', 'success');

            } catch (error) {
                console.error('Failed to add microphone audio:', error);

                if (error.name === 'NotAllowedError') {
                    this.showNotification('Microphone permission denied. Please allow microphone access.', 'error');
                } else {
                    this.showNotification('Failed to setup microphone: ' + error.message, 'error');
                }
            }
        }
        // FIXED: Audio Control with fallback options
        async toggleAudio() {
            try {
                if (!this.screenStream || !this.isScreenSharing) {
                    this.showNotification('Please start screen sharing first', 'warning');
                    return;
                }

                const audioTracks = this.screenStream.getAudioTracks();

                // If no audio tracks available, offer to add microphone
                if (audioTracks.length === 0) {
                    const addAudio = confirm('No screen audio detected. Would you like to add your microphone audio instead?');
                    if (addAudio) {
                        await this.addMicrophoneAudio();
                        return;
                    } else {
                        this.showNotification('Audio not available. Share audio when starting screen share.', 'warning');
                        return;
                    }
                }

                this.isAudioEnabled = !this.isAudioEnabled;

                // CRITICAL: Always mute local audio playback for trainer
                const screenVideo = document.getElementById('screenShareVideo');
                if (screenVideo) {
                    screenVideo.muted = true;
                    screenVideo.volume = 0;
                }

                // Enable/disable audio tracks for transmission to students
                audioTracks.forEach(track => {
                    if (track) {
                        track.enabled = this.isAudioEnabled;
                        console.log(`Audio track ${this.isAudioEnabled ? 'enabled' : 'disabled'} for transmission`);
                    }
                });

                this.updateAudioUI(this.isAudioEnabled);

                if (this.isAudioEnabled) {
                    this.showNotification('🎤 Students can now hear your audio', 'success');
                } else {
                    this.showNotification('🔇 Students cannot hear your audio', 'warning');
                }

            } catch (error) {
                console.error('Audio toggle error:', error);
                this.showNotification('Failed to toggle audio: ' + error.message, 'error');
            }
        }
        // NEW METHOD: Add audio track to existing screen share
        // UPDATED: Add optimized audio track to existing screen share
        async addAudioToScreenShare() {
            try {
                this.showNotification('Setting up optimized audio...', 'info');

                // Get optimized audio stream
                const audioStream = await this.getOptimizedAudioStream();

                // Add audio tracks to screen stream
                const audioTracks = audioStream.getAudioTracks();
                audioTracks.forEach(track => {
                    if (track) {
                        // Configure track to prevent echo
                        track.applyConstraints({
                            echoCancellation: true,
                            noiseSuppression: true,
                            googAudioMirroring: false
                        });

                        this.screenStream.addTrack(track);
                        console.log('Added optimized audio track to screen share');
                    }
                });

                this.isAudioEnabled = true;
                this.updateAudioUI(true);

                // CRITICAL: Update all student connections with new stream
                this.updateStudentConnectionsWithNewStream();

                this.showNotification('Audio added with echo cancellation', 'success');

            } catch (error) {
                console.error('Failed to add optimized audio:', error);
                this.showNotification('Failed to add audio: ' + error.message, 'error');
            }
        }
		// NEW: Monitor and prevent audio feedback
        setupAudioFeedbackPrevention() {
            if (!this.screenStream) return;

            const audioTracks = this.screenStream.getAudioTracks();

            audioTracks.forEach((track, index) => {
                if (track) {
                    // Monitor audio levels to detect potential feedback
                    const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                    const source = audioContext.createMediaStreamSource(new MediaStream([track]));
                    const analyser = audioContext.createAnalyser();
                    source.connect(analyser);

                    // Check for continuous high audio levels (potential feedback)
                    const checkFeedback = () => {
                        const dataArray = new Uint8Array(analyser.frequencyBinCount);
                        analyser.getByteFrequencyData(dataArray);

                        const average = dataArray.reduce((a, b) => a + b) / dataArray.length;

                        // If audio level is consistently high, it might be feedback
                        if (average > 200) {
                            console.warn('Potential audio feedback detected');
                            this.showNotification('Audio feedback detected - adjusting settings', 'warning');

                            // Reduce volume or reapply constraints
                            track.applyConstraints({
                                echoCancellation: true,
                                autoGainControl: true
                            });
                        }
                    };

                    // Check every 2 seconds
                    this.feedbackInterval = setInterval(checkFeedback, 2000);
                }
            });
        }
        // CRITICAL: Prevent trainer from hearing their own voice
        setupAudioEchoCancellation() {
            if (!this.screenStream) return;

            const audioTracks = this.screenStream.getAudioTracks();
            audioTracks.forEach(track => {
                if (track) {
                    // Critical: Disable local audio playback for trainer
                    const audioElement = document.getElementById('screenShareVideo');
                    if (audioElement) {
                        audioElement.volume = 0; // Mute local audio
                        audioElement.muted = true; // Ensure muted
                        audioElement.setAttribute('muted', 'true'); // HTML attribute
                    }

                    // Enhanced audio constraints to prevent echo
                    track.applyConstraints({
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true,
                        googEchoCancellation: true,
                        googNoiseSuppression: true,
                        googAutoGainControl: true,
                        googHighpassFilter: true,
                        // Critical: Disable audio mirroring
                        googAudioMirroring: false
                    });

                    console.log('Audio track configured for echo prevention:', track.getSettings());
                }
            });
        }
        // NEW METHOD: Update student connections when stream changes
        updateStudentConnectionsWithNewStream() {
            if (!this.screenStream) return;

            // Close existing calls and create new ones with updated stream
            this.calls.forEach((call, studentId) => {
                if (call) {
                    call.close();
                }

                // Create new call with updated stream
                const newCall = this.peer.call(studentId, this.screenStream);
                this.calls.set(studentId, newCall);

                newCall.on('stream', (remoteStream) => {
                    console.log(`Student ${studentId} reconnected with audio`);
                });

                newCall.on('close', () => {
                    this.calls.delete(studentId);
                });
            });
        }

        updateAudioUI(isEnabled) {
            const audioBtn = document.getElementById('audioBtn');
            if (isEnabled) {
                audioBtn.innerHTML = '🎤 Mute Audio';
                audioBtn.style.background = '';
                // Tooltip for clarity
                audioBtn.title = 'Students can hear you (you won\'t hear yourself)';
            } else {
                audioBtn.innerHTML = '🎤🔇 Unmute Audio';
                audioBtn.style.background = '#e74c3c';
                audioBtn.title = 'Students cannot hear you';
            }
        }

        // ENHANCED: High Quality MP4 Recording System for Trainer
        initializeRecording() {
            try {
                if (!this.screenStream) {
                    console.error('No screen stream available for recording');
                    return false;
                }

                // Check if we have video tracks
                const videoTracks = this.screenStream.getVideoTracks();
                const audioTracks = this.screenStream.getAudioTracks();

                if (videoTracks.length === 0) {
                    console.error('No video tracks available for recording');
                    this.showNotification('No screen video available for recording', 'warning');
                    return false;
                }

                console.log('Available tracks for recording:', {
                    video: videoTracks.length,
                    audio: audioTracks.length
                });

                this.recordedChunks = [];

                // Enhanced video quality settings for trainer
                const videoSettings = {
                    // High quality video settings for screen sharing
                    width: 1920,
                    height: 1080,
                    frameRate: 30,
                    bitrate: 5000000, // 5 Mbps for high quality screen sharing
                    audioBitrate: 192000 // 192 kbps for clear audio
                };

                // Try MP4 codecs first, then fallback to WebM
                const mimeTypes = [
                    // MP4/H.264 codecs (preferred for compatibility and quality)
                    'video/mp4;codecs=h264,aac',
                    'video/mp4;codecs=avc1.42E01E,mp4a.40.2',
                    'video/mp4;codecs=h264,opus',
                    'video/mp4;codecs=avc1.428028,mp4a.40.2',

                    // High quality WebM fallbacks
                    'video/webm;codecs=vp9,opus',
                    'video/webm;codecs=vp9',
                    'video/webm;codecs=vp8,opus',
                    'video/webm;codecs=vp8',
                    'video/webm'
                ];

                let options = { 
                    videoBitsPerSecond: videoSettings.bitrate,
                    audioBitsPerSecond: videoSettings.audioBitrate,
                    mimeType: ''
                };

                // Find the best supported mimeType
                let selectedMimeType = '';
                for (let mimeType of mimeTypes) {
                    if (MediaRecorder.isTypeSupported(mimeType)) {
                        selectedMimeType = mimeType;
                        console.log('Selected mimeType:', mimeType);
                        break;
                    }
                }

                if (!selectedMimeType) {
                    console.warn('No supported mimeType found, using default');
                    // Let browser choose default with our quality settings
                    options = { 
                        videoBitsPerSecond: videoSettings.bitrate,
                        audioBitsPerSecond: videoSettings.audioBitrate
                    };
                } else {
                    options.mimeType = selectedMimeType;
                }

                // Apply high quality constraints to the stream for recording
                this.applyTrainerRecordingConstraints(videoSettings);

                this.mediaRecorder = new MediaRecorder(this.screenStream, options);

                this.mediaRecorder.ondataavailable = (event) => {
                    console.log('Data available:', event.data.size, 'bytes');
                    if (event.data && event.data.size > 0) {
                        this.recordedChunks.push(event.data);
                    }
                };

                this.mediaRecorder.onstop = () => {
                    console.log('Recording stopped, total chunks:', this.recordedChunks.length);
                    this.processAndDownloadTrainerRecording(selectedMimeType);
                };

                this.mediaRecorder.onerror = (event) => {
                    console.error('MediaRecorder error:', event.error);
                    this.showNotification('Recording error: ' + event.error, 'error');
                    this.isRecording = false;
                    this.updateRecordingUI(false);
                };

                this.mediaRecorder.onstart = () => {
                    console.log('Recording started successfully with settings:', options);
                    this.isRecording = true;
                    this.updateRecordingUI(true);
                    this.recordingStartTime = new Date();
                };

                console.log('Trainer recording system initialized successfully');
                return true;

            } catch (error) {
                console.error('Recording initialization failed:', error);
                this.showNotification('Recording setup failed: ' + error.message, 'error');
                return false;
            }
        }

        // NEW: Apply high quality constraints for trainer recording
        applyTrainerRecordingConstraints(settings) {
            const videoTracks = this.screenStream.getVideoTracks();
            const audioTracks = this.screenStream.getAudioTracks();

            // Apply video constraints for better screen recording quality
            videoTracks.forEach(track => {
                if (track) {
                    try {
                        track.applyConstraints({
                            width: { ideal: settings.width },
                            height: { ideal: settings.height },
                            frameRate: { ideal: settings.frameRate },
                            // Additional quality settings for screen sharing
                            displaySurface: 'window',
                            cursor: 'always'
                        }).then(() => {
                            console.log('Trainer video constraints applied:', track.getSettings());
                        }).catch(err => {
                            console.warn('Could not apply trainer video constraints:', err);
                        });
                    } catch (error) {
                        console.log('Trainer video constraints not adjustable');
                    }
                }
            });

            // Apply audio constraints for better recording quality
            audioTracks.forEach(track => {
                if (track) {
                    try {
                        track.applyConstraints({
                            channelCount: 2,
                            sampleRate: 48000,
                            sampleSize: 16,
                            // For recording, we want clean audio without processing
                            echoCancellation: false,
                            autoGainControl: false,
                            noiseSuppression: false,
                            googEchoCancellation: false,
                            googAutoGainControl: false,
                            googNoiseSuppression: false
                        }).then(() => {
                            console.log('Trainer audio constraints applied:', track.getSettings());
                        }).catch(err => {
                            console.warn('Could not apply trainer audio constraints:', err);
                        });
                    } catch (error) {
                        console.log('Trainer audio constraints not adjustable');
                    }
                }
            });
        }

        // ENHANCED: Process and download trainer recording with format conversion
        async processAndDownloadTrainerRecording(mimeType) {
            if (this.recordedChunks.length === 0) {
                this.showNotification('No recording data available to download', 'warning');
                return;
            }

            try {
                const blob = new Blob(this.recordedChunks, { 
                    type: mimeType || 'video/webm' 
                });

                console.log('Trainer recording blob size:', blob.size, 'bytes');

                if (blob.size === 0) {
                    this.showNotification('Recording file is empty', 'error');
                    return;
                }

                let finalBlob = blob;
                let finalExtension = this.getTrainerFileExtension(mimeType);

                // If recording is in WebM and we want MP4, try to convert
                if (blob.type.includes('webm') && this.shouldConvertToMp4()) {
                    try {
                        this.showNotification('Converting to MP4 format...', 'info');
                        finalBlob = await this.convertWebmToMp4(blob);
                        finalExtension = 'mp4';
                        console.log('Successfully converted trainer WebM to MP4');
                    } catch (conversionError) {
                        console.warn('Trainer WebM to MP4 conversion failed, using original:', conversionError);
                        // Fallback to original WebM
                        finalExtension = 'webm';
                        this.showNotification('Using WebM format (MP4 conversion failed)', 'warning');
                    }
                }

                this.downloadTrainerRecording(finalBlob, finalExtension);
                this.recordedChunks = []; // Clear chunks after download

            } catch (error) {
                console.error('Trainer recording processing error:', error);
                this.showNotification('Failed to process recording: ' + error.message, 'error');
            }
        }

        // NEW: Convert WebM to MP4 for trainer recordings
        async convertWebmToMp4(webmBlob) {
            // Check if FFmpeg is available for conversion
            if (typeof FFmpeg !== 'undefined') {
                return this.convertWithFfmpeg(webmBlob);
            } else {
                // Fallback to simple method
                return this.convertWebmToMp4Simple(webmBlob);
            }
        }

        // NEW: Convert using FFmpeg.js (high quality)
        async convertWithFfmpeg(webmBlob) {
            return new Promise((resolve, reject) => {
                const ffmpeg = new FFmpeg();
                const inputFileName = 'trainer_input.webm';
                const outputFileName = 'trainer_output.mp4';

                ffmpeg.on('log', ({ message }) => {
                    console.log('FFmpeg log:', message);
                });

                ffmpeg.on('progress', ({ progress }) => {
                    const percent = Math.round(progress * 100);
                    console.log('Conversion progress:', percent + '%');
                    // Update notification with progress
                    const existingNotification = document.querySelector('.ihd-notification');
                    if (existingNotification) {
                        existingNotification.textContent = `Converting to MP4... ${percent}%`;
                    }
                });

                ffmpeg.load().then(async () => {
                    try {
                        // Write WebM file to virtual file system
                        const webmData = new Uint8Array(await webmBlob.arrayBuffer());
                        await ffmpeg.writeFile(inputFileName, webmData);

                        // Convert to MP4 with optimal settings for screen recording
                        await ffmpeg.exec([
                            '-i', inputFileName,
                            '-c:v', 'libx264',           // H.264 video codec
                            '-preset', 'medium',         // Good balance of speed/quality
                            '-crf', '18',                // High quality (0-51, lower is better)
                            '-profile:v', 'high',        // High profile for better quality
                            '-level', '4.0',             // H.264 level
                            '-c:a', 'aac',               // AAC audio codec
                            '-b:a', '192k',              // Audio bitrate
                            '-ac', '2',                  // Stereo audio
                            '-movflags', '+faststart',   // Optimize for web playback
                            '-pix_fmt', 'yuv420p',       // Standard pixel format
                            outputFileName
                        ]);

                        // Read converted MP4 file
                        const mp4Data = await ffmpeg.readFile(outputFileName);
                        const mp4Blob = new Blob([mp4Data], { type: 'video/mp4' });

                        resolve(mp4Blob);
                    } catch (error) {
                        reject(error);
                    }
                }).catch(reject);
            });
        }

        // NEW: Simple WebM to MP4 conversion (fallback)
        async convertWebmToMp4Simple(webmBlob) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = function() {
                    try {
                        // Create a new blob with MP4 extension
                        // Note: This doesn't actually convert the codec, just changes the container
                        const arrayBuffer = reader.result;
                        const mp4Blob = new Blob([arrayBuffer], { type: 'video/mp4' });
                        resolve(mp4Blob);
                    } catch (error) {
                        reject(error);
                    }
                };
                reader.onerror = reject;
                reader.readAsArrayBuffer(webmBlob);
            });
        }

        // NEW: Get appropriate file extension for trainer
        getTrainerFileExtension(mimeType) {
            if (mimeType.includes('mp4')) return 'mp4';
            if (mimeType.includes('webm')) return 'webm';
            return 'mp4'; // Default to MP4
        }

        // NEW: Determine if we should attempt MP4 conversion
        shouldConvertToMp4() {
            // Check if browser supports MP4 recording natively
            const supportsMp4 = MediaRecorder.isTypeSupported('video/mp4;codecs=h264,aac') ||
                               MediaRecorder.isTypeSupported('video/mp4;codecs=avc1.42E01E,mp4a.40.2');

            // Only convert if native MP4 isn't supported
            return !supportsMp4;
        }

        // ENHANCED: Download trainer recording file
        downloadTrainerRecording(blob, extension) {
            try {
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                const timestamp = new Date().toISOString()
                    .replace(/[:.]/g, '-')
                    .replace('T', '_')
                    .split('.')[0];

                a.href = url;
                a.download = `trainer-class-recording-${this.config.meetingId}-${timestamp}.${extension}`;
                a.style.display = 'none';

                document.body.appendChild(a);
                a.click();

                // Cleanup
                setTimeout(() => {
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                }, 100);

                // Show detailed success notification
                const duration = this.calculateTrainerRecordingDuration();
                const fileSize = this.formatFileSize(blob.size);

                this.showNotification(`🎥 Recording downloaded! (${duration}, ${fileSize}, ${extension.toUpperCase()})`, 'success');

                // Log recording details
                console.log('Trainer recording details:', {
                    format: extension,
                    size: blob.size,
                    duration: duration,
                    quality: 'High',
                    meetingId: this.config.meetingId
                });

            } catch (error) {
                console.error('Trainer download error:', error);
                this.showNotification('Failed to download recording: ' + error.message, 'error');
            }
        }

        // NEW: Calculate trainer recording duration
        calculateTrainerRecordingDuration() {
            if (!this.recordingStartTime) return 'Unknown duration';
            const duration = Math.round((new Date() - this.recordingStartTime) / 1000);
            const minutes = Math.floor(duration / 60);
            const seconds = duration % 60;
            return `${minutes}m ${seconds}s`;
        }

        // NEW: Format file size for display
        formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // UPDATED: Start recording for trainer with high quality
        startRecording() {
            if (!this.screenStream || !this.isScreenSharing) {
                this.showNotification('Please start screen sharing first', 'warning');

                // Visual feedback - pulse the screen share button
                const screenShareBtn = document.getElementById('screenShareBtn');
                if (screenShareBtn) {
                    screenShareBtn.style.animation = 'pulse 0.5s 3';
                    setTimeout(() => screenShareBtn.style.animation = '', 1500);
                }
                return;
            }

            if (!this.mediaRecorder) {
                if (!this.initializeRecording()) {
                    return;
                }
            }

            if (this.mediaRecorder.state === 'inactive') {
                try {
                    this.recordedChunks = [];
                    this.recordingStartTime = new Date();
                    this.mediaRecorder.start(500); // Collect data every 500ms for better quality
                    this.showNotification('High quality recording started', 'success');
                } catch (error) {
                    console.error('Failed to start recording:', error);
                    this.showNotification('Failed to start recording: ' + error.message, 'error');
                    this.isRecording = false;
                    this.updateRecordingUI(false);
                }
            } else if (this.mediaRecorder.state === 'recording') {
                this.stopRecording();
            }
        }

        // UPDATED: Stop recording for trainer
        stopRecording() {
            if (this.mediaRecorder && this.mediaRecorder.state === 'recording') {
                try {
                    this.mediaRecorder.stop();
                    this.isRecording = false;
                    this.showNotification('Recording stopped - processing download...', 'info');
                } catch (error) {
                    console.error('Error stopping recording:', error);
                    this.showNotification('Error stopping recording', 'error');
                }
            } else {
                this.isRecording = false;
                this.updateRecordingUI(false);
            }
        }

        // Add FFmpeg loader for trainer (call this in your initialization)
        loadTrainerFfmpeg() {
            if (typeof FFmpeg === 'undefined') {
                const script = document.createElement('script');
                script.src = 'https://unpkg.com/@ffmpeg/ffmpeg@0.12.4/dist/ffmpeg.min.js';
                script.onload = () => console.log('FFmpeg loaded for trainer MP4 conversion');
                document.head.appendChild(script);
            }
        }


        // NEW: Update recording UI method
        updateRecordingUI(isRecording) {
            const recordBtn = document.getElementById('recordBtn');
            const recordingIndicator = document.getElementById('recordingIndicator');

            if (isRecording) {
                recordBtn.classList.add('recording');
                recordBtn.innerHTML = '⏺️ Stop Recording';
                if (recordingIndicator) recordingIndicator.style.display = 'block';
            } else {
                recordBtn.classList.remove('recording');
                recordBtn.innerHTML = '⏺️ Start Recording';
                recordingIndicator.style.display = 'none';
            }
        }

        

        // IMPROVED: Download recording method
        downloadRecording() {
            if (this.recordedChunks.length === 0) {
                this.showNotification('No recording data available to download', 'warning');
                return;
            }

            try {
                const blob = new Blob(this.recordedChunks, { 
                    type: 'video/webm' 
                });

                console.log('Recording blob size:', blob.size, 'bytes');

                if (blob.size === 0) {
                    this.showNotification('Recording file is empty', 'error');
                    return;
                }

                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                const timestamp = new Date().toISOString()
                    .replace(/[:.]/g, '-')
                    .replace('T', '_')
                    .split('.')[0];

                a.href = url;
                a.download = `class-recording-${this.config.meetingId}-${timestamp}.webm`;
                a.style.display = 'none';

                document.body.appendChild(a);
                a.click();

                // Cleanup
                setTimeout(() => {
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                }, 100);

                this.showNotification('Recording downloaded successfully!', 'success');
                this.recordedChunks = []; // Clear chunks after download

            } catch (error) {
                console.error('Download error:', error);
                this.showNotification('Failed to download recording: ' + error.message, 'error');
            }
        }

        // Broadcast screen stream to students
        broadcastScreenStream() {
            this.connections.forEach((conn, studentId) => {
                if (conn && conn.open) {
                    conn.send({
                        type: 'screen_share',
                        action: 'started',
                        sender: 'trainer',
                        sender_name: this.config.trainerName
                    });
                }
            });
        }
        handleStudentInfo(data, studentId) {
            console.log('Processing student info:', data);

            this.studentInfo.set(studentId, {
                name: data.student_name,
                is_admin: data.is_admin || false,
                peer_id: data.student_peer_id,
                added: false
            });

            this.addStudentParticipant(studentId);

            // Show appropriate notification
            if (data.is_admin) {
                this.showNotification('Administrator ' + data.student_name + ' joined the class', 'warning');
            } else {
                this.showNotification(data.student_name + ' joined the class', 'success');
            }

            // Broadcast to all other students
            this.broadcastStudentJoined(studentId, this.studentInfo.get(studentId));

            // Send current participant list to the new student
            this.sendParticipantListToStudent(studentId);

            // Add to otherStudents map for student-to-student communication
            this.otherStudents.set(studentId, {
                name: data.student_name,
                peer_id: data.student_peer_id,
                is_admin: data.is_admin || false
            });
        }
        // Add this method to TrainerScreenShareConference:
        sendParticipantListToAllStudents() {
            this.connections.forEach((conn, studentId) => {
                if (conn && conn.open) {
                    this.sendParticipantListToStudent(studentId);
                }
            });
        }
		// CRITICAL: Handle student data connections
        handleStudentConnection(conn) {
            const studentId = conn.peer;
            console.log('Student connection established:', studentId);

            conn.on('open', () => {
                console.log('Student data connection opened:', studentId);

                // Add to connections map
                this.connections.set(studentId, conn);

                // Request student info
                conn.send({
                    type: 'request_info',
                    sender: 'trainer'
                });

                // If screen is already sharing, call the student immediately
                if (this.isScreenSharing && this.screenStream) {
                    console.log(`Calling student ${studentId} with existing screen stream`);
                    this.callStudentWithScreen(studentId);
                }
            });

            conn.on('data', (data) => {
                this.handleMessage(data, studentId);
            });

            conn.on('close', () => {
                console.log('Student data connection closed:', studentId);
                this.connections.delete(studentId);
                this.removeStudentParticipant(studentId);
            });

            conn.on('error', (error) => {
                console.error('Student connection error:', error);
                this.connections.delete(studentId);
                this.removeStudentParticipant(studentId);
            });
        }

        // Helper method to call a specific student
        callStudentWithScreen(studentId) {
            if (!this.screenStream) {
                console.error('No screen stream available to call student');
                return;
            }

            console.log(`Calling student ${studentId} with screen stream`);

            // Close existing call if any
            if (this.calls.has(studentId)) {
                this.calls.get(studentId).close();
            }

            // Create new call
            const call = this.peer.call(studentId, this.screenStream);
            this.calls.set(studentId, call);

            call.on('stream', (remoteStream) => {
                console.log(`Student ${studentId} accepted the screen call`);
            });

            call.on('close', () => {
                console.log(`Screen call with student ${studentId} closed`);
                this.calls.delete(studentId);
            });

            call.on('error', (error) => {
                console.error(`Screen call error with student ${studentId}:`, error);
                this.calls.delete(studentId);
            });

            // Notify student about screen share
            const conn = this.connections.get(studentId);
            if (conn && conn.open) {
                conn.send({
                    type: 'screen_share',
                    action: 'started',
                    sender: 'trainer',
                    sender_name: this.config.trainerName
                });
            }
        }
        handleConnection(conn) {
            const studentId = conn.peer;
    
            conn.on('open', () => {
                console.log('Student connected:', studentId);
                this.connections.set(studentId, conn);

                // Request student info
                conn.send({
                    type: 'request_info',
                    sender: 'trainer'
                });

                // CRITICAL: If screen is already sharing, call the new student immediately
                if (this.isScreenSharing && this.screenStream) {
                    console.log(`Calling new student ${studentId} with existing screen stream`);
                    const call = this.peer.call(studentId, this.screenStream);
                    this.calls.set(studentId, call);

                    call.on('stream', (remoteStream) => {
                        console.log(`New student ${studentId} accepted the call`);
                    });

                    call.on('close', () => {
                        console.log(`Call with new student ${studentId} closed`);
                        this.calls.delete(studentId);
                    });

                    // Also notify the student
                    conn.send({
                        type: 'screen_share',
                        action: 'started',
                        sender: 'trainer',
                        sender_name: this.config.trainerName
                    });
                }
            });
            
            conn.on('data', (data) => {
                this.handleMessage(data, studentId);
            });
            
            conn.on('close', () => {
                console.log('Student disconnected:', studentId);
                this.connections.delete(studentId);
                this.removeStudentParticipant(studentId);
            });

            conn.on('error', (error) => {
                console.error('Connection error:', error);
            });
        }

        handleMessage(data, studentId) {
            console.log('Received message from student:', studentId, data);

            switch (data.type) {
                case 'student_info':
                    this.handleStudentInfo(data, studentId);
                    break;

                case 'chat':
                    this.handleIncomingChat(data, studentId);
                    break;

                case 'student_screen_share':
                    this.handleStudentScreenShareMessage(data, studentId);
                    break;

                case 'student_audio_share':
                    this.handleStudentAudioShareMessage(data, studentId);
                    break;

                default:
                    console.log('Unknown message type:', data.type);
            }
        }

        handleIncomingChat(data, studentId) {
            console.log('Received chat from student:', studentId, data);

            // Only display, DO NOT rebroadcast
            this.displayChatMessage({
                sender: 'student',
                sender_name: data.sender_name || this.studentInfo.get(studentId)?.name || 'Student',
                message: data.message,
                timestamp: data.timestamp || new Date().toISOString()
            });

            // REMOVE this line to prevent rebroadcasting:
            // this.broadcastChatToOtherStudents(data, studentId);
        }

        // In TrainerScreenShareConference class, update addStudentParticipant method
        addStudentParticipant(studentId) {
            const participantsList = document.getElementById('participantsList');
            const studentInfo = this.studentInfo.get(studentId);

            if (!studentInfo || document.getElementById('participant_' + studentId)) {
                return;
            }

            const isAdmin = studentInfo.is_admin || false;
            const role = isAdmin ? 'Administrator' : 'Student';
            const roleClass = isAdmin ? 'administrator' : 'student';

            const participantDiv = document.createElement('div');
            participantDiv.className = `ihd-participant ${roleClass}`;
            participantDiv.id = 'participant_' + studentId;

            participantDiv.innerHTML = `
                <div class="ihd-participant-avatar">
                    ${studentInfo.name.charAt(0).toUpperCase()}
                </div>
                <div class="ihd-participant-info">
                    <div class="ihd-participant-name">${studentInfo.name}</div>
                    <div class="ihd-participant-role">${role}</div>
                </div>
                <div class="ihd-participant-status" id="studentStatus_${studentId}">
                    Online
                </div>
                <div class="ihd-participant-controls">
                    <span class="ihd-participant-audio-status">🎤</span>
                    <button class="ihd-participant-control-btn ihd-mute-btn" onclick="trainerConference.muteStudent('${studentId}')" title="Mute Student">
                        🔇
                    </button>
                    <button class="ihd-participant-control-btn ihd-remove-btn" onclick="trainerConference.removeStudent('${studentId}')" title="Remove Student">
                        🚫
                    </button>
                </div>
            `;

            participantsList.appendChild(participantDiv);
            studentInfo.added = true;

            // Show special notification for admin
            if (isAdmin) {
                this.showNotification('Administrator joined the class', 'warning');
            }

            // Apply mute state if applicable
            if (this.mutedStudents.has(studentId)) {
                this.updateStudentMuteUI(studentId, true);
            }
        }
		broadcastChatToOtherStudents(data, senderStudentId) {
            this.connections.forEach((conn, studentId) => {
                if (studentId !== senderStudentId && conn && conn.open) {
                    conn.send({
                        type: 'chat',
                        message: data.message,
                        sender: 'student',
                        sender_name: data.student_name,
                        timestamp: data.timestamp
                    });
                }
            });
        }
        removeStudentParticipant(studentId) {
            const participantElement = document.getElementById('participant_' + studentId);
            if (participantElement) {
                participantElement.remove();
            }
            this.studentInfo.delete(studentId);
            this.mutedStudents.delete(studentId);
        }

        displayChatMessage(data) {
            const chatMessages = document.getElementById('chatMessages');
            const messageDiv = document.createElement('div');
            messageDiv.className = `ihd-chat-message ${data.sender === 'trainer' ? 'trainer' : ''}`;
            
            messageDiv.innerHTML = `
                <div class="ihd-chat-sender ${data.sender === 'trainer' ? 'trainer' : ''}">
                    ${data.sender_name}
                </div>
                <div class="ihd-chat-text">${this.escapeHtml(data.message)}</div>
                <div class="ihd-chat-timestamp">${new Date().toLocaleTimeString()}</div>
            `;
            
            chatMessages.appendChild(messageDiv);
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        // In TrainerScreenShareConference class - update endClass method
        async endClass() {
            if (confirm('Are you sure you want to end the class for all students? Recording will be downloaded if active.')) {

                // Show loading notification
                this.showNotification('Ending class for all students...', 'info');

                // Stop recording first
                if (this.isRecording) {
                    this.stopRecording();
                    // Wait a bit for recording to finalize
                    await new Promise(resolve => setTimeout(resolve, 1000));
                }

                // Stop screen sharing
                if (this.isScreenSharing) {
                    this.stopScreenShare();
                }

                // Notify all students to leave with immediate redirect
                this.connections.forEach((conn, studentId) => {
                    if (conn && conn.open) {
                        conn.send({
                            type: 'control',
                            action: 'end_call_redirect',
                            sender: 'trainer',
                            message: 'Class ended by trainer',
                            redirect_url: '<?php echo home_url('/completeclass'); ?>'
                        });
                    }
                });

                // Close all student calls
                this.calls.forEach((call, studentId) => {
                    if (call) {
                        call.close();
                        console.log(`Closed call with student: ${studentId}`);
                    }
                });

                // Close all data connections
                this.connections.forEach((conn, studentId) => {
                    if (conn) {
                        conn.close();
                        console.log(`Closed connection with student: ${studentId}`);
                    }
                });

                // Clear all maps
                this.connections.clear();
                this.calls.clear();
                this.studentInfo.clear();
                this.mutedStudents.clear();

                // End batch session in database
                await this.endBatchSession();

                this.leaveMeeting();

                this.showNotification('Class ended successfully! All students have been disconnected.', 'success');

                // Redirect trainer after delay
                setTimeout(() => {
                    window.location.href = '<?php echo home_url('/trainer'); ?>';
                }, 2000);
            }
        }
        async endBatchSession() {
            try {
                const response = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'ihd_end_batch_session',
                        batch_id: this.config.batchId,
                        meeting_id: this.config.meetingId,
                        nonce: '<?php echo wp_create_nonce('ihd_video_nonce'); ?>'
                    })
                });
                
                const result = await response.json();
                console.log('Batch session ended:', result);
            } catch (error) {
                console.error('Failed to end batch session:', error);
            }
        }
		toggleRecording() {
            if (this.isRecording) {
                this.stopRecording();
            } else {
                this.startRecording();
            }
        }
        leaveMeeting() {
            // Close all media streams
            if (this.screenStream) {
                this.screenStream.getTracks().forEach(track => {
                    if (track) {
                        track.stop();
                    }
                });
                this.screenStream = null;
            }

            // Destroy peer connection
            if (this.peer) {
                this.peer.destroy();
            }

            // Clear any intervals
            if (this.feedbackInterval) {
                clearInterval(this.feedbackInterval);
                this.feedbackInterval = null;
            }

            console.log('Trainer left meeting and destroyed all connections');
        }

        // Utility methods
        escapeHtml(unsafe) {
            return unsafe
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }
        
        showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = 'ihd-notification';
            notification.style.background = type === 'error' ? '#e74c3c' : 
                                          type === 'warning' ? '#e67e22' : 
                                          type === 'success' ? '#27ae60' : '#3498db';
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.remove();
            }, 5000);
        }
    }

    // Global functions for trainer
    let trainerConference;
    
    document.addEventListener('DOMContentLoaded', function() {
        trainerConference = new TrainerScreenShareConference();
        trainerConference.initializeConference();
    });
    
    function toggleRecording() {
        if (!trainerConference) {
            console.error('Trainer conference not initialized');
            return;
        }

        const recordBtn = document.getElementById('recordBtn');
		const recordingIndicator=document.getElementById('recordingIndicator');
        if (trainerConference.isRecording) {
            trainerConference.stopRecording();
            if (recordBtn) {
                recordBtn.classList.remove('recording');
                recordBtn.innerHTML = '⏺️ Start Recording';
                recordingIndicator.style.display = 'none';
            }
        } else {
            trainerConference.startRecording(); // Call startRecording, not toggleRecording
            if (recordBtn) {
                recordBtn.classList.add('recording');
                recordBtn.innerHTML = '⏺️ Stop Recording';
            }
        }
    }

    function toggleMuteAll() {
        if (!trainerConference) return;
        
        if (trainerConference.isAllMuted) {
            trainerConference.unmuteAllStudents();
        } else {
            trainerConference.muteAllStudents();
        }
    }
    
    function toggleScreenShare() {
        if (trainerConference) trainerConference.toggleScreenShare();
    }
    
    function toggleAudio() {
        if (trainerConference) trainerConference.toggleAudio();
    }
    
    function toggleFullscreen() {
        const elem = document.documentElement;
        if (!document.fullscreenElement) {
            elem.requestFullscreen();
        } else {
            document.exitFullscreen();
        }
    }
    
    function endClass() {
        if (trainerConference) trainerConference.endClass();
    }
    
    // For Trainer - Enhanced chat function
    function handleChatInput(event) {
        if (event.key === 'Enter' && trainerConference) {
            const input = document.getElementById('chatInput');
            const message = input.value.trim();

            if (message) {
                // Check if we have data connections
                if (trainerConference.dataConnections && trainerConference.dataConnections.size > 0) {
                    // Send to all students via data connections
                    trainerConference.dataConnections.forEach((conn, studentId) => {
                        if (conn && conn.open) {
                            try {
                                conn.send({
                                    type: 'chat',
                                    message: message,
                                    sender: 'trainer',
                                    sender_name: trainerConference.config.trainerName,
                                    timestamp: new Date().toISOString()
                                });
                                console.log('✅ Message sent to student:', studentId);
                            } catch (error) {
                                console.error('❌ Failed to send to student:', studentId, error);
                            }
                        }
                    });

                    // Display locally
                    trainerConference.displayChatMessage({
                        sender: 'trainer',
                        sender_name: trainerConference.config.trainerName,
                        message: message,
                        timestamp: new Date().toISOString()
                    });

                    input.value = '';
                } else {
                    console.warn('⚠️ No data connections available for chat');
                    trainerConference.showNotification('No students connected for chat', 'warning');

                    // Still display locally
                    trainerConference.displayChatMessage({
                        sender: 'trainer',
                        sender_name: trainerConference.config.trainerName,
                        message: message,
                        timestamp: new Date().toISOString()
                    });
                    input.value = '';
                }
            }
        }
    }
    
    // Call this when trainer interface loads
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof trainerConference !== 'undefined') {
            loadTrainerFfmpeg();
        }
    });
    window.addEventListener('beforeunload', () => {
        if (trainerConference) {
            trainerConference.leaveMeeting();
        }
    });
    </script>

    <style>
     .ihd-trainer-controls{
            background:#116473 !important;
         	border: 2px solid #116473 !important;
     }

     .ihd-participants-actions {
        display: flex;
        gap: 5px;
        margin-left: auto;
    }

    .ihd-participant-action-btn {
        background: #34495e;
        color: white;
        border: none;
        padding: 5px 10px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.8em;
        transition: background 0.3s;
    }

    .ihd-participant-action-btn:hover {
        background: #4a6a8b;
    }

    .ihd-participant-controls {
        display: flex;
        gap: 5px;
        align-items: center;
    }

    .ihd-participant-control-btn {
        background: #34495e;
        color: white;
        border: none;
        padding: 4px 8px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.8em;
        transition: background 0.3s;
    }

    .ihd-participant-control-btn:hover {
        background: #4a6a8b;
    }

    .ihd-mute-btn:hover {
        background: #e74c3c;
    }

    .ihd-remove-btn:hover {
        background: #c0392b;
    }

    .ihd-participant-audio-status {
        font-size: 0.9em;
        min-width: 20px;
        text-align: center;
    }

    .ihd-participant-audio-status.muted {
        color: #e74c3c;
    }
     .ihd-participant.administrator .ihd-participant-avatar {
        background: #e67e22 !important;
    }

    .ihd-participant.administrator .ihd-participant-role {
        color: #e67e22 !important;
        font-weight: bold;
    }
    .ihd-video-conference {
        width: 100%;
        height: 100vh;
        background: #1a1a1a;
        color: white;
        font-family: Arial, sans-serif;
        overflow: hidden;
        position: relative;
    }

    .ihd-conference-interface {
        display: flex;
        flex-direction: column;
        height: 100%;
        padding: 10px;
        box-sizing: border-box;
        gap: 10px;
    }
	/* Student Screen Share Styles - Enhanced for Fullscreen */
    .ihd-student-screens-container {
        flex: 1;
        min-width: 400px;
        max-width: 500px;
        display: flex;
        flex-direction: column;
        gap: 10px;
        max-height: 100%;
        overflow-y: auto;
    }

    .ihd-student-screen {
        background: #2c3e50;
        border-radius: 10px;
        overflow: hidden;
        border: 2px solid #3498db;
        position: relative;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .ihd-student-screen:hover {
        border-color: #2980b9;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    }

    .ihd-student-screen.fullscreen-active {
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        width: 100vw !important;
        height: 100vh !important;
        z-index: 10000 !important;
        background: #000 !important;
        border-radius: 0 !important;
        border: none !important;
    }

    .ihd-student-screen.fullscreen-active .ihd-student-screen-video {
        width: 100% !important;
        height: 100% !important;
        object-fit: contain !important;
    }

    .ihd-student-screen.fullscreen-active .ihd-student-screen-header {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        background: rgba(52, 73, 94, 0.9);
        z-index: 10001;
        padding: 15px 20px;
    }

    .ihd-student-screen-header {
        background: #34495e;
        padding: 8px 12px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.9em;
        font-weight: bold;
        transition: all 0.3s ease;
    }

    .ihd-student-name {
        color: #3498db;
    }

    .ihd-close-student-screen {
        background: #e74c3c;
        color: white;
        border: none;
        border-radius: 50%;
        width: 24px;
        height: 24px;
        cursor: pointer;
        font-size: 16px;
        line-height: 1;
        transition: all 0.3s ease;
    }

    .ihd-close-student-screen:hover {
        background: #c0392b;
        transform: scale(1.1);
    }

    .ihd-student-screen-video {
        width: 100%;
        height: 300px;
        object-fit: contain;
        background: #000;
    }

    /* Fullscreen overlay hint */
    .ihd-student-screen::after {
        content: "Double-click for fullscreen";
        position: absolute;
        bottom: 10px;
        left: 50%;
        transform: translateX(-50%);
        background: rgba(0,0,0,0.8);
        color: white;
        padding: 6px 12px;
        border-radius: 15px;
        font-size: 0.7em;
        opacity: 0;
        transition: opacity 0.3s;
        pointer-events: none;
    }

    .ihd-student-screen:hover::after {
        opacity: 1;
    }

    /* Hide other elements when student screen is in fullscreen */
    .ihd-student-screen.fullscreen-active ~ * {
        display: none !important;
    }

    /* Exit fullscreen button for student screens */
    .ihd-exit-fullscreen-btn {
        position: fixed;
        top: 20px;
        right: 20px;
        background: #e74c3c;
        color: white;
        border: none;
        padding: 10px 15px;
        border-radius: 5px;
        cursor: pointer;
        z-index: 10002;
        font-size: 0.9em;
    }

    .ihd-exit-fullscreen-btn:hover {
        background: #c0392b;
    }

    /* Responsive adjustments */
    @media (max-width: 1200px) {
        .ihd-student-screens-container {
            min-width: 350px;
            max-width: 400px;
        }
    }

    @media (max-width: 1024px) {
        .ihd-video-container {
            flex-wrap: wrap;
        }

        .ihd-student-screens-container {
            order: 2;
            min-width: 100%;
            max-width: 100%;
            flex-direction: row;
            overflow-x: auto;
            max-height: 300px;
        }

        .ihd-student-screen {
            min-width: 400px;
        }

        .ihd-sidebar {
            order: 3;
            min-width: 100%;
        }
    }
    .ihd-video-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px 20px;
        background: #2c3e50;
        border-radius: 10px;
        margin-bottom: 10px;
        flex-wrap: wrap;
        gap: 10px;
    }
	/* Audio feedback warning styles */
    .ihd-audio-warning {
        background: #116473 !important;
        animation: warning-pulse 1s infinite;
    }

    @keyframes warning-pulse {
        0% { opacity: 1; }
        50% { opacity: 0.7; }
        100% { opacity: 1; }
    }

    .ihd-audio-feedback-detected {
        border: 2px solid #e74c3c !important;
    }
    .ihd-meeting-info h2 {
        margin: 0;
        font-size: 1.4em;
        color: #ecf0f1;
    }

    .ihd-meeting-info p {
        margin: 5px 0 0 0;
        font-size: 0.9em;
        color: #bdc3c7;
    }

    .ihd-meeting-controls {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .ihd-control-btn {
        background: #34495e;
        color: white;
        border: none;
        padding: 10px 15px;
        border-radius: 5px;
        cursor: pointer;
        font-size: 0.9em;
        transition: background 0.3s;
    }

    .ihd-control-btn:hover {
        background: #4a6a8b;
    }

    .ihd-video-container {
        display: flex;
        flex: 1;
        gap: 15px;
        min-height: 0;
        overflow: hidden;
    }

    .ihd-screen-share-container {
        flex: 3;
        background: #2c3e50;
        border-radius: 10px;
        overflow: hidden;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .ihd-screen-placeholder {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        height: 100%;
        background: #34495e;
    }

    .ihd-screen-placeholder-content {
        text-align: center;
        padding: 40px;
    }

    .ihd-screen-icon {
        font-size: 4em;
        margin-bottom: 20px;
    }

    .ihd-screen-placeholder-content h3 {
        margin: 0 0 10px 0;
        color: #ecf0f1;
    }

    .ihd-screen-placeholder-content p {
        margin: 5px 0;
        color: #bdc3c7;
    }

    .ihd-screen-note {
        font-size: 0.9em;
        color: #95a5a6 !important;
        font-style: italic;
    }

    .ihd-screen-share-container video {
        width: 100%;
        height: 100%;
        object-fit: contain;
        background: #000;
    }

    .ihd-screen-overlay {
        position: absolute;
        top: 15px;
        left: 15px;
        right: 15px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .ihd-screen-info {
        display: flex;
        align-items: center;
        gap: 10px;
        background: rgba(0,0,0,0.7);
        padding: 8px 15px;
        border-radius: 20px;
        color: white;
    }

    .ihd-screen-indicator {
        color: #e74c3c;
        font-weight: bold;
    }

    .ihd-recording-indicator {
        background: rgba(231, 76, 60, 0.9);
        color: white;
        padding: 8px 15px;
        border-radius: 20px;
        font-weight: bold;
        animation: pulse 1.5s infinite;
    }

    @keyframes pulse {
        0% { opacity: 1; }
        50% { opacity: 0.7; }
        100% { opacity: 1; }
    }

    .ihd-sidebar {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 15px;
        min-width: 300px;
        max-width: 400px;
    }

    .ihd-chat-container, .ihd-participants-container {
        background: #2c3e50;
        border-radius: 10px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    .ihd-chat-header, .ihd-participants-header {
        background: #34495e;
        padding: 15px;
        margin: 0;
    }

    .ihd-chat-messages {
        flex: 1;
        padding: 15px;
        overflow-y: auto;
        max-height: 300px;
        
    }

    .ihd-chat-input-container {
        padding: 15px;
        border-top: 1px solid #34495e;
    }

    .ihd-chat-input {
        width: 100%;
        padding: 12px;
        border: none;
        border-radius: 5px;
        background: #34495e;
        color: white;
        box-sizing: border-box;
    }

    .ihd-participants-list {
        flex: 1;
        padding: 15px;
        overflow-y: auto;
        max-height: 300px;
    }

    .ihd-participant {
        display: flex;
        align-items: center;
        padding: 10px;
        margin-bottom: 10px;
        background: #34495e;
        border-radius: 8px;
        gap: 10px;
    }

    .ihd-participant-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: #3498db;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
    }

    .ihd-participant.trainer .ihd-participant-avatar {
        background: #065444;
    }

    .ihd-participant-info {
        flex: 1;
    }

    .ihd-participant-name {
        font-weight: bold;
        font-size: 0.9em;
    }

    .ihd-participant-role {
        font-size: 0.8em;
        color: #bdc3c7;
    }

    .ihd-participant-status {
        font-size: 0.8em;
        color: #27ae60;
        font-weight: bold;
    }

    /* Fixed Controls Bar */
    .ihd-controls-bar {
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 15px 20px;
        background: #2c3e50;
        border-radius: 10px;
        gap: 15px;
        flex-wrap: wrap;
        margin-top: 10px;
    }

    .ihd-main-control {
        background: #34495e;
        color: white;
        border: none;
        padding: 12px 20px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 1em;
        transition: all 0.3s;
        min-width: 120px;
    }

    .ihd-main-control:hover {
        background: #4a6a8b;
        transform: translateY(-2px);
    }

    .ihd-main-control.end-call {
        background: #0c5754;
        font-weight: bold;
    }

    .ihd-main-control.end-call:hover {
        background: #c0392b;
    }

    /* Notification styles */
    .ihd-notification {
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 15px 20px;
        border-radius: 5px;
        color: white;
        z-index: 10000;
        animation: slideIn 0.3s ease;
    }

    @keyframes slideIn {
        from { transform: translateX(100%); }
        to { transform: translateX(0); }
    }

    /* Recording state */
    .recording {
        background: #e74c3c !important;
        animation: pulse 1.5s infinite;
    }

    /* Chat messages */
    .ihd-chat-message {
        margin-bottom: 15px;
        padding: 10px;
        background: #34495e;
        border-radius: 8px;
    }

    .ihd-chat-message.trainer {
        background: #34495e;
        border-left: 4px solid #e67e22;
    }

    .ihd-chat-sender {
        font-weight: bold;
        font-size: 0.8em;
        margin-bottom: 5px;
    }

    .ihd-chat-sender.trainer {
        color: #e67e22;
    }

    .ihd-chat-text {
        font-size: 0.9em;
        line-height: 1.4;
    }

    .ihd-chat-timestamp {
        font-size: 0.7em;
        color: #bdc3c7;
        text-align: right;
        margin-top: 5px;
    }

    /* Responsive design */
    @media (max-width: 1024px) {
        .ihd-video-container {
            flex-direction: column;
        }
        
        .ihd-sidebar {
            max-width: none;
            min-width: auto;
        }
    }

    @media (max-width: 768px) {
        .ihd-video-header {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .ihd-meeting-controls {
            width: 100%;
            justify-content: center;
            margin-top: 10px;
        }
        
        .ihd-controls-bar {
            flex-direction: column;
            gap: 10px;
        }
        
        .ihd-main-control {
            width: 100%;
            max-width: 200px;
        }

        .ihd-participant-controls {
            flex-direction: column;
            gap: 2px;
        }

        .ihd-participant-control-btn {
            padding: 2px 4px;
            font-size: 0.7em;
        }
    }
    </style>
    <?php
    return ob_get_clean();
}

// Student Meeting Join Shortcode - Screen Share Only
function ihd_student_meeting_join_shortcode() {
    // Check if admin is accessing
    $is_admin_access = isset($_GET['admin_access']) && $_GET['admin_access'] === 'true' && current_user_can('administrator');

    if (!$is_admin_access) {
        // Original name verification for students
        if (!isset($_GET['meeting_join']) || !isset($_GET['batch_id'])) {
            return '<div class="error">Invalid meeting link.</div>';
        }

        $meeting_id = sanitize_text_field($_GET['meeting_join']);
        $batch_id = sanitize_text_field($_GET['batch_id']);

     
    } else {
        // Admin access - skip name verification
        $meeting_id = sanitize_text_field($_GET['meeting_join']);
        $batch_id = sanitize_text_field($_GET['batch_id']);
        $studentName = 'Admin'; // Set admin as the name
    }
    
   
    
    global $wpdb;
    $batch_table = $wpdb->prefix . 'ihd_batch_sessions';
    $batch = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $batch_table WHERE meeting_id = %s AND batch_id = %s AND status = 'active'",
        $meeting_id, $batch_id
    ));
    
    if (!$batch) {
        return '<div class="error">Class session not found or ended.</div>';
    }
    
    $batch_students = get_posts(array(
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
    
    $student_names = array_map(function($s) { return $s->post_title; }, $batch_students);
    $trainer = get_user_by('id', $batch->trainer_id);
    $trainer_name = $trainer ? trim($trainer->first_name . ' ' . $trainer->last_name) : 'Trainer';
    $course_name = get_term($batch->course_id, 'module')->name ?? '';
    
    ob_start();
    ?>
    
    <div class="ihd-name-verification" id="nameVerification">
        <h2>🎓 Join Screen Share Class Session</h2>
        
        <div class="ihd-batch-info">
            <p><strong>Course:</strong> <?php echo esc_html($course_name); ?></p>
            <p><strong>Trainer:</strong> <?php echo esc_html($trainer_name); ?></p>
            <p><strong>Session Type:</strong> Screen Sharing + Audio</p>
        </div>
        
        <p>Please enter your full name as registered to join the class:</p>
        
        <input type="text" id="studentNameInput" placeholder="Enter your full name" autocomplete="off">
        <div class="ihd-name-error" id="nameError">Name not found in this batch. Please check your name and try again.</div>
        
        <button onclick="verifyStudentName()">✅ Join Class</button>
        
        <div style="margin-top: 20px; font-size: 14px; color: #7f8c8d;">
            <strong>Registered Students in this Batch:</strong><br>
            <?php echo implode(', ', array_map('esc_html', $student_names)); ?>
        </div>
    </div>

    <div class="ihd-video-conference" id="ihdVideoConference" style="display: none;">
    </div>

    <script>
    const registeredStudents = <?php echo $is_admin_access ? '[]' : json_encode($student_names); ?>;
    const meetingId = '<?php echo esc_js($meeting_id); ?>';
    const batchId = '<?php echo esc_js($batch_id); ?>';
    const trainerName = '<?php echo esc_js($trainer_name); ?>';
    const courseName = '<?php echo esc_js($course_name); ?>';
    const isAdminAccess = <?php echo $is_admin_access ? 'true' : 'false'; ?>;

    let studentName = '<?php echo $is_admin_access ? 'Admin' : ''; ?>';
    let studentConference;
        <?php if ($is_admin_access): ?>
        // Auto-load for admin
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('nameVerification').style.display = 'none';
            document.getElementById('ihdVideoConference').style.display = 'block';
            loadStudentMeetingInterface();
        });
        <?php else: ?>
        // Original student name verification code
        function verifyStudentName() {
            const input = document.getElementById('studentNameInput');
            const error = document.getElementById('nameError');
            const name = input.value.trim();

            if (!name) {
                error.textContent = 'Please enter your name';
                error.style.display = 'block';
                return;
            }

            const found = registeredStudents.some(registered => 
                registered.toLowerCase() === name.toLowerCase()
            );

            if (found) {
                studentName = name;
                document.getElementById('nameVerification').style.display = 'none';
                document.getElementById('ihdVideoConference').style.display = 'block';
                loadStudentMeetingInterface();
                error.style.display = 'none';
            } else {
                error.textContent = 'Name not found in this batch. Please check your name and try again.';
                error.style.display = 'block';
                input.focus();
            }
        }

        // Make sure Enter key works in name input
        document.getElementById('studentNameInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') verifyStudentName();
        });
        <?php endif; ?>


    
   
    function loadStudentMeetingInterface() {
        const conferenceDiv = document.getElementById('ihdVideoConference');
        conferenceDiv.innerHTML = `
            <div class="ihd-conference-interface">
                <div class="ihd-video-header">
                    <div class="ihd-meeting-info">
                        <h2>🎓 Screen Share Class - ${courseName}</h2>
                        <p>Trainer: <strong>${trainerName}</strong> | Student: <strong>${studentName}</strong></p>
                    </div>
                    <div class="ihd-meeting-controls">
                        <button class="ihd-control-btn" id="recordBtn" onclick="toggleStudentRecording()">
                            ⏺️ Start Recording
                        </button>
                        <button class="ihd-control-btn" onclick="toggleStudentFullscreen()">
                            🖥️ Fullscreen
                        </button>
                    </div>
                </div>

                <div class="ihd-video-container">
                    <!-- Student Screen View Area -->
                    <div class="ihd-screen-share-container" id="screenShareContainer">
                        <div class="ihd-screen-placeholder" id="screenPlaceholder">
                            <div class="ihd-screen-placeholder-content">
                                <div class="ihd-screen-icon">👨‍🏫</div>
                                <h3>Waiting for Trainer to Share Screen</h3>
                                <p>The trainer will start sharing their screen shortly</p>
                                <p class="ihd-screen-note">You will see the trainer's screen and hear their audio</p>
                            </div>
                        </div>
                        <video id="screenShareVideo" autoplay playsinline style="display: none;"></video>
                        <div class="ihd-screen-overlay" id="screenOverlay" style="display: none;">
                            
                            <div class="ihd-recording-indicator" id="recordingIndicator" style="display: none;">
                                ⏺️ RECORDING
                            </div>
                        </div>
                    </div>

                    <div class="ihd-sidebar">
                        <div class="ihd-chat-container">
                            <div class="ihd-chat-header">
                                <h3 style="margin: 0; color: #3498db;">💬 Class Chat</h3>
                            </div>
                            <div class="ihd-chat-messages" id="chatMessages" style="height:"1vh">
                            </div>
                            <div class="ihd-chat-input-container">
                                <input type="text" class="ihd-chat-input" id="chatInput" 
                                       placeholder="Type your message..." 
                                       onkeypress="handleStudentChatInput(event)">
                            </div>
                        </div>

                        <div class="ihd-participants-container">
                            <div class="ihd-participants-header">
                                <h3 style="margin: 0; color: #3498db;">👥 Participants</h3>
                            </div>
                            <div class="ihd-participants-list" id="participantsList">
                                <div class="ihd-participant trainer">
                                    <div class="ihd-participant-avatar">
                                        ${trainerName.charAt(0).toUpperCase()}
                                    </div>
                                    <div class="ihd-participant-info">
                                        <div class="ihd-participant-name">${trainerName}</div>
                                        <div class="ihd-participant-role">Trainer</div>
                                    </div>
                                    <div class="ihd-participant-status" id="trainerStatus">
                                        Online
                                    </div>
                                    <div class="ihd-participant-controls">
                                        <span class="ihd-participant-audio-status">🎤</span>
                                    </div>
                                </div>
                                <div class="ihd-participant student">
                                    <div class="ihd-participant-avatar">
                                        ${studentName.charAt(0).toUpperCase()}
                                    </div>
                                    <div class="ihd-participant-info">
                                        <div class="ihd-participant-name">${studentName}</div>
                                        <div class="ihd-participant-role">Student (You)</div>
                                    </div>
                                    <div class="ihd-participant-status" id="studentStatus">
                                        Online
                                    </div>
                                    <div class="ihd-participant-controls">
                                        <span class="ihd-participant-audio-status" id="studentAudioStatus">🎤</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="ihd-controls-bar">
                    <button class="ihd-main-control" id="screenShareBtn" onclick="toggleStudentScreenShare()">
                        🖥️ Share My Screen
                    </button>
                     <button class="ihd-main-control" id="audioShareBtn" onclick="toggleStudentAudioShare()">
                        🎤 Unmute
                    </button>
                    
                    <button class="ihd-main-control end-call" onclick="leaveStudentMeeting()">
                        📞 Leave Class
                    </button>
                </div>
            </div>
        `;

        initializeStudentConference();
    }

    // Student Screen Share Conference Class - FIXED VERSION
    class StudentScreenShareConference {
        constructor() {
            this.localScreenStream = null;
            this.trainerScreenStream = null;
            this.isScreenSharing = false;
            this.isAudioEnabled = true;
            this.recordedChunks = [];
            this.mediaRecorder = null;
            this.isRecording = false;
            this.peer = null;
            this.conn = null;
            this.currentCall = null;
            this.isConnected = false;
            this.attendanceStartTime = null;
            this.audioStream = null;
            this.isAudioSharing = false;
            this.currentStudentCall = null;
            this.currentAudioCall = null;
            this.lastMessageHash = null;
            this.isMuted = false; // Track if student is muted by trainer
  		    this.messageCooldown = 1000; // 1 second cooldown
			this.otherStudents = new Map(); // studentId -> {name, peer_id, is_admin, connection, call}
        	this.studentCalls = new Map(); // studentId -> call object
        	this.studentConnections = new Map(); // studentId -> data connection
            // In the constructor, update the config
            this.config = {
                meetingId: meetingId,
                batchId: batchId,
                studentName: studentName,
                trainerName: trainerName,
                courseName: courseName,
                isAdmin: isAdminAccess
            };

            this.initializeDoubleClickFullscreen();
        }

        // NEW: Handle mute/unmute from trainer
        handleMuteCommand(isMuted) {
            this.isMuted = isMuted;
            
            // Update UI
            this.updateMuteUI(isMuted);
            
            // Apply mute to all audio streams
            this.applyMuteToAllAudioStreams(isMuted);
            
            if (isMuted) {
                this.showNotification('Trainer muted your audio', 'warning');
            } else {
                this.showNotification('Trainer unmuted your audio', 'success');
            }
        }

        // NEW: Apply mute state to all audio streams
        applyMuteToAllAudioStreams(isMuted) {
            // Mute screen share audio if sharing
            if (this.localScreenStream && this.isScreenSharing) {
                const audioTracks = this.localScreenStream.getAudioTracks();
                audioTracks.forEach(track => {
                    if (track) {
                        track.enabled = !isMuted;
                    }
                });
            }

            // Mute separate audio stream if sharing
            if (this.audioStream && this.isAudioSharing) {
                const audioTracks = this.audioStream.getAudioTracks();
                audioTracks.forEach(track => {
                    if (track) {
                        track.enabled = !isMuted;
                    }
                });
            }

            // Update audio share button state
            if (this.isAudioSharing) {
                this.updateAudioShareUI(!isMuted);
            }
        }

        // NEW: Update mute UI
        updateMuteUI(isMuted) {
            const audioStatus = document.getElementById('studentAudioStatus');
            if (audioStatus) {
                audioStatus.textContent = isMuted ? '🔇' : '🎤';
                audioStatus.className = `ihd-participant-audio-status ${isMuted ? 'muted' : ''}`;
            }

            const audioShareBtn = document.getElementById('audioShareBtn');
            if (audioShareBtn) {
                if (isMuted) {
                    audioShareBtn.innerHTML = '🔇 Muted by Trainer';
                    audioShareBtn.style.background = '#e74c3c';
                    audioShareBtn.disabled = true;
                } else {
                    audioShareBtn.innerHTML = this.isAudioSharing ? '🎤 Mute' : '🎤 Unmute';
                    audioShareBtn.style.background = this.isAudioSharing ? '#e74c3c' : '';
                    audioShareBtn.disabled = false;
                }
            }
        }

		isDuplicateMessage(message, timestamp) {
            const hash = `${message}_${timestamp}`;
            if (this.lastMessageHash === hash) {
                return true;
            }
            this.lastMessageHash = hash;
            return false;
        }
        initializeDoubleClickFullscreen() {
            const screenContainer = document.getElementById('screenShareContainer');
            if (screenContainer) {
                screenContainer.addEventListener('dblclick', () => {
                    this.toggleFullscreenMode();
                });
            }

            // Listen for fullscreen changes
            document.addEventListener('fullscreenchange', () => this.handleFullscreenChange());
            document.addEventListener('webkitfullscreenchange', () => this.handleFullscreenChange());
        }
		// Replace the current connectToOtherStudents method:
        async connectToOtherStudents() {
            console.log('Connecting to other students, total:', this.otherStudents.size);

            for (const [studentId, studentInfo] of this.otherStudents) {
                if (studentId !== this.peer.id && !this.studentConnections.has(studentId)) {
                    await this.connectToStudent(studentId, studentInfo);
                }
            }
        }
        // Add this method to StudentScreenShareConference class:
        handleStudentScreenShareBroadcast(data) {
            console.log('Student screen share broadcast:', data);

            if (data.action === 'started') {
                // Call the student who is sharing their screen
                this.callStudentForScreen(data.student_id);
            } else if (data.action === 'stopped') {
                // Close the call
                if (this.studentCalls.has(data.student_id)) {
                    this.studentCalls.get(data.student_id).close();
                    this.studentCalls.delete(data.student_id);
                }
                this.handleStudentScreenStreamEnded(data.student_id);
            }
        }
        // Add this method to StudentScreenShareConference class:
        handleStudentScreenStreamEnded(studentId) {
            const studentScreen = document.getElementById(`studentScreen_${studentId}`);
            if (studentScreen) {
                studentScreen.remove();
            }

            const studentInfo = this.otherStudents.get(studentId);
            if (studentInfo) {
                this.showNotification(`${studentInfo.name} stopped screen sharing`, 'info');
            }
        }
        // Add this method to StudentScreenShareConference class:
        closeStudentScreen(studentId) {
            const studentScreen = document.getElementById(`studentScreen_${studentId}`);
            if (studentScreen) {
                studentScreen.remove();
            }

            // Close the call if it exists
            if (this.studentCalls.has(studentId)) {
                this.studentCalls.get(studentId).close();
                this.studentCalls.delete(studentId);
            }

            this.showNotification('Student screen closed', 'info');
        }
        // Add this method to StudentScreenShareConference class:
        handleStudentScreenStream(studentId, remoteStream) {
            console.log(`Handling screen stream from student ${studentId}`);

            // Create student screen container if it doesn't exist
            let studentScreenContainer = document.getElementById('studentScreenContainer');

            if (!studentScreenContainer) {
                studentScreenContainer = document.createElement('div');
                studentScreenContainer.id = 'studentScreenContainer';
                studentScreenContainer.className = 'ihd-student-screens-container';

                const sidebar = document.querySelector('.ihd-sidebar');
                const videoContainer = document.querySelector('.ihd-video-container');

                if (videoContainer && sidebar) {
                    videoContainer.insertBefore(studentScreenContainer, sidebar);
                }
            }

            // Create or update individual student screen
            let studentScreen = document.getElementById(`studentScreen_${studentId}`);
            const studentInfo = this.otherStudents.get(studentId);

            if (!studentScreen) {
                studentScreen = document.createElement('div');
                studentScreen.id = `studentScreen_${studentId}`;
                studentScreen.className = 'ihd-student-screen';

                studentScreen.innerHTML = `
                    <div class="ihd-student-screen-header">
                        <span class="ihd-student-name">${studentInfo?.name || 'Student'}'s Screen</span>
                        <button class="ihd-close-student-screen" onclick="studentConference.closeStudentScreen('${studentId}')">×</button>
                    </div>
                    <video class="ihd-student-screen-video" autoplay playsinline></video>
                `;

                studentScreenContainer.appendChild(studentScreen);
            }

            // Set the video stream
            const videoElement = studentScreen.querySelector('.ihd-student-screen-video');
            if (videoElement) {
                videoElement.srcObject = remoteStream;
            }

            this.showNotification(`${studentInfo?.name || 'Student'} is sharing their screen`, 'success');
        }
        async connectToStudent(studentId, studentInfo) {
            try {
                console.log(`Connecting to student: ${studentId} (${studentInfo.name})`);

                // Create data connection
                const conn = this.peer.connect(studentId, {
                    reliable: true,
                    serialization: 'json'
                });

                this.studentConnections.set(studentId, conn);

                conn.on('open', () => {
                    console.log(`Connected to student ${studentInfo.name}`);

                    // Exchange student info
                    conn.send({
                        type: 'student_handshake',
                        student_id: this.peer.id,
                        student_name: this.config.studentName,
                        is_admin: this.config.isAdmin
                    });
                });

                conn.on('data', (data) => {
                    this.handleStudentMessage(data, studentId);
                });

                conn.on('close', () => {
                    console.log(`Connection to student ${studentInfo.name} closed`);
                    this.studentConnections.delete(studentId);
                    this.removeStudentParticipant(studentId);
                });

                conn.on('error', (error) => {
                    console.error(`Connection error with student ${studentInfo.name}:`, error);
                    this.studentConnections.delete(studentId);
                });

            } catch (error) {
                console.error(`Failed to connect to student ${studentInfo.name}:`, error);
            }
        }
        handleStudentMessage(data, studentId) {
            console.log('Received message from student:', studentId, data);

            switch (data.type) {
                case 'student_handshake':
                    this.handleStudentHandshake(data, studentId);
                    break;

                case 'student_screen_share':
                    this.handleStudentScreenShareMessage(data, studentId);
                    break;

                case 'student_audio_share':
                    this.handleStudentAudioShareMessage(data, studentId);
                    break;

                case 'chat':
                    this.displayChatMessage({
                        sender: 'student',
                        sender_name: data.student_name || this.otherStudents.get(studentId)?.name || 'Student',
                        message: data.message,
                        timestamp: data.timestamp || new Date().toISOString()
                    });
                    break;
            }
        }
        handleStudentHandshake(data, studentId) {
            this.otherStudents.set(studentId, {
                name: data.student_name,
                peer_id: data.student_id,
                is_admin: data.is_admin || false,
                added: false
            });

            this.addStudentParticipant(studentId);
        }
        // Add this method to StudentScreenShareConference class:
        handleParticipantList(data) {
            console.log('Received participant list:', data.participants);

            // Clear existing participants first
            this.otherStudents.clear();

            data.participants.forEach(participant => {
                if (participant.student_id !== this.peer.id) {
                    this.otherStudents.set(participant.student_id, {
                        name: participant.student_name,
                        peer_id: participant.student_id,
                        is_admin: participant.is_admin || false,
                        added: false
                    });

                    this.addStudentParticipant(participant.student_id);
                }
            });

            // Connect to all other students
            this.connectToOtherStudents();

            console.log('Updated participant list with:', this.otherStudents.size, 'other students');
        }
        handleStudentScreenShareMessage(data, studentId) {
            if (data.action === 'started') {
                // Call the student who is sharing their screen
                this.callStudentForScreen(studentId);
            } else if (data.action === 'stopped') {
                // Close the call
                if (this.studentCalls.has(studentId)) {
                    this.studentCalls.get(studentId).close();
                    this.studentCalls.delete(studentId);
                }
                this.handleStudentScreenStreamEnded(studentId);
            }
        }
		 callStudentForScreen(studentId) {
            if (this.studentCalls.has(studentId)) {
                this.studentCalls.get(studentId).close();
            }

            const call = this.peer.call(studentId);
            this.studentCalls.set(studentId, call);

            call.on('stream', (remoteStream) => {
                console.log(`Received screen stream from student ${studentId}`);
                this.handleStudentScreenStream(studentId, remoteStream);
            });

            call.on('close', () => {
                console.log(`Screen call with student ${studentId} ended`);
                this.studentCalls.delete(studentId);
                this.handleStudentScreenStreamEnded(studentId);
            });

            call.on('error', (error) => {
                console.error(`Screen call error with student ${studentId}:`, error);
                this.studentCalls.delete(studentId);
            });
        }
		handleIncomingStudentCall(call) {
            console.log('Received call from student:', call.peer);

            // Only answer if we're sharing our screen
            if (this.isScreenSharing && this.localScreenStream) {
                call.answer(this.localScreenStream);
            } else {
                call.answer(); // Answer with no stream if not sharing
            }

            call.on('stream', (remoteStream) => {
                console.log(`Received stream from student ${call.peer}`);
                // You can handle streams from other students here if needed
            });

            call.on('close', () => {
                console.log(`Call from student ${call.peer} ended`);
            });
        }

        toggleFullscreenMode() {
            const screenContainer = document.getElementById('screenShareContainer');
            if (!screenContainer) return;

            if (!document.fullscreenElement) {
                if (screenContainer.requestFullscreen) {
                    screenContainer.requestFullscreen();
                } else if (screenContainer.webkitRequestFullscreen) {
                    screenContainer.webkitRequestFullscreen();
                }
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                } else if (document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                }
            }
        }

        handleFullscreenChange() {
            const screenContainer = document.getElementById('screenShareContainer');
            const fullscreenBtn = document.querySelector('.ihd-control-btn[onclick*="toggleStudentFullscreen"]');

            if (document.fullscreenElement) {
                screenContainer?.classList.add('fullscreen-active');
                if (fullscreenBtn) {
                    fullscreenBtn.innerHTML = '🖥️ Exit Fullscreen';
                }
            } else {
                screenContainer?.classList.remove('fullscreen-active');
                if (fullscreenBtn) {
                    fullscreenBtn.innerHTML = '🖥️ Fullscreen';
                }
            }
        }

        async initializeConference() {
            try {
                console.log('Initializing student conference...');

                this.peer = new Peer({
                    config: {
                        'iceServers': [
                            { urls: 'stun:stun.l.google.com:19302' },
                            { urls: 'stun:global.stun.twilio.com:3478' }
                        ]
                    },
                    debug: 3 // Increased debug level
                });

                this.peer.on('open', (id) => {
                    console.log('Student connected with ID:', id);
                    this.joinMeeting();
                });

                // Add this to student peer initialization:
                this.peer.on('call', (call) => {
                    console.log('Incoming call from:', call.peer);

                    if (call.peer === this.config.meetingId) {
                        // Call from trainer
                        this.handleIncomingCall(call);
                    } else {
                        // Call from another student
                        this.handleIncomingStudentCall(call);
                    }
                });

                this.peer.on('error', (err) => {
                    console.error('Peer error:', err);
                    this.showNotification('Connection error: ' + err.type, 'error');

                    // Retry connection after 3 seconds
                    setTimeout(() => {
                        if (!this.isConnected) {
                            this.showNotification('Retrying connection...', 'info');
                            this.connectToTrainer();
                        }
                    }, 3000);
                });

            } catch (error) {
                console.error('Failed to initialize conference:', error);
                this.showNotification('Failed to connect to class', 'error');
            }
        }
        // Add this method to handle student calls:
        handleIncomingStudentCall(call) {
            // Answer with our audio stream if we're sharing audio
            if (this.isAudioSharing && this.audioStream) {
                call.answer(this.audioStream);
            } else {
                call.answer(); // Answer with no stream
            }

            call.on('stream', (remoteStream) => {
                console.log(`Received stream from student ${call.peer}`);
                this.handleStudentAudioStream(call.peer, remoteStream);
            });

            call.on('close', () => {
                console.log(`Call from student ${call.peer} ended`);
            });
        }
        // In StudentScreenShareConference - Enhanced connectToTrainer method
        async connectToTrainer(maxRetries = 5) {
            for (let attempt = 1; attempt <= maxRetries; attempt++) {
                try {
                    console.log(`🔄 Student connection attempt ${attempt}/${maxRetries} to trainer:`, this.config.meetingId);

                    // Wait a bit before retrying (except first attempt)
                    if (attempt > 1) {
                        await new Promise(resolve => setTimeout(resolve, 2000 * attempt));
                    }

                    this.conn = this.peer.connect(this.config.meetingId, {
                        reliable: true,
                        serialization: 'json'
                    });

                    if (!this.conn) {
                        throw new Error('Failed to create connection object');
                    }

                    // Wait for connection to open with timeout
                    await new Promise((resolve, reject) => {
                        const timeout = setTimeout(() => {
                            reject(new Error('Connection timeout'));
                        }, 10000); // 10 second timeout

                        this.conn.on('open', () => {
                            clearTimeout(timeout);
                            console.log('✅ Student connected to trainer successfully');
                            this.isConnected = true;

                            // Update UI
                            const trainerStatus = document.getElementById('trainerStatus');
                            if (trainerStatus) {
                                trainerStatus.textContent = 'Online';
                                trainerStatus.style.color = '#27ae60';
                            }

                            // Send student info
                            this.sendToTrainer({
                                type: 'student_info',
                                student_name: this.config.studentName,
                                student_peer_id: this.peer.id,
                                is_admin: this.config.isAdmin
                            });

                            this.showNotification('Connected to trainer successfully', 'success');
                            resolve();
                        });

                        this.conn.on('error', (error) => {
                            clearTimeout(timeout);
                            reject(error);
                        });
                    });

                    // Set up data handler
                    this.conn.on('data', (data) => {
                        console.log('📨 Received data from trainer:', data);
                        this.handleMessage(data);
                    });

                    this.conn.on('close', () => {
                        console.log('❌ Connection to trainer closed');
                        this.handleDisconnection();
                    });

                    this.conn.on('error', (error) => {
                        console.error('❌ Connection error:', error);
                        this.handleDisconnection();
                    });

                    // Success - break out of retry loop
                    break;

                } catch (error) {
                    console.error(`❌ Connection attempt ${attempt} failed:`, error);

                    if (attempt === maxRetries) {
                        this.showNotification(`Failed to connect after ${maxRetries} attempts. Please refresh the page.`, 'error');
                    } else {
                        this.showNotification(`Connection attempt ${attempt} failed, retrying...`, 'warning');
                    }
                }
            }
        }

        // Safe message sending to trainer
        sendToTrainer(data) {
            if (this.isConnectionReady(this.conn)) {
                try {
                    this.conn.send(data);
                    return true;
                } catch (error) {
                    console.error('Failed to send to trainer:', error);
                    this.showNotification('Failed to send message to trainer', 'error');
                    return false;
                }
            } else {
                console.warn('Connection to trainer not ready');
                this.showNotification('Not connected to trainer', 'warning');
                return false;
            }
        }

        // Connection state checker
        isConnectionReady(conn) {
            return conn && conn.open && !conn.disconnected;
        }

        // Handle disconnection
        handleDisconnection() {
            this.isConnected = false;
            const trainerStatus = document.getElementById('trainerStatus');
            if (trainerStatus) {
                trainerStatus.textContent = 'Disconnected';
                trainerStatus.style.color = '#e74c3c';
            }
            this.showNotification('Disconnected from trainer', 'warning');

            // Auto-reconnect after 5 seconds
            setTimeout(() => {
                if (!this.isConnected) {
                    this.showNotification('Attempting to reconnect...', 'info');
                    this.connectToTrainer(3); // 3 retry attempts for reconnection
                }
            }, 5000);
        }

        // Update joinMeeting method
        async joinMeeting() {
            try {
                this.attendanceStartTime = new Date();
                await this.trackAttendance('join');

                // Wait for peer to be ready before connecting to trainer
                if (this.peer && this.peer.id) {
                    await this.connectToTrainer();
                } else {
                    this.showNotification('Waiting for connection setup...', 'info');
                    // This will be handled by the peer 'open' event
                }
            } catch (error) {
                console.error('Failed to join meeting:', error);
                this.showNotification('Failed to join meeting', 'error');
            }
        }

        // Handle incoming call from trainer with screen stream
        handleIncomingCall(call) {
            console.log('Received call from trainer:', call.peer);

            // Answer the call
            call.answer();

            this.currentCall = call;

            call.on('stream', (remoteStream) => {
                console.log('Received screen stream from trainer');
                this.handleTrainerScreenStream(remoteStream);
            });

            call.on('close', () => {
                console.log('Trainer call ended');
                this.handleTrainerScreenStreamEnded();
                this.currentCall = null;
            });

            call.on('error', (error) => {
                console.error('Call error:', error);
                this.showNotification('Screen sharing connection error', 'error');
            });
        }

        handleTrainerScreenStream(remoteStream) {
            this.trainerScreenStream = remoteStream;
            const screenVideo = document.getElementById('screenShareVideo');

            if (screenVideo) {
                screenVideo.srcObject = remoteStream;
                this.updateScreenView(true, 'trainer');
                this.showNotification('Trainer started screen sharing', 'success');

                // Initialize recording when screen stream is available
                this.initializeRecording();
            }

            // Handle track ended events
            const tracks = remoteStream.getTracks();
            tracks.forEach(track => {
                if (track) {
                    track.onended = () => {
                        console.log('Trainer screen track ended');
                        this.handleTrainerScreenStreamEnded();
                    };
                }
            });
        }

        handleTrainerScreenStreamEnded() {
            this.trainerScreenStream = null;
            const screenVideo = document.getElementById('screenShareVideo');

            if (screenVideo) {
                screenVideo.srcObject = null;
                this.updateScreenView(false, 'trainer');
            }

            // Stop recording if active
            if (this.isRecording) {
                this.stopRecording();
            }

            this.showNotification('Trainer stopped screen sharing', 'info');
        }

        // OPTIMIZED: Student audio sharing with echo cancellation
        async toggleAudioShare() {
            try {
                if (!this.isAudioSharing) {
                    console.log('Student starting optimized audio share...');

                    // FIXED: Enhanced audio constraints for student
                    this.audioStream = await navigator.mediaDevices.getUserMedia({
                        audio: {
                            // Critical echo cancellation settings
                            echoCancellation: { exact: true },
                            noiseSuppression: { exact: true },
                            autoGainControl: { exact: true },

                            // Voice optimization
                            channelCount: 1, // MONO - reduces echo
                            sampleRate: 16000, // Voice-optimized sample rate
                            sampleSize: 16,
                            latency: 0.01,

                            // Chrome-specific optimizations
                            googEchoCancellation: true,
                            googNoiseSuppression: true,
                            googAutoGainControl: true,
                            googHighpassFilter: true,
                            googAudioMirroring: false, // Critical: prevent audio feedback

                            // Prevent multiple processing
                            advanced: [
                                { echoCancellation: true },
                                { googEchoCancellation: true },
                                { channelCount: 1 }
                            ]
                        },
                        video: false
                    });

                    // FIXED: Enhanced audio processing
                    const audioTracks = this.audioStream.getAudioTracks();

                    if (audioTracks.length === 0) {
                        throw new Error('No audio tracks available');
                    }

                    // Apply additional constraints to each track
                    audioTracks.forEach(track => {
                        if (track) {
                            track.applyConstraints({
                                echoCancellation: true,
                                noiseSuppression: true,
                                autoGainControl: true,
                                channelCount: 1,
                                sampleRate: 16000,
                                sampleSize: 16,
                                latency: 0.01
                            });

                            console.log('Student audio track configured:', track.getSettings());
                        }
                    });

                    this.isAudioSharing = true;
                    this.isAudioEnabled = true;

                    // Apply mute state if muted by trainer
                    if (this.isMuted) {
                        this.applyMuteToAllAudioStreams(true);
                    }

                    // Update UI
                    this.updateAudioShareUI(true);

                    // FIXED: Call trainer with optimized audio stream
                    if (this.peer) {
                        const audioCall = this.peer.call(this.config.meetingId, this.audioStream);
                        this.currentAudioCall = audioCall;

                        audioCall.on('stream', (remoteStream) => {
                            console.log('Audio call established with trainer');
                        });

                        audioCall.on('close', () => {
                            console.log('Student audio call closed');
                            this.currentAudioCall = null;
                        });

                        audioCall.on('error', (error) => {
                            console.error('Student audio call error:', error);
                            this.currentAudioCall = null;
                        });
                    }
					this.callOtherStudentsWithAudio();
                    // Setup audio monitoring to detect issues
                    this.setupStudentAudioMonitoring();

                    this.showNotification('🎤 You started sharing your audio with trainer', 'success');

                } else {
                    this.stopAudioShare();
                }
            } catch (error) {
                console.error('Student audio share error:', error);

                if (error.name === 'NotAllowedError') {
                    this.showNotification('Microphone permission denied. Please allow microphone access.', 'error');
                } else if (error.name === 'NotFoundError') {
                    this.showNotification('No microphone found. Please check your audio device.', 'error');
                } else if (error.name === 'NotReadableError') {
                    this.showNotification('Microphone is already in use by another application.', 'error');
                } else {
                    this.showNotification('Failed to share audio: ' + error.message, 'error');
                }
            }
        }
		// Add this method to Student Conference:
        callOtherStudentsWithAudio() {
            this.otherStudents.forEach((studentInfo, studentId) => {
                if (studentId !== this.peer.id && this.audioStream) {
                    const audioCall = this.peer.call(studentId, this.audioStream);

                    audioCall.on('stream', (remoteStream) => {
                        console.log(`Audio connected with student ${studentInfo.name}`);
                        this.handleStudentAudioStream(studentId, remoteStream);
                    });

                    audioCall.on('close', () => {
                        console.log(`Audio call with ${studentInfo.name} closed`);
                    });
                }
            });
        }

        // Add method to handle incoming student audio
        handleStudentAudioStream(studentId, remoteStream) {
            // Create audio element for other student's audio
            const studentAudio = document.createElement('audio');
            studentAudio.srcObject = remoteStream;
            studentAudio.autoplay = true;
            studentAudio.volume = 0.8;

            // Add to hidden container
            const audioContainer = document.getElementById('studentAudiosContainer') || 
                (() => {
                    const container = document.createElement('div');
                    container.id = 'studentAudiosContainer';
                    container.style.display = 'none';
                    document.body.appendChild(container);
                    return container;
                })();

            audioContainer.appendChild(studentAudio);
        }
        // NEW: Setup audio monitoring for student
        setupStudentAudioMonitoring() {
            if (!this.audioStream) return;

            const audioTracks = this.audioStream.getAudioTracks();

            audioTracks.forEach(track => {
                if (track) {
                    try {
                        const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                        const source = audioContext.createMediaStreamSource(new MediaStream([track]));
                        const analyser = audioContext.createAnalyser();

                        analyser.fftSize = 256;
                        source.connect(analyser);

                        // Monitor audio levels for potential issues
                        const checkAudioLevels = () => {
                            const dataArray = new Uint8Array(analyser.frequencyBinCount);
                            analyser.getByteFrequencyData(dataArray);

                            const average = dataArray.reduce((a, b) => a + b) / dataArray.length;

                            // If audio levels are consistently high, reapply constraints
                            if (average > 180) {
                                console.log('High audio levels detected - reapplying constraints');
                                track.applyConstraints({
                                    echoCancellation: true,
                                    autoGainControl: true,
                                    noiseSuppression: true
                                });
                            }
                        };

                        // Check every 3 seconds
                        this.audioMonitorInterval = setInterval(checkAudioLevels, 3000);

                    } catch (error) {
                        console.log('Student audio monitoring not available:', error);
                    }
                }
            });
        }

        // UPDATED: Stop audio share with cleanup
        stopAudioShare() {
            // Clean up monitoring
            if (this.audioMonitorInterval) {
                clearInterval(this.audioMonitorInterval);
                this.audioMonitorInterval = null;
            }

            if (this.audioStream) {
                this.audioStream.getTracks().forEach(track => {
                    if (track) {
                        track.stop();
                    }
                });
                this.audioStream = null;
            }

            if (this.currentAudioCall) {
                this.currentAudioCall.close();
                this.currentAudioCall = null;
            }

            this.isAudioSharing = false;

            // Notify trainer
            if (this.conn && this.conn.open) {
                this.conn.send({
                    type: 'student_audio_share',
                    action: 'stopped',
                    student_name: this.config.studentName
                });
            }

            this.updateAudioShareUI(false);
            this.showNotification('🔇 You stopped sharing audio', 'info');
        }

        

        updateAudioShareUI(isSharing) {
            const audioShareBtn = document.getElementById('audioShareBtn');
            if (audioShareBtn) {
                if (isSharing) {
                    audioShareBtn.innerHTML = '🎤 Mute';
                    audioShareBtn.style.background = '#e74c3c';
                } else {
                    audioShareBtn.innerHTML = '🎤 Unmute';
                    audioShareBtn.style.background = '';
                }
            }
        }

        // Enhanced Student Screen Sharing with Audio
        async toggleScreenShare() {
            try {
                if (!this.isScreenSharing) {
                    console.log('Student starting screen share with audio...');

                    // Get user media with audio FIRST
                    const userMedia = await navigator.mediaDevices.getUserMedia({
                        audio: {
                            echoCancellation: true,
                            noiseSuppression: true,
                            sampleRate: 44100,
                            channelCount: 2,
                            autoGainControl: true
                        },
                        video: false
                    });

                    // Then get screen share
                    const screenStream = await navigator.mediaDevices.getDisplayMedia({
                        video: {
                            cursor: "always",
                            displaySurface: "window",
                            width: { ideal: 1280 },
                            height: { ideal: 720 },
                            frameRate: { ideal: 30 }
                        },
                        audio: false
                    });

                    // Combine both streams
                    this.localScreenStream = new MediaStream();

                    // Add screen video track
                    const videoTracks = screenStream.getVideoTracks();
                    videoTracks.forEach(track => {
                        if (track) {
                            this.localScreenStream.addTrack(track);
                        }
                    });

                    // Add user audio track
                    const audioTracks = userMedia.getAudioTracks();
                    audioTracks.forEach(track => {
                        if (track) {
                            this.localScreenStream.addTrack(track);
                            console.log('Added audio track to screen share:', track);
                        }
                    });

                    this.isScreenSharing = true;
                    this.isAudioEnabled = true;

                    // Apply mute state if muted by trainer
                    if (this.isMuted) {
                        this.applyMuteToAllAudioStreams(true);
                    }

                    // Update UI
                    this.updateScreenShareUI(true);
                    this.updateAudioUI(true);

                    // Notify trainer about student screen share WITH AUDIO
                    if (this.conn && this.conn.open) {
                        this.conn.send({
                            type: 'student_screen_share',
                            action: 'started',
                            student_name: this.config.studentName,
                            student_peer_id: this.peer.id,
                            has_audio: audioTracks.length > 0
                        });
                    }

                    // Call trainer with the combined screen + audio stream
                    if (this.peer) {
                        const call = this.peer.call(this.config.meetingId, this.localScreenStream);

                        this.currentStudentCall = call;

                        call.on('stream', (remoteStream) => {
                            console.log('Received stream in student call back');
                        });

                        call.on('close', () => {
                            console.log('Student screen share call closed');
                            this.currentStudentCall = null;
                        });

                        call.on('error', (error) => {
                            console.error('Student call error:', error);
                            this.currentStudentCall = null;
                        });
                    }

                    this.showNotification('You started screen sharing with audio', 'success');

                    // Handle when user stops sharing via browser controls
                    const videoTrack = screenStream.getVideoTracks()[0];
                    if (videoTrack) {
                        videoTrack.onended = () => {
                            this.stopScreenShare();
                        };
                    }

                } else {
                    this.stopScreenShare();
                }
            } catch (error) {
                console.error('Student screen share error:', error);

                if (error.name === 'NotAllowedError') {
                    this.showNotification('Screen sharing or microphone permission denied', 'error');
                } else if (error.name === 'NotFoundError') {
                    this.showNotification('No screen sharing source found', 'error');
                } else {
                    this.showNotification('Failed to share screen: ' + error.message, 'error');
                }
            }
            if (this.isScreenSharing) {
                // Notify trainer and all students
                if (this.conn && this.conn.open) {
                    this.conn.send({
                        type: 'student_screen_share',
                        action: 'started',
                        student_name: this.config.studentName,
                        student_peer_id: this.peer.id,
                        has_audio: audioTracks.length > 0
                    });
                }

                // Also notify other students directly
                this.studentConnections.forEach((conn, studentId) => {
                    if (conn && conn.open) {
                        conn.send({
                            type: 'student_screen_share',
                            action: 'started',
                            student_name: this.config.studentName,
                            student_peer_id: this.peer.id,
                            has_audio: audioTracks.length > 0
                        });
                    }
                });
            } else {
                // Notify everyone that screen sharing stopped
                if (this.conn && this.conn.open) {
                    this.conn.send({
                        type: 'student_screen_share',
                        action: 'stopped',
                        student_name: this.config.studentName
                    });
                }

                this.studentConnections.forEach((conn, studentId) => {
                    if (conn && conn.open) {
                        conn.send({
                            type: 'student_screen_share',
                            action: 'stopped',
                            student_name: this.config.studentName
                        });
                    }
                });
            }
        }
		sendChatToAll(message) {
            const chatData = {
                type: 'chat',
                message: message,
                sender: 'student',
                sender_name: this.config.studentName,
                timestamp: new Date().toISOString()
            };

            // Send to trainer
            if (this.conn && this.conn.open) {
                this.conn.send(chatData);
            }

            // Send to all other students (except ourselves)
            this.studentConnections.forEach((conn, studentId) => {
                if (conn && conn.open) {
                    conn.send(chatData);
                }
            });

            // Display locally ONLY ONCE
            this.displayChatMessage(chatData);
        }
    
        stopScreenShare() {
            if (this.localScreenStream) {
                this.localScreenStream.getTracks().forEach(track => {
                    if (track) {
                        track.stop();
                    }
                });
                this.localScreenStream = null;
            }

            this.isScreenSharing = false;

            // Update UI
            this.updateScreenShareUI(false);

            // Notify trainer
            if (this.conn && this.conn.open) {
                this.conn.send({
                    type: 'student_screen_share',
                    action: 'stopped',
                    student_name: this.config.studentName
                });
            }

            this.showNotification('You stopped screen sharing', 'info');
        }

        updateScreenShareUI(isSharing) {
            const screenShareBtn = document.getElementById('screenShareBtn');
            if (screenShareBtn) {
                if (isSharing) {
                    screenShareBtn.innerHTML = '🖥️ Stop Sharing';
                    screenShareBtn.style.background = '#e74c3c';
                } else {
                    screenShareBtn.innerHTML = '🖥️ Share My Screen';
                    screenShareBtn.style.background = '';
                }
            }
        }

        updateScreenView(isSharing, sharer) {
            const screenPlaceholder = document.getElementById('screenPlaceholder');
            const screenVideo = document.getElementById('screenShareVideo');
            const screenOverlay = document.getElementById('screenOverlay');
            const screenSharerText = document.getElementById('screenSharerText');

            if (isSharing) {
                if (screenPlaceholder) screenPlaceholder.style.display = 'none';
                if (screenVideo) screenVideo.style.display = 'block';
                if (screenOverlay) screenOverlay.style.display = 'flex';

                if (screenSharerText) {
                    if (sharer === 'trainer') {
                        screenSharerText.textContent = `${this.config.trainerName} is sharing screen`;
                    } else {
                        screenSharerText.textContent = 'You are sharing your screen';
                    }
                }
            } else {
                if (screenPlaceholder) screenPlaceholder.style.display = 'flex';
                if (screenVideo) screenVideo.style.display = 'none';
                if (screenOverlay) screenOverlay.style.display = 'none';
            }
        }

        handleMessage(data) {
            switch (data.type) {
                case 'screen_share':
                    this.handleScreenShareMessage(data);
                    break;
                case 'chat':
                    this.displayChatMessage({
                        sender: 'trainer',
                        sender_name: data.sender_name || this.config.trainerName,
                        message: data.message,
                        timestamp: data.timestamp || new Date().toISOString()
                    });
                    break;
                case 'student_joined':
                    this.handleStudentJoined(data);
                    break;

                case 'student_left':
                    this.handleStudentLeft(data);
                    break;

                case 'participant_list':
                    this.handleParticipantList(data);
                    break;

                case 'student_screen_share':
                    // Broadcast from trainer about student screen share
                    if (data.student_id !== this.peer.id) {
                        this.handleStudentScreenShareBroadcast(data);
                    }
                    break;
                case 'control':
                    this.handleControlMessage(data);
                    break;
            }
        }

        // UPDATED: Handle control messages including mute/unmute
        handleControlMessage(data) {
            if (data.action === 'mute') {
                this.handleMuteCommand(true);
            } else if (data.action === 'unmute') {
                this.handleMuteCommand(false);
            } else if (data.action === 'remove') {
                this.handleRemoveCommand(data);
            } else if (data.action === 'end_call_redirect') {
                // Show immediate notification
                this.showNotification('Class ended by trainer. Redirecting...', 'info');

                // Stop any active sharing immediately
                if (this.isScreenSharing) {
                    this.stopScreenShare();
                }
                if (this.isAudioSharing) {
                    this.stopAudioShare();
                }

                // Stop recording if active
                if (this.isRecording) {
                    this.stopRecording();
                }
				
                // IMMEDIATE redirect without delay
                const redirectUrl = data.redirect_url || '<?php echo home_url('/completeclass'); ?>';
                setTimeout(() => {
                    window.location.href = redirectUrl;
                }, 500); // Very short delay to allow notification to show

            } else if (data.action === 'end_call') {
                // Keep the old behavior as fallback
                this.showNotification('Class ended by trainer. You will be redirected shortly.', 'info');

                if (this.isScreenSharing) {
                    this.stopScreenShare();
                }
                if (this.isAudioSharing) {
                    this.stopAudioShare();
                }

                if (this.isRecording) {
                    this.stopRecording();
                }
				
                setTimeout(() => {
                    this.leaveMeeting();
                }, 500);
            }
        }

        // NEW: Handle remove command from trainer
        handleRemoveCommand(data) {
            this.showNotification(data.message || 'You have been removed from the class by the trainer', 'error');
            
            // Stop all activities
            if (this.isScreenSharing) this.stopScreenShare();
            if (this.isAudioSharing) this.stopAudioShare();
            if (this.isRecording) this.stopRecording();

            // Leave meeting immediately
            setTimeout(() => {
                this.leaveMeeting();
            }, 2000);
        }
		handleStudentJoined(data) {
            this.otherStudents.set(data.student_id, {
                name: data.student_name,
                peer_id: data.student_id,
                is_admin: data.is_admin || false,
                added: false
            });

            this.addStudentParticipant(data.student_id);
            this.connectToStudent(data.student_id, this.otherStudents.get(data.student_id));

            this.showNotification(data.student_name + ' joined the class', 'info');
        }
        handleStudentLeft(data) {
            this.removeStudentParticipant(data.student_id);
            this.otherStudents.delete(data.student_id);

            // Close connections
            if (this.studentConnections.has(data.student_id)) {
                this.studentConnections.get(data.student_id).close();
                this.studentConnections.delete(data.student_id);
            }

            if (this.studentCalls.has(data.student_id)) {
                this.studentCalls.get(data.student_id).close();
                this.studentCalls.delete(data.student_id);
            }
        }
        handleParticipantList(data) {
            data.participants.forEach(participant => {
                if (participant.student_id !== this.peer.id && participant.role !== 'trainer') {
                    this.otherStudents.set(participant.student_id, {
                        name: participant.student_name,
                        peer_id: participant.student_id,
                        is_admin: participant.is_admin || false,
                        added: false
                    });

                    this.addStudentParticipant(participant.student_id);
                }
            });

            // Connect to all other students
            this.connectToOtherStudents();
        }
		addStudentParticipant(studentId) {
            const participantsList = document.getElementById('participantsList');
            const studentInfo = this.otherStudents.get(studentId);

            if (!studentInfo || document.getElementById('participant_' + studentId)) {
                return;
            }

            const isAdmin = studentInfo.is_admin || false;
            const role = isAdmin ? 'Administrator' : 'Student';
            const roleClass = isAdmin ? 'administrator' : 'student';

            const participantDiv = document.createElement('div');
            participantDiv.className = `ihd-participant ${roleClass}`;
            participantDiv.id = 'participant_' + studentId;

            participantDiv.innerHTML = `
                <div class="ihd-participant-avatar">
                    ${studentInfo.name.charAt(0).toUpperCase()}
                </div>
                <div class="ihd-participant-info">
                    <div class="ihd-participant-name">${studentInfo.name}</div>
                    <div class="ihd-participant-role">${role}</div>
                </div>
                <div class="ihd-participant-status" id="studentStatus_${studentId}">
                    Online
                </div>
                <div class="ihd-participant-controls">
                    <span class="ihd-participant-audio-status">🎤</span>
                </div>
            `;

            participantsList.appendChild(participantDiv);
            studentInfo.added = true;
        }
        removeStudentParticipant(studentId) {
            const participantElement = document.getElementById('participant_' + studentId);
            if (participantElement) {
                participantElement.remove();
            }
        }
        handleScreenShareMessage(data) {
            if (data.action === 'started') {
                this.showNotification(data.sender_name + ' started screen sharing', 'info');
            } else if (data.action === 'stopped') {
                this.showNotification(data.sender_name + ' stopped screen sharing', 'info');
                if (data.sender === 'trainer') {
                    this.handleTrainerScreenStreamEnded();
                }
            }
        }

        // Audio Control for student
        async toggleAudio() {
            this.isAudioEnabled = !this.isAudioEnabled;

            const screenVideo = document.getElementById('screenShareVideo');
            if (screenVideo) {
                screenVideo.muted = !this.isAudioEnabled;
            }

            this.updateAudioUI(this.isAudioEnabled);

            if (this.isAudioEnabled) {
                this.showNotification('Audio enabled', 'success');
            } else {
                this.showNotification('Audio muted', 'warning');
            }
        }

        updateAudioUI(isEnabled) {
            const audioBtn = document.getElementById('audioBtn');
            if (audioBtn) {
                if (isEnabled) {
                    audioBtn.innerHTML = '🔊 Mute';
                    audioBtn.style.background = '';
                } else {
                    audioBtn.innerHTML = '🔇 Unmute';
                    audioBtn.style.background = '#e74c3c';
                }
            }
        }

        displayChatMessage(data) {
            if (this.isDuplicateMessage(data.message, data.timestamp)) {
                console.log('Duplicate message detected, skipping display');
                return;
            }
            const chatMessages = document.getElementById('chatMessages');
            if (!chatMessages) return;

            const messageDiv = document.createElement('div');
            messageDiv.className = `ihd-chat-message ${data.sender === 'trainer' ? 'trainer' : ''}`;

            messageDiv.innerHTML = `
                <div class="ihd-chat-sender ${data.sender === 'trainer' ? 'trainer' : ''}">
                    ${data.sender_name}
                </div>
                <div class="ihd-chat-text">${this.escapeHtml(data.message)}</div>
                <div class="ihd-chat-timestamp">${new Date().toLocaleTimeString()}</div>
            `;

            chatMessages.appendChild(messageDiv);
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        handleEndCall() {
            if (this.isRecording) {
                this.stopRecording();
            }

            if (this.isScreenSharing) {
                this.stopScreenShare();
            }

            this.showNotification('Class ended by trainer', 'info');
            this.leaveMeeting();
        }

        muteAudio() {
            this.isAudioEnabled = false;
            const screenVideo = document.getElementById('screenShareVideo');
            if (screenVideo) {
                screenVideo.muted = true;
            }
            this.updateAudioUI(false);
            this.showNotification('Trainer muted your audio', 'warning');
        }

        // ENHANCED: High Quality MP4 Recording System
        initializeRecording() {
            if (!this.trainerScreenStream) {
                console.log('No trainer stream available for recording');
                return false;
            }

            try {
                const videoTracks = this.trainerScreenStream.getVideoTracks();
                const audioTracks = this.trainerScreenStream.getAudioTracks();

                if (videoTracks.length === 0) {
                    console.warn('No video tracks in trainer stream for recording');
                    return false;
                }

                this.recordedChunks = [];

                // Enhanced video quality settings
                const videoSettings = {
                    // High quality video settings
                    width: 1920,
                    height: 1080,
                    frameRate: 30,
                    bitrate: 5000000, // 5 Mbps for high quality
                    audioBitrate: 192000 // 192 kbps for good audio quality
                };

                // Try MP4 codecs first, then fallback to WebM
                const mimeTypes = [
                    // MP4/H.264 codecs (preferred for compatibility and quality)
                    'video/mp4;codecs=h264,aac',
                    'video/mp4;codecs=avc1.42E01E,mp4a.40.2',
                    'video/mp4;codecs=h264,opus',
                    'video/mp4;codecs=avc1.428028,mp4a.40.2',

                    // High quality WebM fallbacks
                    'video/webm;codecs=vp9,opus',
                    'video/webm;codecs=vp9',
                    'video/webm;codecs=vp8,opus',
                    'video/webm;codecs=vp8',
                    'video/webm'
                ];

                let options = { 
                    videoBitsPerSecond: videoSettings.bitrate,
                    audioBitsPerSecond: videoSettings.audioBitrate,
                    mimeType: ''
                };

                // Find the best supported mimeType
                let selectedMimeType = '';
                for (let mimeType of mimeTypes) {
                    if (MediaRecorder.isTypeSupported(mimeType)) {
                        selectedMimeType = mimeType;
                        console.log('Selected mimeType:', mimeType);
                        break;
                    }
                }

                if (!selectedMimeType) {
                    console.warn('No supported mimeType found, using browser default');
                    // Let browser choose default with our quality settings
                    options = { 
                        videoBitsPerSecond: videoSettings.bitrate,
                        audioBitsPerSecond: videoSettings.audioBitrate
                    };
                } else {
                    options.mimeType = selectedMimeType;
                }

                // Apply high quality constraints to the stream
                this.applyRecordingConstraints(videoSettings);

                this.mediaRecorder = new MediaRecorder(this.trainerScreenStream, options);

                this.mediaRecorder.ondataavailable = (event) => {
                    if (event.data && event.data.size > 0) {
                        this.recordedChunks.push(event.data);
                        console.log('Recording chunk:', event.data.size, 'bytes');
                    }
                };

                this.mediaRecorder.onstop = () => {
                    console.log('Recording stopped, processing file...');
                    this.processAndDownloadRecording(selectedMimeType);
                };

                this.mediaRecorder.onerror = (event) => {
                    console.error('MediaRecorder error:', event.error);
                    this.showNotification('Recording error: ' + event.error, 'error');
                    this.isRecording = false;
                    this.updateStudentRecordingUI(false);
                };

                this.mediaRecorder.onstart = () => {
                    console.log('Recording started with settings:', options);
                    this.isRecording = true;
                    this.updateStudentRecordingUI(true);
                };

                console.log('High quality recording system initialized');
                return true;

            } catch (error) {
                console.error('Recording initialization failed:', error);
                this.showNotification('Recording setup failed: ' + error.message, 'error');
                return false;
            }
        }

        // NEW: Apply high quality constraints for recording
        applyRecordingConstraints(settings) {
            const videoTracks = this.trainerScreenStream.getVideoTracks();
            const audioTracks = this.trainerScreenStream.getAudioTracks();

            // Apply video constraints for better quality
            videoTracks.forEach(track => {
                if (track) {
                    try {
                        track.applyConstraints({
                            width: { ideal: settings.width },
                            height: { ideal: settings.height },
                            frameRate: { ideal: settings.frameRate },
                            // Additional quality settings
                            resizeMode: 'crop-and-scale',
                            aspectRatio: 16/9
                        }).then(() => {
                            console.log('Video constraints applied:', track.getSettings());
                        }).catch(err => {
                            console.warn('Could not apply video constraints:', err);
                        });
                    } catch (error) {
                        console.log('Video constraints not adjustable');
                    }
                }
            });

            // Apply audio constraints for better quality
            audioTracks.forEach(track => {
                if (track) {
                    try {
                        track.applyConstraints({
                            channelCount: 2,
                            sampleRate: 48000,
                            sampleSize: 16,
                            echoCancellation: false, // Disable for better recording quality
                            autoGainControl: false,
                            noiseSuppression: false
                        }).then(() => {
                            console.log('Audio constraints applied:', track.getSettings());
                        }).catch(err => {
                            console.warn('Could not apply audio constraints:', err);
                        });
                    } catch (error) {
                        console.log('Audio constraints not adjustable');
                    }
                }
            });
        }

        // ENHANCED: Process and download recording with format conversion
        async processAndDownloadRecording(mimeType) {
            if (this.recordedChunks.length === 0) {
                this.showNotification('No recording data available', 'warning');
                return;
            }

            try {
                console.log('Processing recording chunks:', this.recordedChunks.length);

                // Create blob from recorded chunks
                const blob = new Blob(this.recordedChunks, { 
                    type: mimeType || 'video/webm' 
                });

                console.log('Recording blob size:', blob.size, 'bytes, type:', blob.type);

                if (blob.size === 0) {
                    this.showNotification('Recording file is empty', 'error');
                    return;
                }

                let finalBlob = blob;
                let finalExtension = this.getFileExtension(mimeType);

                // If recording is in WebM and we want MP4, try to convert
                if (blob.type.includes('webm') && this.shouldConvertToMp4()) {
                    try {
                        finalBlob = await this.convertWebmToMp4(blob);
                        finalExtension = 'mp4';
                        console.log('Successfully converted WebM to MP4');
                    } catch (conversionError) {
                        console.warn('WebM to MP4 conversion failed, using original:', conversionError);
                        // Fallback to original WebM
                        finalExtension = 'webm';
                    }
                }

                this.downloadRecordingFile(finalBlob, finalExtension);
                this.recordedChunks = []; // Clear chunks after download

            } catch (error) {
                console.error('Recording processing error:', error);
                this.showNotification('Failed to process recording: ' + error.message, 'error');
            }
        }

        // NEW: Convert WebM to MP4 using FFmpeg.js (client-side conversion)
        async convertWebmToMp4(webmBlob) {
            return new Promise((resolve, reject) => {
                // Check if FFmpeg is available
                if (typeof FFmpeg === 'undefined') {
                    reject(new Error('FFmpeg not available for conversion'));
                    return;
                }

                const ffmpeg = new FFmpeg();
                const inputFileName = 'input.webm';
                const outputFileName = 'output.mp4';

                ffmpeg.on('log', ({ message }) => {
                    console.log('FFmpeg log:', message);
                });

                ffmpeg.on('progress', ({ progress }) => {
                    console.log('Conversion progress:', progress);
                    this.showNotification(`Converting to MP4... ${Math.round(progress * 100)}%`, 'info');
                });

                // Load and run FFmpeg
                ffmpeg.load().then(async () => {
                    try {
                        // Write WebM file to virtual file system
                        const webmData = new Uint8Array(await webmBlob.arrayBuffer());
                        await ffmpeg.writeFile(inputFileName, webmData);

                        // Convert to MP4
                        await ffmpeg.exec([
                            '-i', inputFileName,
                            '-c:v', 'libx264',    // H.264 video codec
                            '-preset', 'medium',  // Encoding speed vs quality balance
                            '-crf', '23',         // Quality setting (0-51, lower is better)
                            '-c:a', 'aac',        // AAC audio codec
                            '-b:a', '192k',       // Audio bitrate
                            '-movflags', '+faststart', // Optimize for web playback
                            outputFileName
                        ]);

                        // Read converted MP4 file
                        const mp4Data = await ffmpeg.readFile(outputFileName);
                        const mp4Blob = new Blob([mp4Data], { type: 'video/mp4' });

                        resolve(mp4Blob);
                    } catch (error) {
                        reject(error);
                    }
                }).catch(reject);
            });
        }

        // NEW: Simple WebM to MP4 conversion using MediaSource (alternative method)
        async convertWebmToMp4Simple(webmBlob) {
            return new Promise((resolve, reject) => {
                // This is a simplified approach that remuxes the file
                // Note: This method has limitations but works for basic conversion

                const reader = new FileReader();
                reader.onload = function() {
                    try {
                        // In a real implementation, you would use a proper remuxing library
                        // like mp4box.js or mux.js for proper WebM to MP4 conversion

                        // For now, we'll create a simple wrapper
                        // This is a placeholder - actual conversion requires proper libraries
                        const arrayBuffer = reader.result;

                        // Create a new blob with MP4 extension but same data
                        // This is not a real conversion but maintains compatibility
                        const mp4Blob = new Blob([arrayBuffer], { type: 'video/mp4' });
                        resolve(mp4Blob);

                    } catch (error) {
                        reject(error);
                    }
                };
                reader.onerror = reject;
                reader.readAsArrayBuffer(webmBlob);
            });
        }

        // NEW: Get appropriate file extension
        getFileExtension(mimeType) {
            if (mimeType.includes('mp4')) return 'mp4';
            if (mimeType.includes('webm')) return 'webm';
            return 'mp4'; // Default to MP4
        }

        // NEW: Determine if we should attempt MP4 conversion
        shouldConvertToMp4() {
            // Check if browser supports MP4 recording natively
            const supportsMp4 = MediaRecorder.isTypeSupported('video/mp4;codecs=h264,aac') ||
                               MediaRecorder.isTypeSupported('video/mp4;codecs=avc1.42E01E,mp4a.40.2');

            // Only convert if native MP4 isn't supported and we have a conversion method
            return !supportsMp4 && (typeof FFmpeg !== 'undefined');
        }

        // ENHANCED: Download recording file
        downloadRecordingFile(blob, extension) {
            try {
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                const timestamp = new Date().toISOString()
                    .replace(/[:.]/g, '-')
                    .replace('T', '_')
                    .split('.')[0];

                a.href = url;
                a.download = `class-recording-${this.config.courseName}-${timestamp}.${extension}`;
                a.style.display = 'none';

                document.body.appendChild(a);
                a.click();

                // Cleanup
                setTimeout(() => {
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                }, 100);

                this.showNotification(`Recording downloaded as ${extension.toUpperCase()}!`, 'success');

                // Log recording details
                console.log('Recording details:', {
                    format: extension,
                    size: blob.size,
                    duration: this.calculateRecordingDuration(),
                    quality: 'High'
                });

            } catch (error) {
                console.error('Download error:', error);
                this.showNotification('Download failed: ' + error.message, 'error');
            }
        }

        // NEW: Calculate recording duration
        calculateRecordingDuration() {
            if (!this.recordingStartTime) return 'Unknown';
            const duration = Math.round((new Date() - this.recordingStartTime) / 1000);
            const minutes = Math.floor(duration / 60);
            const seconds = duration % 60;
            return `${minutes}:${seconds.toString().padStart(2, '0')}`;
        }

        // UPDATED: Start recording with high quality settings
        startRecording() {
            if (!this.trainerScreenStream) {
                this.showNotification('Wait for trainer to start screen sharing', 'warning');
                return;
            }

            if (!this.mediaRecorder) {
                if (!this.initializeRecording()) {
                    this.showNotification('Cannot start recording - no stream available', 'error');
                    return;
                }
            }

            if (this.mediaRecorder.state === 'inactive') {
                this.recordedChunks = [];
                this.recordingStartTime = new Date();

                // Use smaller chunks for better performance and quality
                this.mediaRecorder.start(500); // Collect data every 500ms for better quality

                this.showNotification('High quality recording started', 'success');
            }
        }

        // Add this to your HTML head to include FFmpeg for conversion (optional)
        

        // Call this when the page loads
        

        stopRecording() {
            if (this.mediaRecorder && this.mediaRecorder.state === 'recording') {
                this.mediaRecorder.stop();
                this.isRecording = false;
                this.updateStudentRecordingUI(false);
                this.showNotification('Recording stopped - preparing download', 'info');
            }
        }

        updateStudentRecordingUI(isRecording) {
            const recordBtn = document.getElementById('recordBtn');
            const recordingIndicator = document.getElementById('recordingIndicator');

            if (isRecording) {
                if (recordBtn) {
                    recordBtn.classList.add('recording');
                    recordBtn.innerHTML = '⏺️ Stop Recording';
                }
                if (recordingIndicator) recordingIndicator.style.display = 'block';
            } else {
                if (recordBtn) {
                    recordBtn.classList.remove('recording');
                    recordBtn.innerHTML = '⏺️ Start Recording';
                }
                recordingIndicator.style.display = 'none';
            }
        }

        downloadStudentRecording() {
            if (this.recordedChunks.length === 0) {
                this.showNotification('No recording data available', 'warning');
                return;
            }

            try {
                const blob = new Blob(this.recordedChunks, { type: 'video/webm' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                const timestamp = new Date().toISOString()
                    .replace(/[:.]/g, '-')
                    .replace('T', '_')
                    .split('.')[0];

                a.href = url;
                a.download = `class-${this.config.courseName}-${timestamp}.webm`;
                a.style.display = 'none';

                document.body.appendChild(a);
                a.click();

                setTimeout(() => {
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                }, 100);

                this.showNotification('Recording downloaded!', 'success');
                this.recordedChunks = [];

            } catch (error) {
                console.error('Student download error:', error);
                this.showNotification('Download failed: ' + error.message, 'error');
            }
        }

        escapeHtml(unsafe) {
            return unsafe
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = 'ihd-notification';
            notification.style.background = type === 'error' ? '#e74c3c' : 
                                          type === 'warning' ? '#e67e22' : 
                                          type === 'success' ? '#27ae60' : '#3498db';
            notification.textContent = message;

            document.body.appendChild(notification);

            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 5000);
        }

        async trackAttendance(action, duration = 0) {
            try {
                // Use fetch instead of jQuery for better compatibility
                const response = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'ihd_track_batch_attendance',
                        batch_id: this.config.batchId,
                        student_name: this.config.studentName,
                        action: action,
                        duration: duration,
                        nonce: '<?php echo wp_create_nonce('ihd_video_nonce'); ?>'
                    })
                });

                const result = await response.json();
                console.log('Attendance tracked:', result);
            } catch (error) {
                console.error('Failed to track attendance:', error);
            }
        }

        // OPTIMIZED: Faster leave meeting with immediate feedback
        leaveMeeting() {
            // Show immediate feedback
            this.showNotification('Leaving class...', 'info');

            // Set redirect URL immediately
            const redirectUrl = '<?php echo home_url('/completeclass'); ?>';

            // Stop media streams IMMEDIATELY (most important)
            if (this.localScreenStream) {
                this.localScreenStream.getTracks().forEach(track => track.stop());
            }
            if (this.audioStream) {
                this.audioStream.getTracks().forEach(track => track.stop());
            }

            // Stop recording if active
            if (this.isRecording) {
                this.stopRecording();
            }

            // Close connections (non-blocking)
            if (this.currentCall) this.currentCall.close();
            if (this.currentStudentCall) this.currentStudentCall.close();
            if (this.currentAudioCall) this.currentAudioCall.close();
            if (this.conn) this.conn.close();
            if (this.peer) this.peer.destroy();

            // Track attendance ASYNCHRONOUSLY (don't wait for it)
            this.trackAttendanceAsync();

            // Redirect immediately with minimal delay for UI feedback
            setTimeout(() => {
                window.location.href = redirectUrl;
            }, 300); // Reduced from 1000ms to 300ms
        }

        // ASYNC attendance tracking that doesn't block
        async trackAttendanceAsync() {
            if (!this.attendanceStartTime) return;

            const duration = Math.round((new Date() - this.attendanceStartTime) / 60000);

            try {
                // Use sendBeacon for reliable, non-blocking request
                const data = new URLSearchParams({
                    action: 'ihd_track_batch_attendance',
                    batch_id: this.config.batchId,
                    student_name: this.config.studentName,
                    action: 'leave',
                    duration: duration,
                    nonce: '<?php echo wp_create_nonce('ihd_video_nonce'); ?>'
                });

                // This won't block the page unload
                navigator.sendBeacon('<?php echo admin_url('admin-ajax.php'); ?>', data);
            } catch (error) {
                console.log('Attendance tracking failed silently');
            }
        }
    }
    function initializeStudentConference() {
        studentConference = new StudentScreenShareConference();
        studentConference.initializeConference();
    }
	function loadFfmpegScript() {
            if (typeof FFmpeg === 'undefined') {
                const script = document.createElement('script');
                script.src = 'https://unpkg.com/@ffmpeg/ffmpeg@0.12.4/dist/ffmpeg.min.js';
                script.onload = () => console.log('FFmpeg loaded for MP4 conversion');
                document.head.appendChild(script);
            }
     }
    // Global functions for student interface
    function toggleStudentScreenShare() {
        if (studentConference) studentConference.toggleScreenShare();
    }

    function toggleStudentAudioShare() {
        if (studentConference) studentConference.toggleAudioShare();
    }

    function toggleStudentAudio() {
        if (studentConference) studentConference.toggleAudio();
    }

    function toggleStudentRecording() {
        if (studentConference) {
            if (studentConference.isRecording) {
                studentConference.stopRecording();
            } else {
                studentConference.startRecording();
            }
        }
    }

    function toggleStudentFullscreen() {
        if (studentConference) studentConference.toggleFullscreenMode();
    }

    function leaveStudentMeeting() {
        // Show immediate visual feedback
        const leaveBtn = document.querySelector('.ihd-main-control.end-call');
        if (leaveBtn) {
            leaveBtn.innerHTML = '⏳ Leaving...';
            leaveBtn.disabled = true;
            leaveBtn.style.background = '#95a5a6';
        }

        if (studentConference) {
            studentConference.leaveMeeting();
        }
    }

    function handleStudentChatInput(event) {
        if (event.key === 'Enter' && studentConference) {
            const input = document.getElementById('chatInput');
            const message = input.value.trim();

            if (message) {
                // Use ONLY sendChatToAll which handles both trainer and students
                studentConference.sendChatToAll(message);
                input.value = '';
            }
        }
    }
    //window.addEventListener('beforeunload', () => {
        //if (studentConference) {
            //studentConference.leaveMeeting();
        //}
    //});
	window.addEventListener('beforeunload', (e) => {
        if (studentConference && !studentConference.isLeaving) {
            // Only track if user is closing tab/window unexpectedly
            studentConference.trackAttendanceAsync();
        }
    });
    document.addEventListener('DOMContentLoaded', loadFfmpegScript);
    // Mobile optimization
    if (/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)) {
        document.addEventListener('DOMContentLoaded', function() {
            const screenVideo = document.getElementById('screenShareVideo');
            if (screenVideo) {
                screenVideo.playsInline = true;
                screenVideo.setAttribute('webkit-playsinline', 'true');
            }
        });
    }
      
    </script>

    <style>
    .ihd-video-conference {
        width: 100%;
        height: 100vh;
        background: #1a1a1a;
        color: white;
        font-family: Arial, sans-serif;
        overflow: hidden;
        position: relative;
    }

    .ihd-conference-interface {
        display: flex;
        flex-direction: column;
        height: 100%;
        padding: 10px;
        box-sizing: border-box;
        gap: 10px;
    }
	/* Add to your CSS */
    .ihd-main-control.end-call:disabled {
        background: #95a5a6 !important;
        cursor: not-allowed;
        transform: none !important;
    }
    .ihd-participant.administrator .ihd-participant-avatar {
        background: #e67e22 !important;
    }

    .ihd-participant.administrator .ihd-participant-role {
        color: #e67e22 !important;
        font-weight: bold;
    }
    .ihd-video-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px 20px;
        background: #2c3e50;
        border-radius: 10px;
        margin-bottom: 10px;
        flex-wrap: wrap;
        gap: 10px;
    }
	/* Fullscreen styles for student view */
    .ihd-screen-share-container.fullscreen-active {
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        width: 100vw !important;
        height: 100vh !important;
        z-index: 10000 !important;
        background: #000 !important;
        border-radius: 0 !important;
    }

    .ihd-screen-share-container.fullscreen-active video {
        width: 100% !important;
        height: 100% !important;
        object-fit: contain !important;
    }

    .ihd-screen-share-container.fullscreen-active .ihd-screen-overlay {
        position: fixed !important;
        top: 20px !important;
        left: 20px !important;
        right: 20px !important;
        z-index: 10001 !important;
    }

    /* Hide other elements when in fullscreen */
    .ihd-screen-share-container.fullscreen-active ~ .ihd-sidebar,
    .ihd-screen-share-container.fullscreen-active ~ .ihd-controls-bar,
    .ihd-screen-share-container.fullscreen-active ~ .ihd-video-header {
        display: none !important;
    }

    /* Add cursor hint for double-click */
    .ihd-screen-share-container {
        cursor: pointer;
    }

    .ihd-screen-share-container:hover::after {
        
        position: absolute;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%);
        
        color: white;
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 0.8em;
        opacity: 0;
        transition: opacity 0.3s;
    }

    .ihd-screen-share-container:hover::after {
        opacity: 1;
    }
    .ihd-meeting-info h2 {
        margin: 0;
        font-size: 1.4em;
        color: #ecf0f1;
    }

    .ihd-meeting-info p {
        margin: 5px 0 0 0;
        font-size: 0.9em;
        color: #bdc3c7;
    }

    .ihd-meeting-controls {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .ihd-control-btn {
        background: #34495e;
        color: white;
        border: none;
        padding: 10px 15px;
        border-radius: 5px;
        cursor: pointer;
        font-size: 0.9em;
        transition: background 0.3s;
    }

    .ihd-control-btn:hover {
        background: #4a6a8b;
    }

    .ihd-video-container {
        display: flex;
        flex: 1;
        gap: 15px;
        min-height: 0;
        overflow: hidden;
    }

    .ihd-screen-share-container {
        flex: 3;
        background: #2c3e50;
        border-radius: 10px;
        overflow: hidden;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .ihd-screen-placeholder {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        height: 100%;
        background: #34495e;
    }

    .ihd-screen-placeholder-content {
        text-align: center;
        padding: 40px;
    }

    .ihd-screen-icon {
        font-size: 4em;
        margin-bottom: 20px;
    }

    .ihd-screen-placeholder-content h3 {
        margin: 0 0 10px 0;
        color: #ecf0f1;
    }

    .ihd-screen-placeholder-content p {
        margin: 5px 0;
        color: #bdc3c7;
    }

    .ihd-screen-note {
        font-size: 0.9em;
        color: #95a5a6 !important;
        font-style: italic;
    }

    .ihd-screen-share-container video {
        width: 100%;
        height: 100%;
        object-fit: contain;
        background: #000;
    }

    .ihd-screen-overlay {
        position: absolute;
        top: 15px;
        left: 15px;
        right: 15px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .ihd-screen-info {
        display: flex;
        align-items: center;
        gap: 10px;
        background: rgba(0,0,0,0.7);
        padding: 8px 15px;
        border-radius: 20px;
        color: white;
    }

    .ihd-screen-indicator {
        color: #e74c3c;
        font-weight: bold;
    }

    .ihd-recording-indicator {
        background: rgba(231, 76, 60, 0.9);
        color: white;
        padding: 8px 15px;
        border-radius: 20px;
        font-weight: bold;
        animation: pulse 1.5s infinite;
    }

    @keyframes pulse {
        0% { opacity: 1; }
        50% { opacity: 0.7; }
        100% { opacity: 1; }
    }

    .ihd-sidebar {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 15px;
        min-width: 300px;
        max-width: 400px;
    }

    .ihd-chat-container, .ihd-participants-container {
        background: #2c3e50;
        border-radius: 10px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    .ihd-chat-header, .ihd-participants-header {
        background: #34495e;
        padding: 15px;
        margin: 0;
    }

    .ihd-chat-messages {
        flex: 1;
        padding: 15px;
        overflow-y: auto;
        max-height: 300px;
        
    }

    .ihd-chat-input-container {
        padding: 15px;
        border-top: 1px solid #34495e;
    }

    .ihd-chat-input {
        width: 100%;
        padding: 12px;
        border: none;
        border-radius: 5px;
        background: #34495e;
        color: white;
        box-sizing: border-box;
    }

    .ihd-participants-list {
        flex: 1;
        padding: 15px;
        overflow-y: auto;
        max-height: 300px;
    }

    .ihd-participant {
        display: flex;
        align-items: center;
        padding: 10px;
        margin-bottom: 10px;
        background: #34495e;
        border-radius: 8px;
        gap: 10px;
    }

    /* Mobile-specific styles */
    @media (max-width: 768px) {
        .ihd-controls-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #2c3e50;
            padding: 10px;
            border-radius: 0;
            z-index: 1000;
        }
        
        .ihd-main-control {
            padding: 15px 10px;
            font-size: 0.9em;
            min-width: 100px;
        }
        
        .ihd-screen-share-container {
            margin-bottom: 80px; /* Space for controls */
        }
        
        /* Larger tap targets for mobile */
        .ihd-control-btn, .ihd-main-control {
            min-height: 44px;
            min-width: 44px;
        }
    }

    /* High DPI mobile devices */
    @media (max-width: 768px) and (-webkit-min-device-pixel-ratio: 2) {
        .ihd-screen-share-container video {
            object-fit: cover; /* Better mobile display */
        }
    }

    .ihd-participant-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: #3498db;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
    }

    .ihd-participant.trainer .ihd-participant-avatar {
        background: #e67e22;
    }

    .ihd-participant-info {
        flex: 1;
    }

    .ihd-participant-name {
        font-weight: bold;
        font-size: 0.9em;
    }

    .ihd-participant-role {
        font-size: 0.8em;
        color: #bdc3c7;
    }

    .ihd-participant-status {
        font-size: 0.8em;
        color: #27ae60;
        font-weight: bold;
    }

    /* Fixed Controls Bar */
    .ihd-controls-bar {
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 15px 20px;
        background: #2c3e50;
        border-radius: 10px;
        gap: 15px;
        flex-wrap: wrap;
        margin-top: 10px;
    }

    .ihd-main-control {
        background: #34495e;
        color: white;
        border: none;
        padding: 12px 20px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 1em;
        transition: all 0.3s;
        min-width: 120px;
    }

    .ihd-main-control:hover {
        background: #4a6a8b;
        transform: translateY(-2px);
    }

    .ihd-main-control.end-call {
        background: #e74c3c;
        font-weight: bold;
    }

    .ihd-main-control.end-call:hover {
        background: #c0392b;
    }

    /* Notification styles */
    .ihd-notification {
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 15px 20px;
        border-radius: 5px;
        color: white;
        z-index: 10000;
        animation: slideIn 0.3s ease;
    }

    @keyframes slideIn {
        from { transform: translateX(100%); }
        to { transform: translateX(0); }
    }

    /* Recording state */
    .recording {
        background: #e74c3c !important;
        animation: pulse 1.5s infinite;
    }

    /* Chat messages */
    .ihd-chat-message {
       
        padding: 10px;
        background: #34495e;
        border-radius: 8px;
    }

    .ihd-chat-message.trainer {
        background: #34495e;
        border-left: 4px solid #e67e22;
    }

    .ihd-chat-sender {
        font-weight: bold;
        font-size: 0.8em;
        margin-bottom: 5px;
    }

    .ihd-chat-sender.trainer {
        color: #e67e22;
    }

    .ihd-chat-text {
        font-size: 0.9em;
        line-height: 1.4;
    }

    .ihd-chat-timestamp {
        font-size: 0.7em;
        color: #bdc3c7;
        text-align: right;
        margin-top: 5px;
    }

    /* Responsive design */
    @media (max-width: 1024px) {
        .ihd-video-container {
            flex-direction: column;
        }
        
        .ihd-sidebar {
            max-width: none;
            min-width: auto;
        }
    }

    @media (max-width: 768px) {
        .ihd-video-header {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .ihd-meeting-controls {
            width: 100%;
            justify-content: center;
            margin-top: 10px;
        }
        
        .ihd-controls-bar {
            flex-direction: column;
            gap: 10px;
        }
        
        .ihd-main-control {
            width: 100%;
            max-width: 200px;
        }
    }
    </style>
    <?php
    return ob_get_clean();
}

// AJAX handler for ending batch session
function ihd_end_batch_session_ajax() {
    check_ajax_referer('ihd_video_nonce', 'nonce');
    
    $batch_id = sanitize_text_field($_POST['batch_id']);
    $meeting_id = sanitize_text_field($_POST['meeting_id']);
    
    global $wpdb;
    $batch_table = $wpdb->prefix . 'ihd_batch_sessions';
    
    // Update batch session to completed
    $result = $wpdb->update(
        $batch_table,
        array(
            'status' => 'completed',
            'end_time' => current_time('mysql'),
            'duration_minutes' => 0
        ),
        array(
            'batch_id' => $batch_id,
            'meeting_id' => $meeting_id
        ),
        array('%s', '%s', '%d'),
        array('%s', '%s')
    );
    
    if ($result !== false) {
        wp_send_json_success('Batch session ended successfully');
    } else {
        wp_send_json_error('Failed to end batch session');
    }
}
add_action('wp_ajax_ihd_end_batch_session', 'ihd_end_batch_session_ajax');

// Register meeting shortcodes
add_shortcode('trainer_meeting', 'ihd_trainer_meeting_shortcode');
add_shortcode('student_meeting_join', 'ihd_student_meeting_join_shortcode');
?>