# OpenCart 3 Error Handler

A production-oriented error handler for OpenCart 3 that provides Laravel-like exception and error handling.

The handler:

* Captures PHP errors and exceptions.
* Registers a global exception handler.
* Registers a shutdown handler for fatal errors.
* Hides sensitive database connection arguments from stack traces.
* Specifically protects `DB\...\__construct()` arguments.
* Integrates with the native OpenCart `$log`.
* Supports production and development environments.
* Creates backups before modifying OpenCart files.
* Includes an automated installer.
* Provides both CLI and web-based installation.

## Requirements

* OpenCart 3.x
* PHP 7.0+
* PHP CLI for CLI installation
* `allow_url_fopen = On` for downloading files from GitHub with `file_get_contents()`

---

# Installation

## Automatic installation

The easiest way to install the Error Handler is to download `install.php` directly from GitHub and run it with PHP.

From the OpenCart root directory:

```bash
wget https://raw.githubusercontent.com/newtik42/opencart-error-handler/master/install.php
php install.php
```

The installer automatically:

1. Detects the OpenCart installation.
2. Creates backups.
3. Downloads the latest `ErrorHandler.php` from GitHub.
4. Installs it into `system/library/error_handler.php`.
5. Updates `system/startup.php`.
6. Connects the Error Handler to the OpenCart `$log`.
7. Updates `system/framework.php`.
8. Checks PHP syntax.
9. Runs a final installation check.

The Error Handler is downloaded from:

```text
https://raw.githubusercontent.com/newtik42/opencart-error-handler/refs/heads/master/src/ErrorHandler.php
```

### Install a specific OpenCart directory

If `install.php` is not located in the OpenCart root:

```bash
wget https://raw.githubusercontent.com/newtik42/opencart-error-handler/master/install.php
php install.php /web/sites/multibank_credit
```

---

# CLI

The installer supports several CLI commands.

## Full installation

```bash
php install.php all
```

or:

```bash
php install.php install
```

Both commands perform the complete installation and verification process.

Example:

```text
=============================================
 OpenCart 3 Error Handler
 Full Installation
=============================================

[1/7] Checking OpenCart...
      OK

[2/7] Creating backup...
      OK

[3/7] Downloading ErrorHandler.php...
      OK

[4/7] Updating startup.php...
      OK

[5/7] Updating framework.php...
      OK

[6/7] Checking PHP syntax...
      OK

[7/7] Running final check...
      OK

=============================================
 INSTALLATION SUCCESSFUL
=============================================
```

## Check installation

```bash
php install.php check
```

The command verifies:

* OpenCart files
* `ErrorHandler.php`
* PHP syntax
* `startup.php` integration
* `framework.php` integration
* OpenCart logger integration
* backup availability

Exit codes:

```text
0 = success
1 = failed
```

This makes the command suitable for deployment scripts.

## Restore backup

```bash
php install.php restore
```

The installer asks for confirmation before restoring the original files.

## Delete installer

After successful installation:

```bash
php install.php delete
```

This permanently removes `install.php`.

---

# Web Installation

`install.php` can also be opened through a web browser.

Copy `install.php` to the OpenCart root and open:

```text
https://example.com/install.php
```

The web interface provides:

* **Install / Update**
* **Check Installation**
* **Restore Backup**
* **Delete Installer**

The interface also displays the status of each installation check.

After installation, delete `install.php` using **Delete Installer**.

---

# OpenCart Integration

The installer registers the Error Handler from `system/startup.php`:

```php
require_once(DIR_SYSTEM . 'library/error_handler.php');

$errorHandler = new ErrorHandler('production');
$errorHandler->register();
```

The OpenCart logger is connected from `system/framework.php`:

```php
$log = new Log('error.log');

$errorHandler->setLog($log);
```

This allows the Error Handler to use the native OpenCart logging mechanism.

---

# Database Exception Protection

Database connection exceptions can contain sensitive connection parameters.

For example:

```text
#0 /system/library/db.php(31):
DB\mPDO->__construct(
    'localhost',
    'username',
    'password',
    'database',
    '3306'
)
```

The Error Handler detects database constructor calls using:

```text
DB\
```

and:

```text
__construct
```

and removes their arguments from the displayed stack trace.

The resulting trace does not expose database credentials.

---

# Backup

Before modifying OpenCart files, the installer creates:

```text
.error-handler-backup/
```

The original files are preserved there.

Typical backup files:

```text
.error-handler-backup/
├── system__startup.php
├── system__framework.php
└── system__library__error_handler.php
```

The backup is not overwritten during subsequent installations.

To restore the original files:

```bash
php install.php restore
```

---

# Manual Installation

If you do not want to use the installer, copy:

```text
src/ErrorHandler.php
```

to:

```text
system/library/error_handler.php
```

Then add to `system/startup.php`:

```php
require_once(DIR_SYSTEM . 'library/error_handler.php');

$errorHandler = new ErrorHandler('production');
$errorHandler->register();
```

After OpenCart creates its `$log` instance in `system/framework.php`:

```php
$errorHandler->setLog($log);
```

---

# Development Environment

For development, initialize the handler with:

```php
$errorHandler = new ErrorHandler('development');
```

For production:

```php
$errorHandler = new ErrorHandler('production');
```

Production mode should be used on public websites because sensitive information should not be exposed to visitors.

---

# Security

The installer modifies PHP files and should not remain publicly accessible after installation.

After completing the installation:

```bash
php install.php delete
```

or:

```bash
rm install.php
```

Do not leave `install.php` publicly accessible on a production website.

---

# Updating

To update the Error Handler, download the current installer again:

```bash
wget -O install.php \
https://raw.githubusercontent.com/newtik42/opencart-error-handler/master/install.php
```

Then run:

```bash
php install.php all
```

The installer preserves the original backup and verifies the resulting installation.

---
