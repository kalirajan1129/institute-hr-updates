// Enhanced Video Conferencing JavaScript with Screen Sharing
class VideoConferenceBase {
    constructor() {
        this.localStream = null;
        this.remoteStreams = new Map();
        this.peer = null;
        this.connections = new Map();
        this.calls = new Map();
        this.screenStream = null;
        this.isScreenSharing = false;
        this.config = {};
    }

    // Common utility methods
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

    async ajaxCall(action, data) {
        return new Promise((resolve, reject) => {
            jQuery.ajax({
                url: ihd_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: action,
                    nonce: ihd_ajax.nonce,
                    ...data
                },
                success: (response) => {
                    if (response.success) {
                        resolve(response);
                    } else {
                        reject(response.data);
                    }
                },
                error: (xhr, status, error) => {
                    reject(error);
                }
            });
        });
    }

    // Enhanced Screen Share Method
    async toggleScreenShare() {
        try {
            if (!this.isScreenSharing) {
                // Start screen sharing
                this.screenStream = await navigator.mediaDevices.getDisplayMedia({
                    video: {
                        cursor: "always",
                        displaySurface: "window"
                    },
                    audio: {
                        echoCancellation: true,
                        noiseSuppression: true,
                        sampleRate: 44100
                    }
                });

                this.isScreenSharing = true;
                
                // Replace video track in all active calls
                const videoTrack = this.screenStream.getVideoTracks()[0];
                const audioTrack = this.screenStream.getAudioTracks()[0];
                
                // Update all peer connections with screen share stream
                this.calls.forEach((call, peerId) => {
                    const sender = call.peerConnection.getSenders().find(s => 
                        s.track && s.track.kind === 'video'
                    );
                    
                    if (sender) {
                        sender.replaceTrack(videoTrack);
                    }
                    
                    // Also replace audio if available
                    if (audioTrack) {
                        const audioSender = call.peerConnection.getSenders().find(s => 
                            s.track && s.track.kind === 'audio'
                        );
                        if (audioSender) {
                            audioSender.replaceTrack(audioTrack);
                        }
                    }
                });

                // Update local video display
                const localVideo = document.getElementById('localVideo');
                if (localVideo) {
                    localVideo.srcObject = this.screenStream;
                }

                // Update UI
                const screenShareBtn = document.getElementById('screenShareBtn');
                if (screenShareBtn) {
                    screenShareBtn.innerHTML = '🖥️ Stop Share';
                    screenShareBtn.style.background = '#e74c3c';
                }

                this.showNotification('Screen sharing started', 'success');

                // Handle when user stops sharing via browser controls
                videoTrack.onended = () => {
                    this.stopScreenShare();
                };

            } else {
                // Stop screen sharing
                this.stopScreenShare();
            }
        } catch (error) {
            console.error('Screen share error:', error);
            
            if (error.name === 'NotAllowedError') {
                this.showNotification('Screen sharing permission denied', 'error');
            } else if (error.name === 'NotFoundError') {
                this.showNotification('No screen sharing source found', 'error');
            } else {
                this.showNotification('Failed to share screen: ' + error.message, 'error');
            }
        }
    }

    stopScreenShare() {
        if (this.screenStream) {
            this.screenStream.getTracks().forEach(track => track.stop());
            this.screenStream = null;
        }

        this.isScreenSharing = false;

        // Switch back to camera
        if (this.localStream) {
            const videoTrack = this.localStream.getVideoTracks()[0];
            const audioTrack = this.localStream.getAudioTracks()[0];
            
            // Update all peer connections back to camera
            this.calls.forEach((call, peerId) => {
                const videoSender = call.peerConnection.getSenders().find(s => 
                    s.track && s.track.kind === 'video'
                );
                
                if (videoSender && videoTrack) {
                    videoSender.replaceTrack(videoTrack);
                }
                
                const audioSender = call.peerConnection.getSenders().find(s => 
                    s.track && s.track.kind === 'audio'
                );
                
                if (audioSender && audioTrack) {
                    audioSender.replaceTrack(audioTrack);
                }
            });

            // Update local video display
            const localVideo = document.getElementById('localVideo');
            if (localVideo) {
                localVideo.srcObject = this.localStream;
            }
        }

        // Update UI
        const screenShareBtn = document.getElementById('screenShareBtn');
        if (screenShareBtn) {
            screenShareBtn.innerHTML = '🖥️ Share Screen';
            screenShareBtn.style.background = '';
        }

        this.showNotification('Screen sharing stopped', 'info');
    }

    // Common media controls
    async toggleMic() {
        if (this.localStream) {
            const audioTracks = this.localStream.getAudioTracks();
            if (audioTracks.length > 0) {
                audioTracks.forEach(track => {
                    track.enabled = !track.enabled;
                });
                
                const micBtn = document.getElementById('micBtn');
                
                if (audioTracks[0].enabled) {
                    micBtn.innerHTML = '🎤 Mute';
                    micBtn.style.background = '';
                    this.showNotification('Microphone unmuted', 'success');
                } else {
                    micBtn.innerHTML = '🎤🔇 Unmute';
                    micBtn.style.background = '#e74c3c';
                    this.showNotification('Microphone muted', 'warning');
                }
            }
        }
    }

    async toggleCamera() {
        if (this.localStream) {
            const videoTracks = this.localStream.getVideoTracks();
            if (videoTracks.length > 0) {
                videoTracks.forEach(track => {
                    track.enabled = !track.enabled;
                });
                
                const cameraBtn = document.getElementById('cameraBtn');
                
                if (videoTracks[0].enabled) {
                    cameraBtn.innerHTML = '📹 Stop Video';
                    cameraBtn.style.background = '';
                    this.showNotification('Camera turned on', 'success');
                } else {
                    cameraBtn.innerHTML = '📹🔴 Start Video';
                    cameraBtn.style.background = '#e67e22';
                    this.showNotification('Camera turned off', 'warning');
                }
            }
        }
    }

    // Common initialization
    async initializeMedia() {
        try {
            // Get user media
            this.localStream = await navigator.mediaDevices.getUserMedia({
                video: {
                    width: { ideal: 1280 },
                    height: { ideal: 720 },
                    frameRate: { ideal: 30 }
                },
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                    channelCount: 2,
                    sampleRate: 44100
                }
            });
            
            // Display local video
            const localVideo = document.getElementById('localVideo');
            if (localVideo) {
                localVideo.srcObject = this.localStream;
            }
            
        } catch (error) {
            console.error('Error accessing media devices:', error);
            
            if (error.name === 'NotAllowedError') {
                this.showNotification('Camera/microphone access denied. Please allow permissions.', 'error');
            } else if (error.name === 'NotFoundError') {
                this.showNotification('No camera/microphone found', 'error');
            } else if (error.name === 'NotReadableError') {
                this.showNotification('Camera/microphone is already in use', 'error');
            } else {
                this.showNotification('Could not access camera/microphone: ' + error.message, 'error');
            }
        }
    }

    // Common cleanup
    leaveMeeting() {
        // Stop screen sharing if active
        if (this.screenStream) {
            this.stopScreenShare();
        }
        
        // Stop local stream
        if (this.localStream) {
            this.localStream.getTracks().forEach(track => track.stop());
        }
        
        // Close all peer connections
        if (this.peer) {
            this.peer.destroy();
        }
        
        this.showNotification('You left the meeting', 'info');
    }
}

