<?php

namespace App\Support;

/**
 * Database failure handling: the real mysqli error goes to the server log
 * (Developer > Error Logs), never to the browser. Responses carry only a
 * human message.
 */
class Db
{
    /** Log the connection's current error server-side, if any. */
    public static function log(\mysqli $conn, string $context = ''): void
    {
        if ($conn->error !== '') {
            error_log('[db]' . ($context !== '' ? " {$context}:" : '') . ' [' . $conn->errno . '] ' . $conn->error);
        }
    }

    /** Log the SQL error and return a safe message for exceptions/messages. */
    public static function oops(\mysqli $conn, string $msg = 'A database error occurred.'): string
    {
        self::log($conn);
        return $msg;
    }

    /** Standard failed-JSON response that logs the SQL error instead of leaking it. */
    public static function fail(\mysqli $conn, string $msg = 'The operation failed. Please try again.'): string
    {
        self::log($conn);
        return json_encode(['status' => 'failed', 'msg' => $msg]);
    }
}
