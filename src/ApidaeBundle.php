<?php

namespace ApidaeTourisme\ApidaeBundle ;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

class ApidaeBundle extends AbstractBundle
{
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->extension('doctrine', [
            'orm' => [
                'identity_generation_preferences' => [
                    PostgreSQLPlatform::class => 'SEQUENCE',
                    'Doctrine\DBAL\Platforms\PostgreSqlPlatform' => 'SEQUENCE',
                ],
            ],
        ]);
    }

    public function loadExtension(array $config, ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void
    {
        // load an XML, PHP or Yaml file
        $containerConfigurator->import('../resources/services.yaml');
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
