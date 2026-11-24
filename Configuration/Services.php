<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use WorldDirect\Healthcheck\Probe\ProbeInterface;

/**
 * Registers the healthcheck probes only if the optional worlddirect/healthcheck package is installed.
 *
 * The probes are public, so GeneralUtility::makeInstance() resolves them from the container, and not shared,
 * because every healthcheck run needs a fresh probe result.
 */
return static function (ContainerConfigurator $configurator): void {
    if (!interface_exists(ProbeInterface::class)) {
        return;
    }

    $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->load('WorldDirect\\SolrContentApi\\Probe\\', __DIR__ . '/../Classes/Probe/*Probe.php')
        ->public()
        ->share(false);
};
