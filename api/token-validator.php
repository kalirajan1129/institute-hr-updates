<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

class TokenValidator {
    
    private $valid_tokens;
    private $log_file;
    
    public function __construct() {
        $this->log_file = __DIR__ . '/token-access.log';
        $this->load_tokens();
    }
    
    private function load_tokens() {
        // In production, load from database or secure config file
        $this->valid_tokens = [
            'TOKEN_CLIENT_1' => [
                'domains' => ['placemen.unaux.com', 'www.placemen.unaux.com'],
                'active' => true,
                'expires' => '2026-12-31',
                'rate_limit' => 1000 // requests per hour
            ],
            // Add more tokens as needed
        ];
    }
    
    public function handle_request() {
        $action = $_POST['action'] ?? '';
        $token = $_POST['token'] ?? '';
        $domain = $_POST['domain'] ?? '';
        
        $this->log_access($token, $domain, $action);
        
        switch ($action) {
            case 'validate':
                $this->validate_token($token, $domain);
                break;
            case 'status':
                $this->get_token_status($token);
                break;
            default:
                echo json_encode(['valid' => false, 'error' => 'Invalid action']);
        }
    }
    
    private function validate_token($token, $domain) {
        // Rate limiting check
        if (!$this->check_rate_limit($token)) {
            echo json_encode(['valid' => false, 'error' => 'Rate limit exceeded']);
            return;
        }
        
        if (!isset($this->valid_tokens[$token])) {
            echo json_encode(['valid' => false, 'error' => 'Invalid token']);
            return;
        }
        
        $token_data = $this->valid_tokens[$token];
        
        // Check if token is active
        if (!$token_data['active']) {
            echo json_encode(['valid' => false, 'error' => 'Token inactive']);
            return;
        }
        
        // Check expiration
        if (strtotime($token_data['expires']) < time()) {
            echo json_encode(['valid' => false, 'error' => 'Token expired']);
            return;
        }
        
        // Check domain
        $domain_valid = false;
        foreach ($token_data['domains'] as $allowed_domain) {
            if (strpos($domain, $allowed_domain) !== false) {
                $domain_valid = true;
                break;
            }
        }
        
        if (!$domain_valid) {
            echo json_encode(['valid' => false, 'error' => 'Domain not authorized']);
            return;
        }
        
        // All checks passed
        echo json_encode([
            'valid' => true,
            'expires' => $token_data['expires'],
            'domains' => $token_data['domains'],
            'rate_limit' => $token_data['rate_limit']
        ]);
    }
    
    private function get_token_status($token) {
        if (!isset($this->valid_tokens[$token])) {
            echo json_encode(['exists' => false]);
            return;
        }
        
        $token_data = $this->valid_tokens[$token];
        echo json_encode([
            'exists' => true,
            'active' => $token_data['active'],
            'expires' => $token_data['expires'],
            'domains' => $token_data['domains']
        ]);
    }
    
    private function check_rate_limit($token) {
        $rate_file = __DIR__ . '/rate-' . md5($token) . '.log';
        $current_hour = date('Y-m-d-H');
        
        if (file_exists($rate_file)) {
            $data = json_decode(file_get_contents($rate_file), true);
            if ($data['hour'] === $current_hour && $data['count'] >= $this->valid_tokens[$token]['rate_limit']) {
                return false;
            }
            
            if ($data['hour'] !== $current_hour) {
                $data = ['hour' => $current_hour, 'count' => 0];
            }
        } else {
            $data = ['hour' => $current_hour, 'count' => 0];
        }
        
        $data['count']++;
        file_put_contents($rate_file, json_encode($data));
        
        return true;
    }
    
    private function log_access($token, $domain, $action) {
        $log_entry = sprintf(
            "[%s] Token: %s, Domain: %s, Action: %s, IP: %s\n",
            date('Y-m-d H:i:s'),
            $token ? substr($token, 0, 8) . '...' : 'none',
            $domain,
            $action,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        );
        
        file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }
}

$validator = new TokenValidator();
$validator->handle_request();