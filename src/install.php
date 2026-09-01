<?php

/**
 * OpenCart 3 Error Handler Installer
 *
 * CLI:
 *   php install.php
 *   php install.php all
 *   php install.php check
 *   php install.php restore
 *   php install.php delete
 *
 * Specific OpenCart path:
 *   php install.php all /web/sites/multibank_credit
 *
 * Browser:
 *   https://example.com/install.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

class ErrorHandlerInstaller {

    /**
     * GitHub source.
     */
    private $githubUrl = 'https://raw.githubusercontent.com/' .
            'newtik42/opencart-error-handler/' .
            'refs/heads/master/src/ErrorHandler.php';
    private $root;
    private $libraryFile;
    private $startupFile;
    private $frameworkFile;
    private $backupDir;

    public function __construct($root = null) {
        if ($root === null || $root === '') {
            $root = dirname(__FILE__);
        }

        $realRoot = realpath($root);

        if ($realRoot === false) {
            throw new RuntimeException('OpenCart directory does not exist: ' . $root);
        }

        $this->root = rtrim($realRoot, DIRECTORY_SEPARATOR);

        $this->libraryFile = $this->root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'library' . DIRECTORY_SEPARATOR . 'error_handler.php';

        $this->startupFile = $this->root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'startup.php';

        $this->frameworkFile = $this->root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'framework.php';

        if (is_file($this->root . "/config.php")) {
            require_once $this->root . "/config.php";
        }

        $this->backupDir = DIR_STORAGE . DIRECTORY_SEPARATOR . '.error-handler-backup';
    }

    /**
     * Full installation.
     */
    public function install() {
        $this->output('[1/7] Checking OpenCart...');

        $this->checkOpenCart();

        $this->output('[2/7] Creating backup...');

        $this->createBackupDir();

        //$this->backupFile($this->startupFile);
        $this->backupFile($this->frameworkFile);
        $this->backupFile($this->libraryFile);

        $this->output('[3/7] Downloading ErrorHandler.php...');

        $this->downloadErrorHandler();

        $this->output('[4/7] Updating startup.php...');

        //$this->installStartup();

        $this->output('[5/7] Updating framework.php...');

        $this->installFramework();

        $this->output('[6/7] Checking PHP syntax...');

        $files = array(
            $this->libraryFile,
            $this->startupFile,
            $this->frameworkFile
        );
        /*
        foreach ($files as $file) {
            $syntax = $this->checkSyntax($file);

            if (!$syntax['success']) {
                throw new RuntimeException('PHP syntax error in ' . $file . PHP_EOL . $syntax['message']);
            }

            $this->output('      [OK] ' . $file);
        }
        */
        $this->output('[7/7] Running final check...');

        return $this->check();
    }

    /**
     * Check installation.
     */
    public function check() {
        $result = array(
            'success' => true,
            'checks' => array()
        );

        $this->addCheck($result, 'PHP version', version_compare(PHP_VERSION, '7.0.0', '>='), PHP_VERSION);

        $this->addCheck($result, 'OpenCart system directory', is_dir($this->root . '/system'), $this->root . '/system');

        $this->addCheck($result, 'system/startup.php', is_file($this->startupFile), $this->startupFile);

        $this->addCheck($result, 'system/framework.php', is_file($this->frameworkFile), $this->frameworkFile);

        $this->addCheck($result, 'ErrorHandler.php', is_file($this->libraryFile), $this->libraryFile);

        if (is_file($this->libraryFile)) {
            $syntax = $this->checkSyntax($this->libraryFile);

            $this->addCheck($result, 'ErrorHandler syntax', $syntax['success'], $syntax['message']);
        }

        if (is_file($this->startupFile)) {
            $content = file_get_contents($this->startupFile);

            if ($content === false) {
                $this->addCheck($result, 'startup.php readable', false, 'Unable to read startup.php');
            } else {
                $installed = strpos($content, "library/error_handler.php") !== false && strpos($content, '$errorHandler->register()') !== false;

                $this->addCheck($result, 'startup.php integration', $installed, $installed ? 'ErrorHandler registered' : 'ErrorHandler integration not found');
            }
        }

        if (is_file($this->frameworkFile)) {
            $content = file_get_contents(
                    $this->frameworkFile
            );

            if ($content === false) {
                $this->addCheck($result, 'framework.php readable', false, 'Unable to read framework.php');
            } else {
                $installed = strpos($content, '$errorHandler->setLog($log)') !== false;

                $this->addCheck($result, 'framework.php integration', $installed, $installed ? 'OpenCart logger connected' : 'setLog() integration not found');
            }
        }

        $this->addCheck($result, 'Backup directory', is_dir($this->backupDir), is_dir($this->backupDir) ? $this->backupDir : 'Backup not found');

        return $result;
    }

    /**
     * Restore original files.
     */
    public function restore() {
        if (!is_dir($this->backupDir)) {
            throw new RuntimeException('Backup directory does not exist: ' . $this->backupDir);
        }

        $files = array(
            'system__startup.php' => $this->startupFile,
            'system__framework.php' => $this->frameworkFile,
            'system__library__error_handler.php' => $this->libraryFile
        );

        $restored = array();

        foreach ($files as $backup => $target) {
            $source = $this->backupDir .
                    DIRECTORY_SEPARATOR .
                    $backup;

            if (!is_file($source)) {
                continue;
            }

            if (!copy($source, $target)) {
                throw new RuntimeException('Unable to restore: ' . $target);
            }

            $restored[] = $target;
        }

        return $restored;
    }

    /**
     * Delete this installer.
     */
    public function deleteInstaller() {
        $installer = __FILE__;

        if (!is_file($installer)) {
            throw new RuntimeException('Installer file not found.');
        }

        if (!is_writable($installer)) {
            throw new RuntimeException('Installer is not writable.');
        }

        if (!unlink($installer)) {
            throw new RuntimeException('Unable to delete installer.');
        }

        return true;
    }

    /**
     * Check OpenCart.
     */
    private function checkOpenCart() {
        if (!is_dir($this->root . '/system')) {
            throw new RuntimeException('OpenCart system directory not found: ' . $this->root . '/system');
        }

        if (!is_file($this->startupFile)) {
            throw new RuntimeException('system/startup.php not found.');
        }

        if (!is_file($this->frameworkFile)) {
            throw new RuntimeException('system/framework.php not found.');
        }

        $libraryDir = $this->root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'library';

        if (!is_dir($libraryDir)) {
            throw new RuntimeException('system/library directory not found.');
        }

        if (!is_readable($this->startupFile)) {
            throw new RuntimeException('system/startup.php is not readable.');
        }

        if (!is_writable($this->startupFile)) {
            throw new RuntimeException('system/startup.php is not writable.');
        }

        if (!is_readable($this->frameworkFile)) {
            throw new RuntimeException('system/framework.php is not readable.');
        }

        if (!is_writable($this->frameworkFile)) {
            throw new RuntimeException('system/framework.php is not writable.');
        }

        if (is_file($this->libraryFile) && !is_writable($this->libraryFile)) {
            throw new RuntimeException('system/library/error_handler.php is not writable.');
        }
    }

    /**
     * Create backup directory.
     */
    private function createBackupDir() {
        if (is_dir($this->backupDir)) {
            return;
        }

        if (!mkdir($this->backupDir, 0755, true)) {
            throw new RuntimeException('Unable to create backup directory: ' . $this->backupDir);
        }
    }

    /**
     * Backup file only once.
     */
    private function backupFile($file) {
        if (!is_file($file)) {
            return;
        }

        $relative = str_replace($this->root . DIRECTORY_SEPARATOR, '', $file);

        $backup = $this->backupDir . DIRECTORY_SEPARATOR . str_replace(DIRECTORY_SEPARATOR, '__', $relative);

        /*
         * Do not overwrite original backup.
         */
        if (is_file($backup)) {
            return;
        }

        if (!copy($file, $backup)) {
            throw new RuntimeException('Unable to create backup: ' . $file);
        }
    }

    /**
     * Download ErrorHandler.php from GitHub.
     *
     * file_get_contents() only.
     */
    private function downloadErrorHandler() {

        $content = file_get_contents($this->githubUrl);

        restore_error_handler();

        if ($content === false) {
            $message = 'Unable to download ErrorHandler.php from GitHub.';

            if ($lastError !== null) {
                $message .= PHP_EOL . $lastError;
            }

            throw new RuntimeException($message);
        }

        if (trim($content) === '') {
            throw new RuntimeException('Downloaded ErrorHandler.php is empty.');
        }
        /*
         * Validate downloaded source.
         */
        if (strpos($content, '<?php') === false || strpos($content, 'class ErrorHandler') === false) {
            throw new RuntimeException('Downloaded file is not a valid ErrorHandler.php.');
        }

        if (file_put_contents($this->libraryFile, $content) === false) {
            throw new RuntimeException('Unable to write ErrorHandler.php to: ' . $this->libraryFile);
        }

        $this->output('      [OK] ErrorHandler.php downloaded and validated.');
    }

    /**
     * Add ErrorHandler to startup.php.
     */
    private function installStartup() {
        $content = file_get_contents($this->startupFile);

        if ($content === false) {
            throw new RuntimeException('Unable to read startup.php.');
        }

        /*
         * Already installed.
         */
        if (strpos($content, "library/error_handler.php") !== false) {
            $this->output('      [OK] ErrorHandler already exists in startup.php.');
            return;
        }

        $code = <<<'PHP'

/*
 * OpenCart Error Handler
 */
require_once(DIR_SYSTEM . 'library/error_handler.php');

$errorHandler = new ErrorHandler('production');
$errorHandler->register();

PHP;

        $position = strpos($content, '<?php');

        if ($position === false) {
            throw new RuntimeException('PHP opening tag not found in startup.php.');
        }

        $position += 5;

        $newContent = substr($content, 0, $position) . $code . substr($content, $position);

        if (file_put_contents($this->startupFile, $newContent, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write startup.php.');
        }

        $this->output('      [OK] startup.php updated.');
    }

    /**
     * Connect ErrorHandler to OpenCart logger.
     */
    private function installFramework() {
        $content = file_get_contents($this->frameworkFile);

        if ($content === false) {
            throw new RuntimeException('Unable to read framework.php.');
        }

        /*
         * Already installed.
         */
        if (strpos($content, '$errorHandler->setLog($log)') !== false) {
            $this->output('      [OK] $errorHandler->setLog($log) already exists.');

            return;
        }

        /*
         * Find:
         *
         * $log = new Log(...);
         *
         * OpenCart 3 normally has:
         *
         * $log = new Log('error.log');
         */
        $pattern = '/(\$log\s*=\s*new\s+Log\s*\([^;]*\);\s*)/';

        $replacement = '$1' . PHP_EOL . 'require_once(DIR_SYSTEM . \'library/error_handler.php\');

$errorHandler = new ErrorHandler\'development\', $log);
$errorHandler->register();' . PHP_EOL;

        $count = 0;

        $newContent = preg_replace($pattern, $replacement, $content, 1, $count);

        if ($newContent === null || $count === 0) {
            throw new RuntimeException('Unable to find "$log = new Log(...);" ' . 'in framework.php.');
        }

        if (file_put_contents($this->frameworkFile, $newContent, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write framework.php.');
        }

        $this->output('      [OK] framework.php updated.');
    }

    /**
     * PHP syntax check.
     */
    private function checkSyntax($file) {
        if (!is_file($file)) {
            return array(
                'success' => false,
                'message' => 'File not found.'
            );
        }

        $php = null;

        /*
         * PHP_BINDIR is more reliable than PHP_BINARY
         * on some older PHP/OpenCart environments.
         */
        if (defined('PHP_BINDIR') && PHP_BINDIR !== '') {
            $candidate = rtrim(PHP_BINDIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php';

            if (is_file($candidate) && is_executable($candidate)) {
                $php = $candidate;
            }
        }

        /*
         * Fallback to current PHP executable.
         */
        if ($php === null && defined('PHP_BINARY')) {
            if (PHP_BINARY !== '' && is_file(PHP_BINARY) && is_executable(PHP_BINARY)) {
                $php = PHP_BINARY;
            }
        }

        /*
         * Last fallback: find php in PATH.
         */
        if ($php === null) {
            $output = array();
            $returnCode = 0;

            exec('command -v php 2>/dev/null', $output, $returnCode);

            if ($returnCode === 0 && !empty($output[0])) {
                $candidate = trim($output[0]);

                if (is_file($candidate) && is_executable($candidate)) {
                    $php = $candidate;
                }
            }
        }

        if ($php === null) {
            return array(
                'success' => false,
                'message' =>
                'Unable to locate PHP CLI executable.'
            );
        }

        $output = array();
        $returnCode = 0;

        $command = escapeshellarg($php) . ' -l ' . escapeshellarg($file) . ' 2>&1';

        exec($command, $output, $returnCode);

        return array(
            'success' => $returnCode === 0,
            'message' => implode(' ', $output)
        );
    }

    /**
     * Add check result.
     */
    private function addCheck(&$result, $name, $success, $message) {
        $result['checks'][] = array(
            'name' => $name,
            'success' => (bool) $success,
            'message' => (string) $message
        );

        if (!$success) {
            $result['success'] = false;
        }
    }

    /**
     * Output helper.
     */
    private function output($message) {
        if (php_sapi_name() === 'cli') {
            echo $message . PHP_EOL;
        }
    }

    /**
     * Get OpenCart root.
     */
    public function getRoot() {
        return $this->root;
    }
}

/*
 * ============================================================
 * CLI
 * ============================================================
 */

if (php_sapi_name() === 'cli') {

    $command = isset($argv[1]) ? strtolower($argv[1]) : 'all';

    /*
     * If the first argument is a directory,
     * treat it as OpenCart root and use "all".
     */
    if (
            isset($argv[1]) &&
            is_dir($argv[1])
    ) {
        $command = 'all';
        $root = $argv[1];
    } else {
        $root = isset($argv[2]) ? $argv[2] : dirname(__FILE__);
    }

    try {

        $installer = new ErrorHandlerInstaller($root);

        switch ($command) {

            /*
             * =================================================
             * ALL / INSTALL
             * =================================================
             */

            case 'all':
            case 'install':

                echo PHP_EOL;
                echo "=============================================" . PHP_EOL;
                echo " OpenCart 3 Error Handler" . PHP_EOL;
                echo " Full Installation" . PHP_EOL;
                echo "=============================================" . PHP_EOL;

                echo PHP_EOL;
                echo "OpenCart root:" . PHP_EOL;
                echo "  " . $installer->getRoot() . PHP_EOL;

                echo PHP_EOL;

                $result = $installer->install();

                echo PHP_EOL;

                if ($result['success']) {

                    echo "=============================================" . PHP_EOL;
                    echo " INSTALLATION SUCCESSFUL" . PHP_EOL;
                    echo "=============================================" . PHP_EOL;

                    echo PHP_EOL;
                    echo "All checks passed." . PHP_EOL;

                    exit(0);
                } else {

                    echo "=============================================" . PHP_EOL;
                    echo " INSTALLATION FAILED" . PHP_EOL;
                    echo "=============================================" . PHP_EOL;

                    echo PHP_EOL;

                    foreach (
                            $result['checks']
                    as $check
                    ) {
                        echo $check['success'] ? "[OK]   " : "[FAIL] ";

                        echo $check['name'];

                        if (
                                $check['message'] !== ''
                        ) {
                            echo " - " .
                            $check['message'];
                        }

                        echo PHP_EOL;
                    }

                    exit(1);
                }

                break;

            /*
             * =================================================
             * CHECK
             * =================================================
             */

            case 'check':

                echo PHP_EOL;
                echo "=============================================" . PHP_EOL;
                echo " OpenCart 3 Error Handler" . PHP_EOL;
                echo " System Check" . PHP_EOL;
                echo "=============================================" . PHP_EOL;

                echo PHP_EOL;

                $result = $installer->check();

                $failed = 0;

                foreach (
                        $result['checks']
                as $check
                ) {
                    echo $check['success'] ? "[OK]   " : "[FAIL] ";

                    if (!$check['success']) {
                        $failed++;
                    }

                    echo $check['name'];

                    if (
                            $check['message'] !== ''
                    ) {
                        echo " - " .
                        $check['message'];
                    }

                    echo PHP_EOL;
                }

                echo PHP_EOL;

                if ($failed === 0) {
                    echo "RESULT: OK" . PHP_EOL;
                    exit(0);
                }

                echo "RESULT: FAILED" . PHP_EOL;
                echo "Failed checks: " .
                $failed .
                PHP_EOL;

                exit(1);

            /*
             * =================================================
             * RESTORE
             * =================================================
             */

            case 'restore':

                echo PHP_EOL;
                echo "=============================================" . PHP_EOL;
                echo " OpenCart 3 Error Handler" . PHP_EOL;
                echo " Restore Backup" . PHP_EOL;
                echo "=============================================" . PHP_EOL;

                echo PHP_EOL;

                echo "WARNING: Original files will be restored."
                . PHP_EOL;

                echo PHP_EOL;

                echo "Continue? [y/N]: ";

                $answer = trim(fgets(STDIN));

                if (strtolower($answer) !== 'y' && strtolower($answer) !== 'yes') {
                    echo "Cancelled." . PHP_EOL;
                    exit(0);
                }

                echo PHP_EOL;

                $restored = $installer->restore();

                if (count($restored) === 0) {

                    echo "No backup files found." . PHP_EOL;
                } else {

                    foreach ($restored as $file) {
                        echo "[OK] " . $file . PHP_EOL;
                    }
                }

                echo PHP_EOL;
                echo "Restore completed." . PHP_EOL;

                exit(0);

            /*
             * =================================================
             * DELETE
             * =================================================
             */

            case 'delete':

                echo PHP_EOL;
                echo "=============================================" . PHP_EOL;
                echo " Delete Installer" . PHP_EOL;
                echo "=============================================" . PHP_EOL;

                echo PHP_EOL;

                echo "WARNING: install.php will be permanently deleted." . PHP_EOL;

                echo PHP_EOL;

                echo "Continue? [y/N]: ";

                $answer = trim(fgets(STDIN));

                if (strtolower($answer) !== 'y' && strtolower($answer) !== 'yes') {
                    echo "Cancelled." . PHP_EOL;
                    exit(0);
                }

                $installer->deleteInstaller();

                echo "Installer deleted successfully." . PHP_EOL;

                exit(0);

            /*
             * =================================================
             * HELP
             * =================================================
             */

            case 'help':
            case '--help':
            case '-h':

                echo PHP_EOL;

                echo "OpenCart 3 Error Handler Installer"
                . PHP_EOL;

                echo PHP_EOL;

                echo "Usage:" . PHP_EOL;

                echo "  php install.php" . PHP_EOL;

                echo "  php install.php all [path]" . PHP_EOL;

                echo "  php install.php install [path]" . PHP_EOL;

                echo "  php install.php check [path]" . PHP_EOL;

                echo "  php install.php restore [path]" . PHP_EOL;

                echo "  php install.php delete [path]" . PHP_EOL;

                echo PHP_EOL;

                echo "Commands:" . PHP_EOL;

                echo "  all       Full installation and verification" . PHP_EOL;

                echo "  install   Alias for all" . PHP_EOL;

                echo "  check     Check installation" . PHP_EOL;

                echo "  restore   Restore original backup" . PHP_EOL;

                echo "  delete    Delete installer" . PHP_EOL;

                echo PHP_EOL;

                echo "Examples:" . PHP_EOL;

                echo "  php install.php" . PHP_EOL;

                echo "  php install.php all" . PHP_EOL;

                echo "  php install.php all /web/sites/multibank_credit" . PHP_EOL;

                echo "  php install.php check" . PHP_EOL;

                echo "  php install.php restore" . PHP_EOL;

                echo "  php install.php delete" . PHP_EOL;

                echo PHP_EOL;

                exit(0);

            default:

                echo "Unknown command: " . $command . PHP_EOL;

                echo "Run:" . PHP_EOL;

                echo "  php install.php --help" . PHP_EOL;

                exit(1);
        }
    } catch (Exception $e) {

        echo PHP_EOL;
        echo "ERROR:" . PHP_EOL;
        echo $e->getMessage() . PHP_EOL;
        echo PHP_EOL;

        exit(1);
    }
}


/*
 * ============================================================
 * WEB INTERFACE
 * ============================================================
 */

function h($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function webHeader() {
    echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width, initial-scale=1">

<title>OpenCart Error Handler Installer</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 30px;
    background: #f4f5f7;
    color: #222;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        Arial,
        sans-serif;
}

.container {
    max-width: 900px;
    margin: 0 auto;
}

.header,
.card {
    background: #fff;
    border-radius: 10px;
    padding: 25px;
    margin-bottom: 20px;
    box-shadow: 0 2px 12px rgba(0,0,0,.08);
}

h1 {
    margin: 0 0 8px;
}

h2 {
    margin-top: 0;
}

.subtitle {
    color: #666;
}

.actions {
    display: grid;
    grid-template-columns:
        repeat(auto-fit, minmax(190px, 1fr));
    gap: 12px;
}

button {
    width: 100%;
    padding: 13px 15px;
    border: 0;
    border-radius: 7px;
    cursor: pointer;
    font-size: 15px;
    background: #222;
    color: #fff;
}

button:hover {
    opacity: .9;
}

button.warning {
    background: #8a5a00;
}

button.danger {
    background: #b42318;
}

.status {
    padding: 15px;
    border-radius: 7px;
    margin-bottom: 20px;
}

.status.success {
    background: #edf8f0;
    border-left: 4px solid #16803c;
}

.status.error {
    background: #fff1f0;
    border-left: 4px solid #b42318;
}

.check {
    padding: 12px 15px;
    margin-bottom: 8px;
    border-radius: 6px;
    background: #f5f5f5;
}

.check.ok {
    border-left: 4px solid #16803c;
}

.check.fail {
    border-left: 4px solid #b42318;
}

.small {
    color: #777;
    font-size: 13px;
}

code {
    background: #eee;
    padding: 2px 5px;
    border-radius: 4px;
}
</style>
</head>

<body>

<div class="container">

<div class="header">
    <h1>OpenCart 3 Error Handler</h1>
    <div class="subtitle">
        Installer and diagnostic interface
    </div>
</div>';
}

function webFooter() {
    echo '
<div class="card">
    <strong>Security:</strong>
    delete <code>install.php</code>
    after installation.
</div>

</div>
</body>
</html>';
}

function renderChecks($result) {
    echo '<div class="card">';

    echo '<h2>System Check</h2>';

    foreach ($result['checks'] as $check) {
        $class = $check['success'] ? 'ok' : 'fail';
        $icon = $check['success'] ? '&#10003;' : '&#10007;';
        echo '<div class="check ' . $class . '">';
        echo '<strong>' . $icon . ' ' . h($check['name']) . '</strong>';
        echo '<br>';
        echo '<span class="small">' . h($check['message']) . '</span>';
        echo '</div>';
    }

    echo '</div>';
}

try {

    $installer = new ErrorHandlerInstaller(dirname(__FILE__));

    webHeader();

    $message = null;
    $error = null;

    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        try {

            switch ($action) {

                case 'install':

                    $result = $installer->install();

                    if ($result['success']) {
                        $message = 'Installation completed successfully.';
                    } else {
                        $error = 'Installation completed with failed checks.';
                    }

                    break;

                case 'check':

                    $result = $installer->check();

                    break;

                case 'restore':

                    $restored = $installer->restore();

                    $message = 'Backup restored: ' .
                            count($restored) .
                            ' file(s).';

                    break;

                case 'delete':

                    $installer->deleteInstaller();

                    echo '<div class="status success">
                        Installer deleted successfully.
                    </div>

                    <div class="card">
                        You can now close this page.
                    </div>';

                    webFooter();

                    exit;

                default:

                    $error = 'Unknown action.';
            }
        } catch (Exception $e) {

            $error = $e->getMessage();
        }
    }

    if ($message !== null) {
        echo '<div class="status success">' . nl2br(h($message)) . '</div>';
    }

    if ($error !== null) {
        echo '<div class="status error">' . '<strong>Error:</strong><br>' . nl2br(h($error)) . '</div>';
    }

    echo '<div class="card">';

    echo '<h2>Actions</h2>';

    echo '<div class="actions">';

    echo '
    <form method="post"
          onsubmit="return confirm(\'Install or update ErrorHandler?\');">

        <input type="hidden"
               name="action"
               value="install">

        <button type="submit">
            Install / Update
        </button>

    </form>';

    echo '
    <form method="post">

        <input type="hidden"
               name="action"
               value="check">

        <button type="submit">
            Check Installation
        </button>

    </form>';

    echo '
    <form method="post"
          onsubmit="return confirm(\'Restore original backup?\');">

        <input type="hidden"
               name="action"
               value="restore">

        <button type="submit"
                class="warning">
            Restore Backup
        </button>

    </form>';

    echo '
    <form method="post"
          onsubmit="return confirm(\'Delete install.php permanently?\');">

        <input type="hidden"
               name="action"
               value="delete">

        <button type="submit"
                class="danger">
            Delete Installer
        </button>

    </form>';

    echo '</div>';
    echo '</div>';

    renderChecks(
            $installer->check()
    );

    echo '<div class="card">';

    echo '<h2>OpenCart Root</h2>';

    echo '<code>' .
    h($installer->getRoot()) .
    '</code>';

    echo '</div>';

    webFooter();
} catch (Exception $e) {

    webHeader();

    echo '<div class="status error">';

    echo '<strong>Error:</strong><br>';

    echo nl2br(
            h($e->getMessage())
    );

    echo '</div>';

    webFooter();
}