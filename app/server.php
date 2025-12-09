<?php
/**
 * WordPress on Pure PHP Async HTTP Server
 * Based on TrueAsync HTTP Keep-Alive benchmark
 *
 * Usage:
 *   php server.php [host] [port]
 */

ini_set('memory_limit', '512M');
set_time_limit(0);

// Logging function (for compatibility with WordPress modifications)
function log_debug($message) {
    // Silent no-op - debug logging disabled in async server mode
    // file_put_contents(__DIR__.'/debug.log', date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
}

use function Async\spawn;
use function Async\await;
use function Async\delay;
use function Async\getCoroutines;

/**
 * Server statistics and metrics
 */
final class ServerStats
{
    public static int $connectionsAccepted = 0;
    public static int $connectionsStarted = 0;
    public static int $connectionsClosed = 0;
    public static int $requestCount = 0;
    public static int $requestHandled = 0;
}

/**
 * MIME types configuration
 */
final class MimeTypes
{
    public const array MAP = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'otf' => 'font/otf',
    ];
}

// Configuration
$host = $argv[1] ?? '0.0.0.0';
$port = (int)($argv[2] ?? 8080);
$keepaliveTimeout = 30;

echo "=== WordPress on TrueAsync HTTP Server ===\n";
echo "Starting server on http://$host:$port\n";
echo "Keep-Alive timeout: {$keepaliveTimeout}s\n";
echo "Press Ctrl+C to stop\n\n";

// Load WordPress core once
require_once __DIR__ . '/wp-loader.php';

echo "WordPress loaded successfully\n";
echo "Document root: " . WP_ROOT . "\n\n";

/**
 * Parse HTTP request
 *
 * @param string $request Raw HTTP request
 * @return array{method: string, uri: string, headers: array<string, string>, connection_close: bool}|null
 */
function parseHttpRequest(string $request): ?array
{
    $lines = explode("\r\n", $request);
    $firstLine = $lines[0] ?? '';

    // Parse request line: GET /path HTTP/1.1
    $parts = explode(' ', $firstLine, 3);
    if (count($parts) < 2) {
        return null;
    }

    $method = $parts[0];
    $uri = $parts[1];

    // Parse headers
    $headers = [];
    for ($i = 1; $i < count($lines); $i++) {
        if (empty($lines[$i])) break;

        $headerParts = explode(':', $lines[$i], 2);
        if (count($headerParts) === 2) {
            $name = strtolower(trim($headerParts[0]));
            $value = trim($headerParts[1]);
            $headers[$name] = $value;
        }
    }

    $connectionClose = isset($headers['connection']) &&
                       strtolower($headers['connection']) === 'close';

    return [
        'method' => $method,
        'uri' => $uri,
        'headers' => $headers,
        'connection_close' => $connectionClose
    ];
}

/**
 * Setup WordPress environment from HTTP request
 *
 * @param string $method HTTP method
 * @param string $uri Request URI
 * @param array<string, string> $headers HTTP headers
 * @return string Parsed path
 */
function setupWordPressEnvironment(string $method, string $uri, array $headers): string
{
    $parsed = parse_url($uri);
    $path = $parsed['path'] ?? '/';
    $query = $parsed['query'] ?? '';

    // Setup $_SERVER superglobal
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['QUERY_STRING'] = $query;
    $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
    $_SERVER['SERVER_NAME'] = $headers['host'] ?? 'localhost';
    $_SERVER['HTTP_HOST'] = $headers['host'] ?? 'localhost';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = WP_ROOT . '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';
    $_SERVER['DOCUMENT_ROOT'] = WP_ROOT;
    $_SERVER['REQUEST_TIME'] = time();
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);

    // Setup additional headers
    foreach ($headers as $name => $value) {
        $headerName = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $_SERVER[$headerName] = $value;
    }

    // Parse query string into $_GET
    if (!empty($query)) {
        parse_str($query, $_GET);
    }

    return $path;
}

/**
 * Send HTTP response
 *
 * @param resource $client Socket resource
 * @param int $statusCode HTTP status code
 * @param array<string, string|int> $headers Response headers
 * @param string $body Response body
 * @param bool $shouldKeepAlive Whether to keep connection alive
 * @return bool Success status
 */
function sendResponse(mixed $client, int $statusCode, array $headers, string $body, bool $shouldKeepAlive): bool
{
    $statusTexts = [
        200 => 'OK',
        404 => 'Not Found',
        500 => 'Internal Server Error',
    ];

    $statusText = $statusTexts[$statusCode] ?? 'Unknown';

    $response = "HTTP/1.1 $statusCode $statusText\r\n";

    // Add headers
    foreach ($headers as $name => $value) {
        $response .= "$name: $value\r\n";
    }

    // Add connection header
    if ($shouldKeepAlive) {
        $response .= "Connection: keep-alive\r\n";
        $response .= "Keep-Alive: timeout=30, max=1000\r\n";
    } else {
        $response .= "Connection: close\r\n";
    }

    $response .= "\r\n" . $body;

    $written = fwrite($client, $response);
    return $written !== false;
}

/**
 * Close MySQL connection to prevent connection leaks
 *
 * @return void
 */
function closeMySQLConnection(): void
{
    global $wpdb;
    if (isset($wpdb) && $wpdb instanceof wpdb) {
        $wpdb->close();
    }
}

/**
 * Process HTTP request with WordPress
 *
 * @param resource $client Socket resource
 * @param string $rawRequest Raw HTTP request
 * @return bool|null True for keep-alive, false to close, null on error
 */
