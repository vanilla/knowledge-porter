<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter;

use Garden\Cli\Args;
use Garden\Cli\Cli;
use Garden\Cli\StreamLogger;
use Garden\Cli\TaskLogger;
use Garden\Container\Container;
use Garden\Container\Reference;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Cache\Simple\ChainCache;
use Vanilla\KnowledgePorter\Commands\AbstractCommand;
use Vanilla\KnowledgePorter\HttpClients\HttpRateLimitMiddleware;
use Vanilla\KnowledgePorter\HttpClients\VanillaClient;
use Vanilla\KnowledgePorter\HttpClients\RateLimiterMiddleware;
use Vanilla\KnowledgePorter\HttpClients\ZendeskClient;
use Garden\Container\ContainerException;
use Garden\Container\NotFoundException;
use InvalidArgumentException;
use Exception;

/**
 * The main program loop.
 */
final class Main
{
    /**
     * @var Container
     */
    private $container;

    /**
     * @var Cli
     */
    private $cli;

    /**
     * Main constructor.
     *
     * @throws Exception
     */
    public function __construct()
    {
        $this->container = $this->createContainer();
        $this->cli = $this->createCli();
    }

    /**
     * Run the program.
     *
     * @param array $argv Command line arguments.
     * @return int Returns the integer result of the command which should be propagated back to the command line.
     * @throws ContainerException
     * @throws NotFoundException
     * @throws Exception
     */
    public function run(array $argv): int
    {
        $args = $this->cli->parse($argv);

        // Set the args in the container so they can be injected into classes.
        $this->container->setInstance(Args::class, $args);

        try {
            // This is a basic method for each command. You could also create command objects if you would like.
            $className =
                "\\Vanilla\\KnowledgePorter\\Commands\\" .
                self::changeCase($args->getCommand()) .
                "Command";
            if ($this->container->has($className)) {
                /* @var AbstractCommand $command */
                $command = $this->container->get($className);
                $command->run();
            } else {
                throw new InvalidArgumentException(
                    "Cannot find a class for command: " . $args->getCommand(),
                    1
                );
            }

            return 0;
        } catch (Exception $ex) {
            /* @var LoggerInterface $log */
            $log = $this->container->get(LoggerInterface::class);
            $log->error($ex->getMessage());

            return $ex->getCode();
        }
    }

    /**
     * Create and configure the command line interface for the application.
     *
     * @return Cli
     * @throws Exception
     */
    private function createCli(): Cli
    {
        $cli = new Cli();

        $cli->command("import")
            ->description("Import a knowledge base from a source.")
            ->opt("config:c", "The path to the import config.", true)
            ->opt("src-type", "The type of knowledge base being imported.")
            ->opt("dest-type", "The target of the knowledge base import.");

        return $cli;
    }

    /**
     * Create and configure the DI container for the application.
     *
     * @return Container
     */
    private function createContainer(): Container
    {
        $di = new Container();

        $di->setInstance(Container::class, $di)
            ->defaultRule()
            ->setShared(true)
            ->rule(ContainerInterface::class)
            ->setAliasOf(Container::class)
            ->rule(LoggerInterface::class)
            ->setAliasOf(TaskLogger::class)
            ->rule(TaskLogger::class)
            ->setConstructorArgs([
                "logger" => new Reference(StreamLogger::class),
            ])
            ->setShared(true)
            ->rule(LoggerAwareInterface::class)
            ->addCall("setLogger")
            ->rule(TaskLoggerAwareInterface::class)
            ->addCall("setLogger");

        $di->rule(LoggerAwareInterface::class)
            ->addCall("setLogger")
            ->rule(CacheInterface::class)
            ->setClass(ChainCache::class)
            ->setFactory(function (): CacheInterface {
                $fileCache = new FilesystemAdapter(
                    "knowledge-porter",
                    strtotime("12 hours"),
                    "./cache"
                );
                $r = new Psr16Cache($fileCache);

                return $r;
            })
            ->setShared(true)
            ->rule(ZendeskClient::class)
            ->addCall("addMiddleware", [
                new Reference(RateLimiterMiddleware::class),
            ])
            ->addCall("addMiddleware", [
                new Reference(HttpRateLimitMiddleware::class),
            ])
            ->setShared(false)
            ->rule(VanillaClient::class)
            ->addCall("addMiddleware", [
                new Reference(RateLimiterMiddleware::class),
            ])
            ->addCall("addMiddleware", [
                new Reference(HttpRateLimitMiddleware::class),
            ])
            ->setShared(false);

        return $di;
    }

    /**
     * Change a kebab case variable to pascal case.
     *
     * @param string $kebabCase
     * @return string
     */
    public static function changeCase(string $kebabCase): string
    {
        $parts = explode("-", $kebabCase);
        $parts = array_map("ucfirst", $parts);
        $result = implode("", $parts);

        return $result;
    }
}
