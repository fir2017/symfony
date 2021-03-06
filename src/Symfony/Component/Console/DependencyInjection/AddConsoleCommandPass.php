<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Console\DependencyInjection;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\TypedReference;

/**
 * Registers console commands.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
class AddConsoleCommandPass implements CompilerPassInterface
{
    private $commandLoaderServiceId;
    private $commandTag;

    public function __construct($commandLoaderServiceId = 'console.command_loader', $commandTag = 'console.command')
    {
        $this->commandLoaderServiceId = $commandLoaderServiceId;
        $this->commandTag = $commandTag;
    }

    public function process(ContainerBuilder $container)
    {
        $commandServices = $container->findTaggedServiceIds($this->commandTag, true);
        $lazyCommandMap = array();
        $lazyCommandRefs = array();
        $serviceIds = array();

        foreach ($commandServices as $id => $tags) {
            $definition = $container->getDefinition($id);
            $class = $container->getParameterBag()->resolveValue($definition->getClass());

            if (!$r = $container->getReflectionClass($class)) {
                throw new InvalidArgumentException(sprintf('Class "%s" used for service "%s" cannot be found.', $class, $id));
            }
            if (!$r->isSubclassOf(Command::class)) {
                throw new InvalidArgumentException(sprintf('The service "%s" tagged "%s" must be a subclass of "%s".', $id, $this->commandTag, Command::class));
            }

            $commandId = 'console.command.'.strtolower(str_replace('\\', '_', $class));

            if (!isset($tags[0]['command'])) {
                if (isset($serviceIds[$commandId]) || $container->hasAlias($commandId)) {
                    $commandId = $commandId.'_'.$id;
                }
                if (!$definition->isPublic()) {
                    $container->setAlias($commandId, $id);
                    $id = $commandId;
                }
                $serviceIds[$commandId] = $id;

                continue;
            }

            $serviceIds[$commandId] = false;
            $commandName = $tags[0]['command'];
            unset($tags[0]);
            $lazyCommandMap[$commandName] = $id;
            $lazyCommandRefs[$id] = new TypedReference($id, $class);
            $aliases = array();

            foreach ($tags as $tag) {
                if (isset($tag['command'])) {
                    $aliases[] = $tag['command'];
                    $lazyCommandMap[$tag['command']] = $id;
                }
            }

            $definition->addMethodCall('setName', array($commandName));

            if ($aliases) {
                $definition->addMethodCall('setAliases', array($aliases));
            }
        }

        $container
            ->register($this->commandLoaderServiceId, ContainerCommandLoader::class)
            ->setArguments(array(ServiceLocatorTagPass::register($container, $lazyCommandRefs), $lazyCommandMap));

        $container->setParameter('console.command.ids', $serviceIds);
    }
}