function processHttpRequest(mixed $client, string $rawRequest): ?bool
{
    $parsedRequest = parseHttpRequest($rawRequest);
    if ($parsedRequest === null) {
        sendResponse($client, 400, ['Content-Type' => 'text/plain'], 'Bad Request', false);
        return false;
    }

    $method = $parsedRequest['method'];
    $uri = $parsedRequest['uri'];
    $headers = $parsedRequest['headers'];
    $shouldKeepAlive = !$parsedRequest['connection_close'];

    try {
        // Setup WordPress environment
        $path = setupWordPressEnvironment($method, $uri, $headers);

        // Remove leading slash for file path
        $filePath = ltrim($path, '/');
        if (empty($filePath)) {
            $filePath = 'index.php';
        }

        $fullPath = WP_ROOT . '/' . $filePath;

        // Check if it's a static file
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (isset(MimeTypes::MAP[$ext]) && is_file($fullPath)) {
            // Serve static file
            $content = file_get_contents($fullPath);
            $responseHeaders = [
                'Content-Type' => MimeTypes::MAP[$ext],
                'Content-Length' => strlen($content),
                'Cache-Control' => 'public, max-age=3600',
            ];

            $success = sendResponse($client, 200, $responseHeaders, $content, $shouldKeepAlive);
            ServerStats::$requestHandled++;
            return $success ? $shouldKeepAlive : false;
        }

        // Process with WordPress
        ob_start();

        // Clone WordPress globals for this request
        WPShared::cloneGlobals();

        // Run WordPress
        wp();

        // Load template
        $template_loader = ABSPATH . WPINC . '/template-loader.php';
        if (file_exists($template_loader)) {
            include $template_loader;
        }

        $output = ob_get_clean();

        $responseHeaders = [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Length' => strlen($output),
            'X-Powered-By' => 'TrueAsync PHP',
        ];

        $success = sendResponse($client, 200, $responseHeaders, $output, $shouldKeepAlive);
        ServerStats::$requestHandled++;

        // Close MySQL connection to prevent connection leaks
        closeMySQLConnection();

        return $success ? $shouldKeepAlive : false;

    } catch (Throwable $e) {
        // Error handling
        @ob_end_clean();

        $errorMessage = "Error: " . $e->getMessage() . "\n\n" . $e->getTraceAsString();
        $errorHtml = '<html><body><h1>Error 500</h1><pre>' . htmlspecialchars($errorMessage) . '</pre></body></html>';

        $responseHeaders = [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Length' => strlen($errorHtml),
        ];

        sendResponse($client, 500, $responseHeaders, $errorHtml, false);
        ServerStats::$requestHandled++;

        // Close MySQL connection even on error
        closeMySQLConnection();

        return false;
    }
}

/**
 * Handle socket connection with keep-alive support
 *
 * @param resource $client Socket resource
 * @return void
 */
function handleSocket(mixed $client): void
{
    ServerStats::$connectionsStarted++;

    try {
        while (true) {
            $request = '';
            $totalBytes = 0;

            // Read HTTP request
            while (true) {
                $chunk = fread($client, 1024);

                if ($chunk === false || $chunk === '') {
                    return;
                }

                $request .= $chunk;
                $totalBytes += strlen($chunk);

                // Request size limit
                if ($totalBytes > 8192) {
                    fclose($client);
                    ServerStats::$requestCount++;
                    return;
                }

                // Check for a complete HTTP request
                if (str_contains($request, "\r\n\r\n")) {
                    break;
                }
            }

            if (empty(trim($request))) {
                continue;
            }

            ServerStats::$requestCount++;

            // Process request
            $shouldKeepAlive = processHttpRequest($client, $request);

            if ($shouldKeepAlive === false) {
                return;
            }
        }

    } finally {
        ServerStats::$connectionsClosed++;
        if (is_resource($client)) {
            fclose($client);
        }
    }
}

/**
 * Start HTTP Server
 *
 * @param string $host Host to bind to
 * @param int $port Port to listen on
 * @return object Fiber/Task object
 * @throws Exception
 */
function startHttpServer(string $host, int $port): object
{
    return spawn(function() use ($host, $port): void {
        $server = stream_socket_server(
            "tcp://$host:$port",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );

        if (!$server) {
            throw new Exception("Could not create server: $errstr ($errno)");
        }

        stream_context_set_option($server, 'socket', 'tcp_nodelay', true);

        echo "Server ready and accepting connections!\n";
        echo "Visit: http://$host:$port/\n\n";

        while (true) {
            $client = stream_socket_accept($server, 0);
            if ($client) {
                ServerStats::$connectionsAccepted++;
                // Spawn coroutine in the separate scope to avoid inheriting globals and Superglobals
                $scope = new Async\Scope(inheritSuperglobals: false);
                $scope->spawn(handleSocket(...), $client);
            }
        }

        fclose($server);
    });
}

/**
 * Statistics reporter coroutine
 */
spawn(function(): void {
    while (true) {
        delay(5000);
        $activeCoroutines = count(getCoroutines());
        $activeConnections = ServerStats::$connectionsStarted - ServerStats::$connectionsClosed;

        echo "[Stats] Connections: Accepted=" . ServerStats::$connectionsAccepted .
             " Started=" . ServerStats::$connectionsStarted .
             " Active=" . $activeConnections .
             " Closed=" . ServerStats::$connectionsClosed .
             " | Requests: Total=" . ServerStats::$requestCount .
             " Handled=" . ServerStats::$requestHandled .
             " | Coroutines: " . $activeCoroutines . "\n";
    }
});

// Start server
try {
    $serverTask = startHttpServer($host, $port);
    await($serverTask);
} catch (Throwable $e) {
    echo "Server error: " . $e->getMessage() . "\n";
    exit(1);
}
