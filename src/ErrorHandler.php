<?php

/* * ******************************************************* */
/* 	@copyright	NewTik Ltd. (https://newtik-opencart.com/).  */
/* 	@support	https://newtik-opencart.com/			     */
/* 	@license	https://opensource.org/licenses/GPL-3.0      */
/* * ******************************************************* */

/**
 * OpenCart 3 Error Handler
 *
 * Перехоплює:
 * - PHP Warning
 * - PHP Notice
 * - PHP Deprecated
 * - User errors
 * - Uncaught Exception
 * - Fatal Error
 * - Parse Error
 *
 */
class ErrorHandler {

    /**
     * OpenCart Log object
     *
     * @var object|null
     */
    private $log;

    /**
     * Environment
     *
     * development | production
     *
     * @var string
     */
    private $environment;

    /**
     * Чи вже була відрендерена помилка
     *
     * @var bool
     */
    private $handled = false;

    /**
     * Constructor
     *
     * @param object|null $log
     * @param string $environment
     */
    public function __construct($environment = 'production', $log = null){        
        $this->environment = $environment;
        $this->log = $log;
    }

    public function setLog($log) {
        $this->log = $log;
    }

    /**
     * Register handlers
     */
    public function register() {
        /*
         * Важливо:
         * Не включаємо E_ERROR/E_PARSE/E_CORE_ERROR/E_COMPILE_ERROR,
         * оскільки вони не передаються set_error_handler().
         */
        set_error_handler(array($this, 'handleError'));

        set_exception_handler(array($this, 'handleException'));

        register_shutdown_function(array($this, 'handleShutdown'));
    }

    /**
     * PHP Warning / Notice / Deprecated / User errors
     *
     * @return bool
     */
    public function handleError($severity, $message, $file, $line) {
        /*
         * Якщо помилка подавлена оператором @,
         * не обробляємо її.
         */
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $error = array(
            'type' => $this->errorType($severity),
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'url' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null,
            'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null,
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null
        );

        $this->report($error);

        /*
         * true = помилка повністю оброблена.
         *
         * Завдяки цьому PHP не виведе:
         *
         * Warning: ...
         *
         * прямо в HTML.
         */
        return true;
    }

    private function getSafeTrace($exception) {
        $trace = $exception->getTrace();

        foreach ($trace as $index => $item) {

            if (
                    isset($item['class'], $item['function']) && strpos($item['class'], 'DB') === 0 && $item['function'] === '__construct'
            ) {
                $trace[$index]['args'] = array();
            }
        }

        $result = array();

        foreach ($trace as $index => $item) {
            $file = isset($item['file']) ? $item['file'] : '[internal]';
            $line = isset($item['line']) ? $item['line'] : '';

            $class = isset($item['class']) ? $item['class'] : '';
            $type = isset($item['type']) ? $item['type'] : '';
            $function = isset($item['function']) ? $item['function'] : '';

            $args = '';

            if (isset($item['args']) && !empty($item['args'])) {
                $args = implode(', ', array_map(function ($arg) {
                            return var_export($arg, true);
                        }, $item['args']));
            }

            $result[] = sprintf(
                    '#%d %s(%s): %s%s%s(%s)',
                    $index,
                    $file,
                    $line,
                    $class,
                    $type,
                    $function,
                    $args
            );
        }

        return implode("\n", $result);
    }

    /**
     * Uncaught Exception
     */
    public function handleException($exception) {
        if ($this->handled) {
            return;
        }

        $this->handled = true;

        $error = array(
            'type' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $this->getSafeTrace($exception),
            'url' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null,
            'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null,
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null
        );
        $this->report($error);

        $this->renderException($exception);
    }

    /**
     * Fatal Error
     */
    public function handleShutdown() {
        /*
         * Якщо exception handler вже відрендерив відповідь,
         * нічого не робимо.
         */
        if ($this->handled) {
            return;
        }

        $error = error_get_last();

        if (!$error) {
            return;
        }

        $fatal_types = array(
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_CORE_WARNING,
            E_COMPILE_ERROR,
            E_COMPILE_WARNING,
            E_USER_ERROR
        );

        if (!in_array($error['type'], $fatal_types, true)) {
            return;
        }

        $this->handled = true;

        $data = array(
            'type' => $this->errorType($error['type']),
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line'],
            'url' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null,
            'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null,
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null
        );

        $this->report($data);

        /*
         * Якщо заголовки вже відправлені,
         * не намагаємося встановити HTTP status.
         */
        if (!headers_sent()) {
            http_response_code(500);

            $this->renderFatal($data);
        }
    }

