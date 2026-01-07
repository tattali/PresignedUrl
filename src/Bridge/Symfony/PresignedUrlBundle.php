<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Bridge\Symfony;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Tattali\PresignedUrl\Bridge\Symfony\DependencyInjection\PresignedUrlExtension;

final class PresignedUrlBundle extends AbstractBundle
{
    public function getContainerExtension(): PresignedUrlExtension
    {
        return new PresignedUrlExtension();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $this->getContainerExtension()->loadFromConfig($config, $container, $builder);
    }
}
