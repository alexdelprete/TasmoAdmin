<?php

namespace TasmoAdmin\Storage;

use TasmoAdmin\Database\DatabaseConfigurationException;

/**
 * Shown when the database cannot be opened, instead of a stack trace.
 */
class StorageErrorPage
{
    public static function render(\Throwable $exception): void
    {
        error_log('TasmoAdmin: storage could not be opened: '.$exception->getMessage());

        $message = $exception instanceof DatabaseConfigurationException
            ? $exception->getMessage()
            : self::redact($exception->getMessage());

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, 'TasmoAdmin: the database could not be opened: '.$message.PHP_EOL);

            return;
        }

        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: text/html; charset=utf-8');
            header('Retry-After: 30');
        }

        $escaped = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo <<<HTML
            <!doctype html>
            <html lang="en">
            <head><meta charset="utf-8"><title>TasmoAdmin: database unavailable</title></head>
            <body style="font-family: system-ui, sans-serif; max-width: 40rem; margin: 4rem auto; padding: 0 1rem;">
            <h1>The database could not be opened</h1>
            <p>{$escaped}</p>
            <p>Check <code>TASMO_DB_URI</code> (or <code>TASMO_DB_URI_FILE</code>) and that the database server is reachable.
            Without either variable TasmoAdmin uses an SQLite file in its data directory; <code>TASMO_STORAGE=files</code> keeps the previous devices.csv and MyConfig.json files.</p>
            </body>
            </html>
            HTML;
    }

    private static function redact(string $message): string
    {
        return (string) preg_replace('#([a-z0-9+.-]+://[^:/@\s]+):[^@\s]*@#i', '$1:***@', $message);
    }
}
