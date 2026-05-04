<?php
/**
 * WebSocket Server for Real-time Price Updates
 * Pure PHP implementation without external dependencies
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/PriceFeeder.php';

class WebSocketServer {
    private $host = 'localhost';
    private $port = 8080;
    private $socket;
    private $clients = [];
    private $db;
    private $priceFeeder;

    public function __construct($host = 'localhost', $port = 8080) {
        $this->host = $host;
        $this->port = $port;
        $this->db = new Database();
        $this->priceFeeder = new PriceFeeder($this->db);
    }

    /**
     * Start the WebSocket server
     */
    public function start() {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        
        if (!$this->socket) {
            die("Failed to create socket: " . socket_strerror(socket_last_error()) . "\n");
        }

        if (!socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            die("Failed to set socket option: " . socket_strerror(socket_last_error()) . "\n");
        }

        if (!socket_bind($this->socket, $this->host, $this->port)) {
            die("Failed to bind socket: " . socket_strerror(socket_last_error()) . "\n");
        }

        if (!socket_listen($this->socket, 5)) {
            die("Failed to listen on socket: " . socket_strerror(socket_last_error()) . "\n");
        }

        echo "[INFO] WebSocket server started on {$this->host}:{$this->port}\n";
        echo "[INFO] Waiting for connections...\n";

        $this->run();
    }

    /**
     * Main server loop
     */
    private function run() {
        while (true) {
            $read = array_merge([$this->socket], $this->clients);
            $write = null;
            $except = null;

            if (socket_select($read, $write, $except, 0, 500000) > 0) {
                // New connection
                if (in_array($this->socket, $read)) {
                    $newSocket = socket_accept($this->socket);
                    if ($newSocket) {
                        $this->clients[] = $newSocket;
                        echo "[INFO] New client connected. Total clients: " . count($this->clients) . "\n";
                        
                        // Perform WebSocket handshake
                        $this->handshake($newSocket);
                    }
                    
                    // Remove server socket from read array
                    $key = array_search($this->socket, $read);
                    unset($read[$key]);
                }

                // Handle client messages
                foreach ($read as $client) {
                    $data = @socket_read($client, 1024, PHP_NORMAL_READ);
                    
                    if ($data === false || $data === '') {
                        // Client disconnected
                        $this->disconnectClient($client);
                    } else {
                        $this->handleMessage($client, $data);
                    }
                }
            }

            // Broadcast price updates every 5 seconds
            static $lastUpdate = 0;
            $now = time();
            if ($now - $lastUpdate >= 5) {
                $this->broadcastPrices();
                $lastUpdate = $now;
            }
        }
    }

    /**
     * WebSocket handshake
     */
    private function handshake($socket) {
        $headers = [];
        $line = '';
        
        while (true) {
            $char = socket_read($socket, 1);
            $line .= $char;
            
            if (substr($line, -4) === "\r\n\r\n") {
                break;
            }
        }

        $requestLine = explode("\r\n", $line)[0];
        preg_match('/GET (.*) HTTP/', $requestLine, $matches);
        $path = $matches[1] ?? '/';

        $headers = explode("\r\n", $line);
        $key = null;

        foreach ($headers as $header) {
            if (stripos($header, 'Sec-WebSocket-Key:') === 0) {
                $key = trim(substr($header, 19));
                break;
            }
        }

        if (!$key) {
            echo "[ERROR] Invalid WebSocket handshake\n";
            socket_close($socket);
            return;
        }

        // Generate response key
        $responseKey = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));

        $response = "HTTP/1.1 101 Switching Protocols\r\n";
        $response .= "Upgrade: websocket\r\n";
        $response .= "Connection: Upgrade\r\n";
        $response .= "Sec-WebSocket-Accept: $responseKey\r\n";
        $response .= "\r\n";

        socket_write($socket, $response, strlen($response));
        echo "[INFO] WebSocket handshake completed\n";
    }

    /**
     * Handle incoming WebSocket message
     */
    private function handleMessage($socket, $data) {
        $message = $this->decodeWebSocketData($data);
        
        if (!$message) {
            return;
        }

        $payload = json_decode($message, true);

        if (!$payload) {
            return;
        }

        switch ($payload['type'] ?? null) {
            case 'subscribe':
                echo "[INFO] Client subscribed to channel: " . ($payload['channel'] ?? 'all') . "\n";
                break;

            case 'unsubscribe':
                echo "[INFO] Client unsubscribed from channel: " . ($payload['channel'] ?? 'all') . "\n";
                break;

            case 'ping':
                $response = json_encode(['type' => 'pong']);
                $this->sendMessage($socket, $response);
                break;

            default:
                echo "[DEBUG] Received message type: " . ($payload['type'] ?? 'unknown') . "\n";
        }
    }

    /**
     * Decode WebSocket frame data
     */
    private function decodeWebSocketData($data) {
        if (strlen($data) < 2) {
            return false;
        }

        $byte1 = ord($data[0]);
        $byte2 = ord($data[1]);

        $fin = ($byte1 & 0x80) >> 7;
        $opcode = $byte1 & 0x0f;
        $masked = ($byte2 & 0x80) >> 7;
        $payload_len = $byte2 & 0x7f;

        $offset = 2;

        if ($payload_len == 126) {
            $payload_len = unpack('N', substr($data, $offset, 4))[1];
            $offset += 4;
        } elseif ($payload_len == 127) {
            $payload_len = unpack('N', substr($data, $offset, 8))[1];
            $offset += 8;
        }

        if ($masked) {
            $mask = substr($data, $offset, 4);\n            $offset += 4;
            $payload = '';
            
            for ($i = 0; $i < $payload_len; $i++) {
                $payload .= chr(ord($data[$offset + $i]) ^ ord($mask[$i % 4]));
            }
        } else {
            $payload = substr($data, $offset, $payload_len);
        }

        return $payload;
    }

    /**
     * Send WebSocket message to client
     */
    private function sendMessage($socket, $message) {
        $frame = chr(0x81); // FIN + Text opcode
        
        $len = strlen($message);
        
        if ($len < 126) {
            $frame .= chr($len);
        } elseif ($len < 65536) {
            $frame .= chr(126) . pack('n', $len);
        } else {
            $frame .= chr(127) . pack('N', 0) . pack('N', $len);
        }

        $frame .= $message;
        
        @socket_write($socket, $frame, strlen($frame));
    }

    /**
     * Broadcast price updates to all connected clients
     */
    private function broadcastPrices() {
        $prices = $this->priceFeeder->getCurrentPrices();
        
        $message = json_encode([
            'type' => 'price_update',
            'timestamp' => date('Y-m-d H:i:s'),
            'data' => $prices
        ]);

        $this->broadcast($message);
    }

    /**
     * Broadcast message to all clients
     */
    private function broadcast($message) {
        foreach ($this->clients as $client) {
            $this->sendMessage($client, $message);
        }
    }

    /**
     * Disconnect client
     */
    private function disconnectClient($socket) {
        $key = array_search($socket, $this->clients);
        if ($key !== false) {
            unset($this->clients[$key]);
        }
        @socket_close($socket);
        echo "[INFO] Client disconnected. Total clients: " . count($this->clients) . "\n";
    }
}

// Start server if run directly
if (php_sapi_name() === 'cli') {
    $host = $_SERVER['argv'][1] ?? 'localhost';
    $port = $_SERVER['argv'][2] ?? 8080;
    
    $server = new WebSocketServer($host, $port);
    $server->start();
}
?>
