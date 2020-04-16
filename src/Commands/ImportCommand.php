<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Commands;

use Garden\Cli\Args;
use Psr\Container\ContainerInterface;
use Vanilla\KnowledgePorter\Destinations\AbstractDestination;
use Vanilla\KnowledgePorter\Destinations\VanillaDestination;
use Vanilla\KnowledgePorter\Main;
use Vanilla\KnowledgePorter\Sources\AbstractSource;
use Exception;

/**
 * Class ImportCommand
 *
 * @package Vanilla\KnowledgePorter\Commands
 */
class ImportCommand extends AbstractCommand {
    /**
     * @var Args
     */
    private $args;
    /**
     * @var ContainerInterface
     */
    private $container;

    /** @var array $config */
    private $config;

    /**
     * ImportCommand constructor.
     *
     * @param Args $args
     * @param ContainerInterface $container
     */
    public function __construct(Args $args, ContainerInterface $container) {
        $this->args = $args;
        $this->container = $container;
    }

    /**
     * @inheritdoc
     * @throws Exception
     */
    public function run(): void {
        $this->parseArgs();
        $source = $this->createSource();

        $this->logger->info("Source: {domain} [`{type}`]", [
            'type' => get_class($source),
            'domain' => $this->config['source']['domain'],
        ]);

        $this->logger->info("Target: {domain} [`{type}`]", [
            'type' => get_class($source->getDestination()),
            'domain' => $this->config['destination']['domain'],
        ]);

        $source->import();
    }

    /**
     * @throws Exception When configuration not found or wrong format.
     */
    private function parseArgs() {
        // Configuration can be a configuration file or a reference to an Environmental Variable
        $configArg = $this->args->getOpt('config');

        if (strpos($configArg, 'ENV:') === 0) {
            $this->logger->info("Getting configuration from Environmental Variable");
            $tokens = explode(":", $configArg);
            if (count($tokens) !== 2) {
                throw new Exception("Malformed configuration from Environmental Variable. Use `ENV:VAR_NAME`", 1);
            }
            $this->config = json_decode(trim(getenv($tokens[1])), true);
        } else {
            $this->logger->info("Getting configuration from File");
            if (!file_exists($configArg)) {
                throw new Exception("File not found: `$configArg``", 1);
            }

            $this->config = json_decode(file_get_contents($configArg), true);
        }
        if (!is_array($this->config) || empty($this->config)) {
            throw new Exception("The configuration is not a valid JSON object.", 1);
        }
    }

    /**
     * @return AbstractSource
     */
    private function createSource(): AbstractSource {
        $destType = $this->config['destination']['type'] ?? '';
        $destClass = '\\Vanilla\\KnowledgePorter\\Destinations\\'.Main::changeCase($destType).'Destination';
        $sourceType = $this->config['source']['type'] ?? '';
        $sourceClass = '\\Vanilla\\KnowledgePorter\\Sources\\'.Main::changeCase($sourceType).'Source';
        /* @var AbstractSource $source */
        $source = $this->container->get($sourceClass);
        /** @var AbstractDestination $dest */
        $dest = $this->container->get($destClass);

        $sourceConfig = $this->config['source'];
        $destConfig = $this->config['destination'];

        $syncFrom = $sourceConfig['syncFrom'] ?? null;
        $destConfig['update'] = ($syncFrom) ? VanillaDestination::UPDATE_MODE_ON_CHANGE : $this->config['destination']['update'];
        $dest->setConfig($destConfig);


        $source->setDestination($dest);
        $sourceConfig['targetDomain'] = $sourceConfig['targetDomain'] ?? $this->config['destination']['domain'];
        $source->setConfig($sourceConfig);

        return $source;
    }
}
