# WordPress with TrueAsync PHP

Test build demonstrating WordPress running on **TrueAsync PHP** with pure CLI-based HTTP server - single-threaded execution with coroutine-based concurrency.

## Quick Start

### Build
```bash
docker build -t wordpress-trueasync .
```

### Run
```bash
docker run -d \
  --name wordpress-async \
  -p 8080:8080 \
  wordpress-trueasync
```

### Access
```
http://localhost:8080
```

## How It Works

WordPress runs in a single PHP CLI process with concurrent request handling via coroutines:

```php
// Server accepts connections
while (true) {
    $client = stream_socket_accept($server, 0);
    if ($client) {
        spawn(handleSocket(...), $client);  // Each connection gets its own coroutine
    }
}

// Each request is processed
function processHttpRequest($client, $rawRequest) {
    // Setup WordPress environment
    setupWordPressEnvironment($method, $uri, $headers);

    ob_start();

    // Clone WordPress globals for this request
    WPShared::cloneGlobals();

    // Run WordPress
    wp();

    // Load template
    include ABSPATH . WPINC . '/template-loader.php';

    $output = ob_get_clean();

    // Send HTTP response
    sendResponse($client, 200, $headers, $output, $shouldKeepAlive);
}
```

Multiple requests are handled concurrently in the same PHP process using fiber-based coroutines. The server supports HTTP Keep-Alive for better performance.

### Global Isolation

This works thanks to **global isolation** in TrueAsync PHP:

- Each coroutine has its own isolated `$GLOBALS`
- When a new request coroutine is spawned, it sets unique `$_GET`, `$_POST`, `$_SERVER`, `$_COOKIE` superglobals
- Superglobals are bound to the request scope - all child coroutines within that request inherit them
- Different requests never conflict because their superglobals are isolated from each other
- The `WPShared::cloneGlobals()` mechanism ensures WordPress globals are properly isolated per request

This allows WordPress to handle multiple requests simultaneously in one process without data corruption or race conditions.

### Performance Features

- **HTTP Keep-Alive**: Connections are reused for multiple requests
- **Zero-Copy Static Files**: Static assets (CSS, JS, images) are served directly without WordPress overhead
- **Coroutine-based**: Thousands of concurrent connections with minimal memory footprint
- **Single Process**: No process management overhead, all handled by the event loop

## Configuration

Default credentials:
- Database: `trueasync`
- User: `trueasync`
- Password: `trueasync`

### Deployment Options

**Option 1: Use built-in WordPress (from app/ directory)**
```bash
docker run -d -p 8080:8080 -v $(pwd)/app:/app/www wordpress-trueasync
```

**Option 2: Fresh installation (server files auto-deployed)**
```bash
docker run -d -p 8080:8080 wordpress-trueasync
```
The container will automatically copy `server.php` and `wp-loader.php` if they don't exist.

**Option 3: Custom WordPress installation**
```bash
docker run -d -p 8080:8080 -v /path/to/wordpress:/app/www wordpress-trueasync
```
Make sure to copy `server.php` and `wp-loader.php` to your WordPress root.

## Running the Server Manually

You can also run the server directly without Docker:

```bash
cd /path/to/wordpress
php server.php 0.0.0.0 8080
```

## Benchmarking

Test with wrk:

```bash
wrk -t12 -c400 -d30s http://localhost:8080/
```

## Purpose

This is a **test environment** to demonstrate coroutine-based concurrent request handling in WordPress with TrueAsync PHP using a pure PHP HTTP server.

## Architecture

- **No Web Server**: Direct PHP CLI server using stream sockets
- **Event Loop**: Built on TrueAsync's libuv-based reactor
- **Keep-Alive**: Full HTTP/1.1 connection reuse support
- **WordPress Integration**: Native PHP implementation without any middleware

## Resources

- [TrueAsync PHP](https://github.com/true-async/php-src)
- [TrueAsync Extension](https://github.com/true-async/php-async)
- [HTTP Server Benchmark](https://github.com/true-async/php-async/blob/main/benchmarks/http_server_keepalive.php)