// Enhanced Recording System for Screen + Audio
class EnhancedRecordingSystem {
    constructor() {
        this.mediaRecorder = null;
        this.recordedChunks = [];
        this.isRecording = false;
        this.recordingStream = null;
        this.audioStream = null;
        this.screenStream = null;
        this.cameraStream = null;
        this.recordingType = 'camera'; // 'camera' or 'screen'
    }

    // Initialize recording system with multiple streams
    async initializeRecording(cameraStream, audioStream) {
        this.cameraStream = cameraStream;
        this.audioStream = audioStream;
        
        try {
            console.log('Recording system initialized with camera and audio streams');
        } catch (error) {
            console.error('Recording initialization failed:', error);
        }
    }

    // Start recording with current active streams
    async startRecording(streamType = 'camera') {
        if (this.isRecording) {
            console.warn('Recording already in progress');
            return false;
        }

        try {
            let videoStream;
            
            if (streamType === 'screen' && this.screenStream) {
                videoStream = this.screenStream;
                this.recordingType = 'screen';
            } else {
                videoStream = this.cameraStream;
                this.recordingType = 'camera';
            }

            // Combine video and audio streams
            const combinedStream = new MediaStream([
                ...videoStream.getVideoTracks(),
                ...this.audioStream.getAudioTracks()
            ]);

            this.recordingStream = combinedStream;
            this.recordedChunks = [];

            // Configure MediaRecorder with better settings
            const options = {
                mimeType: 'video/webm;codecs=vp9,opus',
                videoBitsPerSecond: 5000000, // Higher quality
                audioBitsPerSecond: 128000
            };

            // Fallback to VP8 if VP9 not supported
            if (!MediaRecorder.isTypeSupported(options.mimeType)) {
                options.mimeType = 'video/webm;codecs=vp8,opus';
                console.log('Using VP8 codec as fallback');
            }

            // Fallback to basic webm if VP8 not supported
            if (!MediaRecorder.isTypeSupported(options.mimeType)) {
                options.mimeType = 'video/webm';
                console.log('Using basic WebM format');
            }

            this.mediaRecorder = new MediaRecorder(combinedStream, options);

            this.mediaRecorder.ondataavailable = (event) => {
                if (event.data.size > 0) {
                    this.recordedChunks.push(event.data);
                    console.log('Recording data chunk:', event.data.size, 'bytes');
                }
            };

            this.mediaRecorder.onstop = () => {
                this.finalizeRecording();
            };

            this.mediaRecorder.onerror = (event) => {
                console.error('MediaRecorder error:', event.error);
            };

            this.mediaRecorder.start(1000); // Collect data every second
            this.isRecording = true;

            console.log('Recording started with:', this.recordingType);
            return true;

        } catch (error) {
            console.error('Failed to start recording:', error);
            return false;
        }
    }

