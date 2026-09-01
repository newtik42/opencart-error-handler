# OpenCart 3 Error Handler

A centralized error and exception handler for OpenCart 3, inspired by Laravel's error handling approach.

The handler provides centralized handling of PHP errors, uncaught exceptions, fatal errors, and shutdown errors while preventing sensitive database credentials from being exposed through PHP stack traces.

## Features

* Centralized PHP error handling
* `set_error_handler()` support
* `set_exception_handler()` support
* `register_shutdown_function()` support
* Fatal Error handling
* Parse Error detection through shutdown handling
* Safe stack trace generation
* Database credential protection
* Removes arguments from:

  * `DB\...\__construct()`
  * `DB->__construct()`
* Development and production modes
* HTML error pages
* JSON responses for AJAX/API requests
* OpenCart `Log` integration
* Automatic masking of common secrets
* PHP 7.x / 8.x compatible
* No PHP 8-only syntax such as `match`

---

# Why is this necessary?

PHP stack traces can contain function arguments.

This is especially dangerous in OpenCart because database connection parameters are passed directly to constructors.

For example:

```text
#0 /web/sites/example/system/library/db.php(31): DB\mPDO->__construct(
    'localhost',
    'username',
    'password',
    'database',
    '3306'
)
```

The database password can therefore be exposed through:

* browser error output
* PHP logs
* OpenCart logs
* monitoring systems
* exception reporting systems

This handler removes constructor arguments from database-related calls.

The resulting trace is:

```text
#0 /web/sites/example/system/library/db.php(31): DB\mPDO->__construct()
#1 /web/sites/example/system/framework.php(146): DB->__construct()
```

---

# Installation

Copy:

```text
src/ErrorHandler.php
```

to:

```text
system/library/error_handler.php
```

Example:

```text
/web/sites/example/
├── admin/
├── catalog/
├── system/
│   ├── library/
│   │   └── error_handler.php
│   └── startup.php
├── config.php
└── index.php
```

---

# OpenCart 3 Integration

The error handler should be registered from:

```text
system/startup.php
```

This allows the handler to be registered before the OpenCart framework initializes the database and other application components.

Add:

```php
require_once(DIR_SYSTEM . 'library/error_handler.php');

$errorHandler = new ErrorHandler('production');

$errorHandler->register();
```

For development:

```php
require_once(DIR_SYSTEM . 'library/error_handler.php');

$errorHandler = new ErrorHandler('development');

$errorHandler->register();
```

The important part is that this code executes **before `framework.php` creates the database connection**.

---

# Passing the OpenCart Logger

The OpenCart `$log` object is created later in:

```text
system/framework.php
```

For example:

```php
$log = new Log('error.log');
```

After `$log` is created, pass it to the error handler:

```php
$errorHandler->setLog($log);
```

The resulting initialization flow is:

```text
system/startup.php
        │
        ├── require error_handler.php
        │
        ├── new ErrorHandler('production')
        │
        └── register()
                │
                ▼
        system/framework.php
                │
                ├── $log = new Log('error.log')
                │
                └── $errorHandler->setLog($log)
                                │
                                ▼
                         ErrorHandler
                                │
                                └── $log->write()
```

This means the error handler is active before the database connection is created, but still uses the standard OpenCart logger once `$log` becomes available.

---

# ErrorHandler API

## Constructor

```php
public function __construct(
    $environment = 'production',
    $log = null
)
```

### Development

```php
$errorHandler = new ErrorHandler('development');
```

### Production

```php
$errorHandler = new ErrorHandler('production');
```

### With an existing logger

```php
$errorHandler = new ErrorHandler(
    'production',
    $log
);
```

---

## Register

Register all error handlers:

```php
$errorHandler->register();
```

This registers:

```php
set_error_handler();
set_exception_handler();
register_shutdown_function();
```

---

## Set Logger

The logger can be attached after initialization:

```php
$errorHandler->setLog($log);
```

This is useful for OpenCart because `$log` is not available when `startup.php` initially registers the error handler.

---

# Error Handling

The handler processes PHP errors such as:

```text
E_WARNING
E_NOTICE
E_DEPRECATED
E_USER_ERROR
E_USER_WARNING
E_USER_NOTICE
E_USER_DEPRECATED
```