    /**
     * Report error
     */
    private function report($error) {
        /*
         * Формуємо лог.
         */
        $message = sprintf(
                "[%s] %s: %s in %s:%d",
                date('Y-m-d H:i:s'),
                isset($error['type']) ? $error['type'] : 'Error',
                isset($error['message']) ? $error['message'] : '',
                isset($error['file']) ? $error['file'] : '',
                isset($error['line']) ? $error['line'] : 0
        );

        /*
         * Stack trace.
         */
        if (!empty($error['trace'])) {
            $message .= "\n" . $error['trace'];
        }


        /*
         * Request context.
         */
        if (!empty($error['url'])) {
            $message .= "\nURL: " . $error['url'];
        }

        if (!empty($error['method'])) {
            $message .= "\nMETHOD: " . $error['method'];
        }

        if (!empty($error['ip'])) {
            $message .= "\nIP: " . $error['ip'];
        }

        /*
         * Маскуємо можливі паролі/токени.
         */
        $message = $this->sanitize($message);

        /*
         * OpenCart Log.
         */
        if ($this->log && method_exists($this->log, 'write')) {
            $this->log->write($message);
        } else {
            error_log($message);
        }
    }

    /**
     * Маскування секретів
     */
    private function sanitize($message) {
        $patterns = array(
            /*
             * password=value
             */
            '/((?:password|passwd|pwd|pass)\s*[=:]\s*)([^&\s]+)/i',
            /*
             * token=value
             */
            '/((?:token|access_token|refresh_token)\s*[=:]\s*)([^&\s]+)/i',
            /*
             * secret=value
             */
            '/((?:secret|api_key|apikey)\s*[=:]\s*)([^&\s]+)/i',
            /*
             * JSON:
             * "password":"123"
             */
            '/((?:"|\')?(?:password|passwd|pwd|token|secret|api_key|apikey)(?:"|\')?\s*:\s*)(?:"|\')?([^,"\'}\s]+)(?:"|\')?/i'
        );

        foreach ($patterns as $pattern) {
            $message = preg_replace(
                    $pattern,
                    '$1********',
                    $message
            );
        }

        return $message;
    }

    /**
     * Error type
     */
    private function errorType($severity) {
        switch ($severity) {
            case E_ERROR:
                return 'Fatal Error';

            case E_WARNING:
                return 'Warning';

            case E_PARSE:
                return 'Parse Error';

            case E_NOTICE:
                return 'Notice';

            case E_CORE_ERROR:
                return 'Core Error';

            case E_CORE_WARNING:
                return 'Core Warning';

            case E_COMPILE_ERROR:
                return 'Compile Error';

            case E_COMPILE_WARNING:
                return 'Compile Warning';

            case E_USER_ERROR:
                return 'User Error';

            case E_USER_WARNING:
                return 'User Warning';

            case E_USER_NOTICE:
                return 'User Notice';

            case E_DEPRECATED:
                return 'Deprecated';

            case E_USER_DEPRECATED:
                return 'User Deprecated';

            default:
                return 'Unknown Error';
        }
    }

    /**
     * Render Exception
     */
    private function renderException($exception) {
        if ($this->isJsonRequest()) {
            $this->jsonResponse(
                    500,
                    'Internal Server Error'
            );

            return;
        }

        http_response_code(500);

        /*
         * Development mode:
         * показуємо деталі.
         */
        if ($this->environment === 'development') {
            echo $this->developmentPage(
                    get_class($exception),
                    $exception->getMessage(),
                    $exception->getFile(),
                    $exception->getLine(),
                    $this->getSafeTrace($exception)
            );

            return;
        }

        /*
         * Production.
         */
        echo $this->errorPage(
                'Internal Server Error',
                'Сталася внутрішня помилка сервера.'
        );
    }

