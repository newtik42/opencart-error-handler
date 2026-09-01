<?php

/* * ******************************************************* */
/* 	@copyright	NewTik Ltd. (https://newtik-opencart.com/).  */
/* 	@support	https://newtik-opencart.com/			     */
/* 	@license	https://opensource.org/licenses/GPL-3.0      */
/* * ******************************************************* */

/**
 * OpenCart 3 Error Handler Installer
 *
 * CLI:
 *   php install.php
 *   php install.php /path/to/opencart
 *
 * Browser:
 *   https://example.com/install.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

class ErrorHandlerInstaller
{
    private $root;

    private $githubUrl =
        'https://raw.githubusercontent.com/' .
        'newtik42/opencart-error-handler/main/src/ErrorHandler.php';

    private $libraryFile;
    private $startupFile;
    private $frameworkFile;
    private $backupDir;

    public function __construct($root = null)
    {
        if ($root === null) {
            $root = dirname(__FILE__);
        }

        $realRoot = realpath($root);

        if ($realRoot === false) {
            throw new RuntimeException(
                'OpenCart directory does not exist: ' . $root
            );
        }

        $this->root = rtrim(
            $realRoot,
            DIRECTORY_SEPARATOR
        );

        $this->libraryFile =
            $this->root .
            DIRECTORY_SEPARATOR .
            'system' .
            DIRECTORY_SEPARATOR .
            'library' .
            DIRECTORY_SEPARATOR .
            'error_handler.php';

        $this->startupFile =
            $this->root .
            DIRECTORY_SEPARATOR .
            'system' .
            DIRECTORY_SEPARATOR .
            'startup.php';

        $this->frameworkFile =
            $this->root .
            DIRECTORY_SEPARATOR .
            'system' .
            DIRECTORY_SEPARATOR .
            'framework.php';

        $this->backupDir =
            $this->root .
            DIRECTORY_SEPARATOR .
            '.error-handler-backup';
    }

    /*
     * ---------------------------------------------------------
     * INSTALL
     * ---------------------------------------------------------
     */

    public function install()
    {
        $this->checkOpenCart();

        $this->createBackupDir();

        $this->backupFile($this->startupFile);
        $this->backupFile($this->frameworkFile);
        $this->backupFile($this->libraryFile);

        $this->downloadErrorHandler();

        $this->installStartup();

        $this->installFramework();

        return $this->check();
    }

    /*
     * ---------------------------------------------------------
     * CHECK
     * ---------------------------------------------------------
     */

    public function check()
    {
        $result = array(
            'success' => true,
            'checks' => array()
        );

        $this->addCheck(
            $result,
            'PHP version',
            version_compare(PHP_VERSION, '7.0.0', '>='),
            PHP_VERSION
        );

        $this->addCheck(
            $result,
            'OpenCart system directory',
            is_dir($this->root . '/system'),
            $this->root . '/system'
        );

        $this->addCheck(
            $result,
            'system/startup.php',
            is_file($this->startupFile),
            $this->startupFile
        );

        $this->addCheck(
            $result,
            'system/framework.php',
            is_file($this->frameworkFile),
            $this->frameworkFile
        );

        $this->addCheck(
            $result,
            'ErrorHandler.php',
            is_file($this->libraryFile),
            $this->libraryFile
        );

        if (is_file($this->libraryFile)) {
            $syntax = $this->checkSyntax(
                $this->libraryFile
            );

            $this->addCheck(
                $result,
                'ErrorHandler syntax',
                $syntax['success'],
                $syntax['message']
            );
        }

        if (is_file($this->startupFile)) {
            $content = file_get_contents(
                $this->startupFile
            );

            $installed =
                strpos(
                    $content,
                    'library/error_handler.php'
                ) !== false
                &&
                strpos(
                    $content,
                    '$errorHandler->register()'
                ) !== false;

            $this->addCheck(
                $result,
                'startup.php integration',
                $installed,
                $installed
                    ? 'ErrorHandler registered'
                    : 'Integration not found'
            );
        }

        if (is_file($this->frameworkFile)) {
            $content = file_get_contents(
                $this->frameworkFile
            );

            $installed =
                strpos(
                    $content,
                    '$errorHandler->setLog($log)'
                ) !== false;

            $this->addCheck(
                $result,
                'framework.php integration',
                $installed,
                $installed
                    ? 'OpenCart logger connected'
                    : 'setLog() integration not found'
            );
        }

        if (is_dir($this->backupDir)) {
            $this->addCheck(
                $result,
                'Backup directory',
                true,
                $this->backupDir
            );
        }

        return $result;
    }

    private function addCheck(
        &$result,
        $name,
        $success,
        $message
    ) {
        $result['checks'][] = array(
            'name' => $name,
            'success' => (bool) $success,
            'message' => $message
        );

        if (!$success) {
            $result['success'] = false;
        }
    }

    /*
     * ---------------------------------------------------------
     * RESTORE
     * ---------------------------------------------------------
     */

    public function restore()
    {
        if (!is_dir($this->backupDir)) {
            throw new RuntimeException(
                'Backup directory does not exist.'
            );
        }

        $restored = array();

        $files = array(
            'system__startup.php' =>
                $this->startupFile,

            'system__framework.php' =>
                $this->frameworkFile,

            'system__library__error_handler.php' =>
                $this->libraryFile
        );

        foreach ($files as $backup => $target) {
            $source =
                $this->backupDir .
                DIRECTORY_SEPARATOR .
                $backup;

            if (!is_file($source)) {
                continue;
            }

            if (!copy($source, $target)) {
                throw new RuntimeException(
                    'Unable to restore: ' . $target
                );
            }

            $restored[] = $target;
        }

        return $restored;
    }

    /*
     * ---------------------------------------------------------
     * DELETE INSTALLER
     * ---------------------------------------------------------
     */

    public function deleteInstaller()
    {
        $installer = __FILE__;

        if (!is_writable($installer)) {
            throw new RuntimeException(
                'Installer is not writable.'
            );
        }

        if (!unlink($installer)) {
            throw new RuntimeException(
                'Unable to delete installer.'
            );
        }

        return true;
    }

    /*
     * ---------------------------------------------------------
     * OPENCART CHECK
     * ---------------------------------------------------------
     */

    private function checkOpenCart()
    {
        if (!is_dir($this->root . '/system')) {
            throw new RuntimeException(
                'OpenCart system directory not found.'
            );
        }

        if (!is_file($this->startupFile)) {
            throw new RuntimeException(
                'system/startup.php not found.'
            );
        }

        if (!is_file($this->frameworkFile)) {
            throw new RuntimeException(
                'system/framework.php not found.'
            );
        }

        if (!is_dir(
            $this->root .
            DIRECTORY_SEPARATOR .
            'system' .
            DIRECTORY_SEPARATOR .
            'library'
        )) {
            throw new RuntimeException(
                'system/library directory not found.'
            );
        }
    }

    /*
     * ---------------------------------------------------------
     * BACKUP
     * ---------------------------------------------------------
     */

    private function createBackupDir()
    {
        if (!is_dir($this->backupDir)) {
            if (!mkdir(
                $this->backupDir,
                0755,
                true
            )) {
                throw new RuntimeException(
                    'Unable to create backup directory.'
                );
            }
        }
    }

    private function backupFile($file)
    {
        if (!is_file($file)) {
            return;
        }

        $relative = str_replace(
            $this->root . DIRECTORY_SEPARATOR,
            '',
            $file
        );

        $backup = $this->backupDir .
            DIRECTORY_SEPARATOR .
            str_replace(
                DIRECTORY_SEPARATOR,
                '__',
                $relative
            );

        /*
         * Never overwrite the first/original backup.
         */
        if (!is_file($backup)) {
            if (!copy($file, $backup)) {
                throw new RuntimeException(
                    'Unable to create backup: ' . $file
                );
            }
        }
    }

    /*
     * ---------------------------------------------------------
     * DOWNLOAD
     * ---------------------------------------------------------
     */

    private function downloadErrorHandler()
    {
        $content = false;

        /*
         * cURL
         */
        if (function_exists('curl_init')) {
            $curl = curl_init();

            curl_setopt(
                $curl,
                CURLOPT_URL,
                $this->githubUrl
            );

            curl_setopt(
                $curl,
                CURLOPT_RETURNTRANSFER,
                true
            );

            curl_setopt(
                $curl,
                CURLOPT_FOLLOWLOCATION,
                true
            );

            curl_setopt(
                $curl,
                CURLOPT_CONNECTTIMEOUT,
                10
            );

            curl_setopt(
                $curl,
                CURLOPT_TIMEOUT,
                30
            );

            curl_setopt(
                $curl,
                CURLOPT_USERAGENT,
                'OpenCart-Error-Handler-Installer'
            );

            $content = curl_exec($curl);

            $httpCode = curl_getinfo(
                $curl,
                CURLINFO_HTTP_CODE
            );

            curl_close($curl);

            if (
                $content === false ||
                $httpCode !== 200
            ) {
                $content = false;
            }
        }

        /*
         * file_get_contents fallback
         */
        if ($content === false) {
            $context = stream_context_create(
                array(
                    'http' => array(
                        'method' => 'GET',
                        'timeout' => 30,
                        'header' =>
                            "User-Agent: " .
                            "OpenCart-Error-Handler-Installer\r\n"
                    )
                )
            );

            $content = @file_get_contents(
                $this->githubUrl,
                false,
                $context
            );
        }

        if ($content === false) {
            throw new RuntimeException(
                'Unable to download ErrorHandler.php from GitHub.'
            );
        }

        if (trim($content) === '') {
            throw new RuntimeException(
                'Downloaded ErrorHandler.php is empty.'
            );
        }

        /*
         * Basic validation.
         */
        if (
            strpos($content, '<?php') === false ||
            strpos($content, 'class ErrorHandler') === false
        ) {
            throw new RuntimeException(
                'Downloaded file is not a valid ErrorHandler.php.'
            );
        }

        if (
            file_put_contents(
                $this->libraryFile,
                $content,
                LOCK_EX
            ) === false
        ) {
            throw new RuntimeException(
                'Unable to write ErrorHandler.php.'
            );
        }
    }

    /*
     * ---------------------------------------------------------
     * STARTUP
     * ---------------------------------------------------------
     */

    private function installStartup()
    {
        $content = file_get_contents(
            $this->startupFile
        );

        if ($content === false) {
            throw new RuntimeException(
                'Unable to read startup.php.'
            );
        }

        if (
            strpos(
                $content,
                'library/error_handler.php'
            ) !== false
        ) {
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

        $position = strpos(
            $content,
            '<?php'
        );

        if ($position === false) {
            throw new RuntimeException(
                'PHP opening tag not found in startup.php.'
            );
        }

        $position += 5;

        $content =
            substr($content, 0, $position) .
            $code .
            substr($content, $position);

        if (
            file_put_contents(
                $this->startupFile,
                $content,
                LOCK_EX
            ) === false
        ) {
            throw new RuntimeException(
                'Unable to write startup.php.'
            );
        }
    }

    /*
     * ---------------------------------------------------------
     * FRAMEWORK
     * ---------------------------------------------------------
     */

    private function installFramework()
    {
        $content = file_get_contents(
            $this->frameworkFile
        );

        if ($content === false) {
            throw new RuntimeException(
                'Unable to read framework.php.'
            );
        }

        if (
            strpos(
                $content,
                '$errorHandler->setLog($log)'
            ) !== false
        ) {
            return;
        }

        $pattern =
            '/(\$log\s*=\s*new\s+Log\s*\([^;]+;\s*)/';

        $replacement =
            '$1' .
            PHP_EOL .
            '$errorHandler->setLog($log);' .
            PHP_EOL;

        $newContent = preg_replace(
            $pattern,
            $replacement,
            $content,
            1,
            $count
        );

        if ($count === 0) {
            throw new RuntimeException(
                'Unable to find "$log = new Log(...)" in framework.php.'
            );
        }

        if (
            file_put_contents(
                $this->frameworkFile,
                $newContent,
                LOCK_EX
            ) === false
        ) {
            throw new RuntimeException(
                'Unable to write framework.php.'
            );
        }
    }

    /*
     * ---------------------------------------------------------
     * SYNTAX CHECK
     * ---------------------------------------------------------
     */

    private function checkSyntax($file)
    {
        $output = array();
        $returnCode = 0;

        exec(
            'php -l ' .
            escapeshellarg($file) .
            ' 2>&1',
            $output,
            $returnCode
        );

        return array(
            'success' => $returnCode === 0,
            'message' => implode(
                ' ',
                $output
            )
        );
    }
}