It also handles uncaught exceptions.

Example:

```php
throw new Exception('Something went wrong');
```

The exception is automatically passed to the registered exception handler.

---

# Fatal Errors

Fatal errors cannot normally be handled by `set_error_handler()`.

The project therefore uses:

```php
register_shutdown_function()
```

together with:

```php
error_get_last()
```

to detect fatal errors.

The following error types are checked:

```text
E_ERROR
E_PARSE
E_CORE_ERROR
E_CORE_WARNING
E_COMPILE_ERROR
E_COMPILE_WARNING
E_USER_ERROR
```

---

# Safe Database Stack Trace

Database constructors are treated specially.

The handler detects:

```text
DB\...\__construct()
```

and:

```text
DB->__construct()
```

and removes their arguments from the generated stack trace.

For example, this:

```text
DB\mPDO->__construct(
    'localhost',
    '1newtik_u8gRwJ',
    'kSQ1V4PHYQV5',
    '1newtik_kaU7GY',
    '3306'
)
```

becomes:

```text
DB\mPDO->__construct()
```

The same applies to:

```text
DB\mysqli->__construct()
DB\PDO->__construct()
DB\mPDO->__construct()
DB->__construct()
```

This prevents database credentials from being exposed by PHP's trace arguments.

---

# Secret Masking

The handler also masks common sensitive values in log messages.

Protected parameter names include:

```text
password
passwd
pwd
pass
token
access_token
refresh_token
secret
api_key
apikey
```

Example:

```text
password=my-secret-password
```

becomes:

```text
password=********
```

This is an additional security layer.

Applications should still avoid placing credentials directly into exception messages.

---

# Development Mode

Use:

```php
$errorHandler = new ErrorHandler('development');
$errorHandler->register();
```

Development mode displays technical information such as:

* exception type
* error message
* source file
* line number
* safe stack trace

Example:

```text
Exception

Failed to connect to database.

/web/sites/example/system/library/db/mpdo.php:11

Stack trace:

#0 /web/sites/example/system/library/db.php(31): DB\mPDO->__construct()
#1 /web/sites/example/system/framework.php(146): DB->__construct()
```

Database constructor arguments are not displayed.

---

# Production Mode

Production should be used on publicly accessible websites:

```php
$errorHandler = new ErrorHandler('production');
$errorHandler->register();
```

Visitors receive only a generic error:

```text
Internal Server Error

An internal server error occurred.
```

Technical details are written to the configured OpenCart logger.

Do not expose development mode on a production website.

---

# AJAX / API Requests

The handler automatically detects JSON/AJAX requests.

JSON responses are returned as:

```json
{
    "success": false,
    "error": {
        "code": 500,
        "message": "Internal Server Error"
    }
}
```

This allows the same handler to be used by:

* OpenCart frontend requests
* admin AJAX requests
* API endpoints
* JavaScript applications

---

# Recommended OpenCart Configuration

## `system/startup.php`

```php
require_once(DIR_SYSTEM . 'library/error_handler.php');

$errorHandler = new ErrorHandler('production');

$errorHandler->register();
```

## `system/framework.php`

After OpenCart creates the logger:

```php
$log = new Log('error.log');

$errorHandler->setLog($log);
```

The handler is now active before database initialization and uses the OpenCart logger once it becomes available.

---

# Project Structure

```text
opencart-error-handler/
├── src/
│   └── ErrorHandler.php
├── examples/
│   └── index.php
├── README.md
├── LICENSE
└── .gitignore
```

---

# Requirements

* OpenCart 3.x
* PHP 7.x or PHP 8.x

The implementation intentionally avoids PHP 8-only syntax such as:

```php
match
```

to maintain compatibility with older OpenCart 3 installations.

---

# Security Considerations

This project is designed to reduce accidental disclosure of sensitive information during error handling.

It does not replace proper application security practices.

Recommended practices:

* Never put passwords in exception messages.
* Never log database credentials.
* Do not expose development mode publicly.
* Protect OpenCart log files.
* Use HTTPS.
* Keep PHP and OpenCart updated.
* Review third-party OpenCart extensions for unsafe error handling.

---

# License

GPL-3.0 License