    /**
     * Render Fatal Error
     */
    private function renderFatal($error) {
        if ($this->isJsonRequest()) {
            $this->jsonResponse(
                    500,
                    'Internal Server Error'
            );

            return;
        }

        /*
         * Development.
         */
        if ($this->environment === 'development') {
            echo $this->developmentPage(
                    isset($error['type']) ? $error['type'] : 'Fatal Error',
                    isset($error['message']) ? $error['message'] : '',
                    isset($error['file']) ? $error['file'] : '',
                    isset($error['line']) ? $error['line'] : 0,
                    ''
            );

            return;
        }

        /*
         * Production.
         */
        echo $this->errorPage(
                'Internal Server Error',
                'Сталася внутрішня помилка сервера.'
        );
    }

    /**
     * JSON response
     */
    private function jsonResponse($status, $message) {
        if (!headers_sent()) {
            http_response_code($status);

            header(
                    'Content-Type: application/json; charset=utf-8'
            );
        }

        echo json_encode(
                array(
                    'success' => false,
                    'error' => array(
                        'code' => $status,
                        'message' => $message
                    )
                ),
                JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Check AJAX / JSON request
     */
    private function isJsonRequest() {
        $accept = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';

        $content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';

        $xhr = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : '';

        return (
                strpos($accept, 'application/json') !== false || strpos($content_type, 'application/json') !== false || strtolower($xhr) === 'xmlhttprequest'
        );
    }

    /**
     * Production error page
     */
    private function errorPage($title, $message) {
        $title = htmlspecialchars(
                $title,
                ENT_QUOTES,
                'UTF-8'
        );

        $message = htmlspecialchars(
                $message,
                ENT_QUOTES,
                'UTF-8'
        );

        return '<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . $title . '</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 40px 20px;
    background: #f5f5f5;
    color: #333;
    font-family: Arial, Helvetica, sans-serif;
}

.error {
    width: 100%;
    max-width: 700px;
    margin: 60px auto;
    padding: 40px;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 15px rgba(0, 0, 0, .08);
}

.error h1 {
    margin: 0 0 20px;
    font-size: 28px;
}

.error p {
    margin: 0;
    color: #666;
    line-height: 1.6;
}
</style>

</head>

<body>

<div class="error">

<h1>' . $title . '</h1>

<p>' . $message . '</p>

</div>

</body>
</html>';
    }

    /**
     * Development error page
     */
    private function developmentPage(
            $type,
            $message,
            $file,
            $line,
            $trace
    ) {
        $type = htmlspecialchars(
                $type,
                ENT_QUOTES,
                'UTF-8'
        );

        $message = htmlspecialchars(
                $message,
                ENT_QUOTES,
                'UTF-8'
        );

        $file = htmlspecialchars(
                $file,
                ENT_QUOTES,
                'UTF-8'
        );

        $trace = htmlspecialchars(
                $trace,
                ENT_QUOTES,
                'UTF-8'
        );

        return '<!DOCTYPE html>
<html lang="uk">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1">

<title>' . $type . '</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 30px;
    background: #f5f5f5;
    color: #333;
    font-family:
        Consolas,
        Monaco,
        monospace;
}

.container {
    max-width: 1200px;
    margin: 0 auto;
}

.header {
    background: #fff;
    padding: 25px;
    border-radius: 8px;
    margin-bottom: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,.08);
}

.type {
    font-size: 14px;
    color: #888;
    margin-bottom: 10px;
}

.message {
    font-size: 20px;
    font-weight: bold;
    word-break: break-word;
}

.file {
    margin-top: 15px;
    color: #666;
    word-break: break-all;
}

.trace {
    background: #1e1e1e;
    color: #ddd;
    padding: 25px;
    border-radius: 8px;
    overflow-x: auto;
    white-space: pre-wrap;
    line-height: 1.5;
}

</style>

</head>

<body>

<div class="container">

<div class="header">

<div class="type">
' . $type . '
</div>

<div class="message">
' . $message . '
</div>

<div class="file">
' . $file . ':' . (int) $line . '
</div>

</div>

<pre class="trace">' . $trace . '</pre>

</div>

</body>

</html>';
    }
}
