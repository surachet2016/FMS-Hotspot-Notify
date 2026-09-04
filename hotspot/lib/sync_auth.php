<?php
/**
 * Shared authentication for the OpenClaw bridge <-> FMS sync API.
 *
 * The bridge now authenticates with a bearer token sent in the
 * Authorization header instead of a `key` query-string parameter.
 * Query-string secrets end up in Apache access logs, browser history,
 * and proxy logs — a header does not.
 */

function currentSyncBearerToken(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

    // Some PHP/Apache setups (mod_php, PHP-FPM behind certain proxies) drop
    // the Authorization header from $_SERVER; getallheaders() is a fallback.
    if ($header === null && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = $value;
                break;
            }
        }
    }

    if ($header === null || stripos($header, 'Bearer ') !== 0) {
        return null;
    }

    return trim(substr($header, 7));
}

/**
 * Verify the request carries the correct sync bearer token.
 * On failure, sends 401/403 JSON and terminates the request.
 */
function requireSyncBearerAuth(): void
{
    $token = currentSyncBearerToken();

    if ($token === null || $token === '') {
        http_response_code(401);
        header('WWW-Authenticate: Bearer');
        echo json_encode(['error' => 'Missing bearer token']);
        exit;
    }

    if (!hash_equals((string) SYNC_KEY, $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}
