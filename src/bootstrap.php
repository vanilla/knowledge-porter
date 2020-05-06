<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */
try {
    error_reporting(E_ALL | ~E_NOTICE | ~E_USER_NOTICE); //E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR);
    ini_set('display_errors', 'on');
    ini_set('track_errors', 1);

    define('ROOT_PATH', dirname(__DIR__));

    date_default_timezone_set('UTC');

    $paths = [
        __DIR__.'/../vendor/autoload.php', // locally
        __DIR__.'/../../../autoload.php' // dependency
    ];
    foreach ($paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            break;
        }
    }

    $main = new \Vanilla\KnowledgePorter\Main();
    $result = $main->run($argv);
} catch (Throwable $t) {
    $exitCode = $t->getCode() === 0 ? 1 : $t->getCode();
    exit($exitCode);
}

exit ($result);