/*
 * ============================================================
 * WEB INTERFACE
 * ============================================================
 */

function isWebRequest()
{
    return php_sapi_name() !== 'cli';
}

function htmlEscape($value)
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function renderHeader()
{
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

.header {
    background: #fff;
    padding: 25px;
    border-radius: 10px;
    margin-bottom: 20px;
    box-shadow: 0 2px 12px rgba(0,0,0,.08);
}

h1 {
    margin: 0 0 10px;
}

.subtitle {
    color: #666;
}

.card {
    background: #fff;
    padding: 25px;
    border-radius: 10px;
    margin-bottom: 20px;
    box-shadow: 0 2px 12px rgba(0,0,0,.08);
}

.actions {
    display: grid;
    grid-template-columns:
        repeat(auto-fit, minmax(200px, 1fr));
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

button.danger {
    background: #b42318;
}

button.warning {
    background: #8a5a00;
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

.status {
    padding: 15px;
    border-radius: 7px;
    margin-bottom: 20px;
    background: #f5f5f5;
}

.status.success {
    border-left: 4px solid #16803c;
}

.status.error {
    border-left: 4px solid #b42318;
}

code {
    background: #eee;
    padding: 2px 5px;
    border-radius: 4px;
}

.small {
    color: #777;
    font-size: 13px;
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

function renderFooter()
{
    echo '
<div class="card">
<p class="small">
Security: delete <code>install.php</code>
after installation.
</p>
</div>

</div>
</body>
</html>';
}

function renderChecks($result)
{
    echo '<div class="card">';

    echo '<h2>System Check</h2>';

    foreach ($result['checks'] as $check) {
        $class = $check['success']
            ? 'ok'
            : 'fail';

        $icon = $check['success']
            ? '&#10003;'
            : '&#10007;';

        echo '<div class="check ' .
            $class .
            '">';

        echo '<strong>' .
            $icon .
            ' ' .
            htmlEscape($check['name']) .
            '</strong>';

        echo '<br>';

        echo '<span class="small">' .
            htmlEscape(
                (string) $check['message']
            ) .
            '</span>';

        echo '</div>';
    }

    echo '</div>';
}

function renderWebInterface(
    ErrorHandlerInstaller $installer
) {
    renderHeader();

    $message = null;
    $error = null;

    if (
        isset($_POST['action'])
    ) {
        $action = $_POST['action'];

        try {
            switch ($action) {

                case 'install':

                    $installer->install();

                    $message =
                        'Installation completed successfully.';

                    break;

                case 'check':

                    break;

                case 'restore':

                    $restored =
                        $installer->restore();

                    $message =
                        'Backup restored: ' .
                        count($restored) .
                        ' file(s).';

                    break;

                case 'delete':

                    $installer->deleteInstaller();

                    /*
                     * Installer has deleted itself.
                     */
                    echo '<div class="card">
                        <div class="status success">
                        Installer deleted successfully.
                        </div>
                        <p>
                        You can now close this page.
                        </p>
                        </div>';

                    renderFooter();

                    return;
            }

        } catch (Exception $e) {

            $error = $e->getMessage();
        }
    }

    if ($message !== null) {
        echo '<div class="status success">' .
            htmlEscape($message) .
            '</div>';
    }

    if ($error !== null) {
        echo '<div class="status error">' .
            '<strong>Error:</strong><br>' .
            htmlEscape($error) .
            '</div>';
    }

    echo '<div class="card">';

    echo '<h2>Actions</h2>';

    echo '<div class="actions">';

    echo '<form method="post"
        onsubmit="return confirm(
        \'Install or update ErrorHandler?\'
        );">
        <input type="hidden"
               name="action"
               value="install">
        <button type="submit">
            Install / Update
        </button>
    </form>';

    echo '<form method="post">
        <input type="hidden"
               name="action"
               value="check">
        <button type="submit">
            Check Installation
        </button>
    </form>';

    echo '<form method="post"
        onsubmit="return confirm(
        \'Restore original backup?\'
        );">
        <input type="hidden"
               name="action"
               value="restore">
        <button
            type="submit"
            class="warning">
            Restore Backup
        </button>
    </form>';

    echo '<form method="post"
        onsubmit="return confirm(
        \'Delete install.php permanently?\'
        );">
        <input type="hidden"
               name="action"
               value="delete">
        <button
            type="submit"
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

    echo '<h2>Installation Flow</h2>';

    echo '<ol>';

    echo '<li>Download the latest ErrorHandler.php from GitHub.</li>';

    echo '<li>Create backups of OpenCart files.</li>';

    echo '<li>Register ErrorHandler in startup.php.</li>';

    echo '<li>Connect OpenCart $log in framework.php.</li>';

    echo '<li>Run PHP syntax checks.</li>';

    echo '<li>Verify the installation.</li>';

    echo '</ol>';

    echo '</div>';

    renderFooter();
}


/*
 * ============================================================
 * CLI
 * ============================================================
 */

if (!isWebRequest()) {

    $root = isset($argv[1])
        ? $argv[1]
        : dirname(__FILE__);

    try {

        $installer =
            new ErrorHandlerInstaller($root);

        echo PHP_EOL;
        echo "OpenCart 3 Error Handler Installer";
        echo PHP_EOL;
        echo "===================================";
        echo PHP_EOL;

        echo "Root: " . realpath($root);
        echo PHP_EOL;

        $installer->install();

        echo PHP_EOL;
        echo "Installation completed successfully.";
        echo PHP_EOL;

    } catch (Exception $e) {

        echo PHP_EOL;
        echo "ERROR: " .
            $e->getMessage() .
            PHP_EOL;

        exit(1);
    }

    exit;
}


/*
 * ============================================================
 * WEB
 * ============================================================
 */

try {

    $installer =
        new ErrorHandlerInstaller(
            dirname(__FILE__)
        );

    renderWebInterface($installer);

} catch (Exception $e) {

    renderHeader();

    echo '<div class="status error">';

    echo '<strong>Error:</strong><br>';

    echo htmlEscape(
        $e->getMessage()
    );

    echo '</div>';

    renderFooter();
}