    // Stop recording
    stopRecording() {
        if (this.mediaRecorder && this.isRecording) {
            this.mediaRecorder.stop();
            this.isRecording = false;
            console.log('Recording stopped');
            return true;
        }
        return false;
    }

    // Switch recording source (camera/screen)
    async switchRecordingSource(streamType) {
        if (!this.isRecording) return;

        const wasRecording = this.isRecording;
        const previousType = this.recordingType;
        
        if (wasRecording) {
            this.stopRecording();
        }

        // Small delay to ensure clean switch
        await new Promise(resolve => setTimeout(resolve, 100));

        if (wasRecording) {
            await this.startRecording(streamType);
            console.log(`Switched recording from ${previousType} to ${streamType}`);
        }
    }

    // Update screen stream when screen sharing starts
    updateScreenStream(screenStream) {
        this.screenStream = screenStream;
        
        // If recording is active and screen sharing starts, switch to screen recording
        if (this.isRecording && screenStream) {
            this.switchRecordingSource('screen');
        }
    }

    // Remove screen stream when screen sharing stops
    removeScreenStream() {
        this.screenStream = null;
        
        // If recording is active and screen sharing stops, switch back to camera
        if (this.isRecording) {
            this.switchRecordingSource('camera');
        }
    }

    // Finalize and download recording
    finalizeRecording() {
        if (this.recordedChunks.length === 0) {
            console.warn('No recording data available');
            this.showNotification('No recording data available', 'warning');
            return;
        }

        const blob = new Blob(this.recordedChunks, { type: 'video/webm' });
        this.downloadRecording(blob);
        
        // Clean up
        this.recordedChunks = [];
        this.recordingStream = null;
    }

    // Download the recording
    downloadRecording(blob) {
        try {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            const timestamp = new Date().toISOString()
                .replace(/[:.]/g, '-')
                .replace('T', '_')
                .split('.')[0];

            a.href = url;
            a.download = `class-recording-${this.recordingType}-${timestamp}.webm`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);

            console.log('Recording downloaded:', a.download);
            this.showNotification(`Recording downloaded (${this.recordingType})`, 'success');
        } catch (error) {
            console.error('Download failed:', error);
            this.showNotification('Download failed: ' + error.message, 'error');
        }
    }

    // Get recording status
    getRecordingStatus() {
        return {
            isRecording: this.isRecording,
            duration: this.recordedChunks.length,
            currentSource: this.recordingType
        };
    }

    // Show notification
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

    // Clean up resources
    cleanup() {
        if (this.isRecording) {
            this.stopRecording();
        }
        
        if (this.recordingStream) {
            this.recordingStream.getTracks().forEach(track => track.stop());
        }
        
        this.recordedChunks = [];
        this.mediaRecorder = null;
        this.recordingStream = null;
    }
}

// Global functions
function toggleFullscreen() {
    const elem = document.documentElement;
    if (!document.fullscreenElement) {
        elem.requestFullscreen().catch(err => {
            console.error('Error attempting to enable fullscreen:', err);
        });
    } else {
        document.exitFullscreen();
    }
}

// Global recording functions
let currentConference = null;

function initializeRecording(conference) {
    currentConference = conference;
}

async function toggleRecording() {
    if (currentConference) {
        await currentConference.toggleRecording();
        
        // Update recording status indicator
        updateRecordingStatusIndicator();
    }
}

function updateRecordingStatusIndicator() {
    let statusIndicator = document.getElementById('recordingStatusIndicator');
    
    if (!statusIndicator && currentConference && currentConference.isRecording) {
        statusIndicator = document.createElement('div');
        statusIndicator.id = 'recordingStatusIndicator';
        statusIndicator.className = 'recording-status';
        statusIndicator.textContent = 'RECORDING';
        document.body.appendChild(statusIndicator);
    } else if (statusIndicator && (!currentConference || !currentConference.isRecording)) {
        statusIndicator.remove();
    }
}

// Update the recording button handler
function toggleRecording() {
    if (trainerConference) {
        trainerConference.toggleRecording();
    } else if (studentConference) {
        studentConference.toggleRecording();
    }
}

// Handle page unload
window.addEventListener('beforeunload', () => {
    // Cleanup will be handled by individual conference instances
});

// Export for use in other files
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { VideoConferenceBase, EnhancedRecordingSystem };
}