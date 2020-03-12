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
use Vanilla\KnowledgePorter\Main;
use Vanilla\KnowledgePorter\Sources\AbstractSource;

/**
 * Class ImportCommand
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
     */
    public function run(): void {
        $this->parseArgs();

        $source = $this->createSource();
        $this->logger->info("Running import command from {source} to {destination}.", [
            'source' => get_class($source),
            'destination' => get_class($source->getDestination()),
        ]);

        $source->import();
    }

    /**
     * @throws \Exception When configuration not found or wrong format.
     */
    private function parseArgs() {
        $configPath = $this->args->getOpt('config');
        if (!file_exists($configPath)) {
            throw new \Exception("Config file not found: $configPath", 404);
        }

        $config = json_decode(file_get_contents($configPath), true);
        if (!is_array($config) || isset($config[0])) {
            throw new \Exception("The config file is not a valid JSON object.", 400);
        }
        $this->config = $config;
    }

    /**
     * @return AbstractSource
     */
    private function createSource(): AbstractSource {
        $destType = $this->config['destination']['type'] ?? '';
        $destClass = '\\Vanilla\\KnowledgePorter\\Destinations\\'.Main::changeCase($destType).'Destination';
        /** @var AbstractDestination $dest */
        $dest = $this->container->get($destClass);
        $dest->setConfig($this->config['destination']);

        $sourceType = $this->config['source']['type'] ?? '';
        $sourceClass = '\\Vanilla\\KnowledgePorter\\Sources\\'.Main::changeCase($sourceType).'Source';
        /* @var \Vanilla\KnowledgePorter\Sources\AbstractSource $source */
        $source = $this->container->get($sourceClass);
        $source->setParams($this->config['source'] ?? []);
        $source->setDestination($dest);
        $source->setConfig($this->config['source']);
        return $source;
    }
}
