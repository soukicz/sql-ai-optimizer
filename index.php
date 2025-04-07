<?php
use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\LLMChainClient;
use Soukicz\Llm\MarkdownFormatter;
use Soukicz\SqlAiOptimizer\LLMFileLogger;
use Soukicz\SqlAiOptimizer\StateDatabase;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

require_once __DIR__ . '/vendor/autoload_runtime.php';

set_time_limit(0);

class Kernel extends BaseKernel {
    use MicroKernelTrait;

    protected function configureContainer(ContainerConfigurator $container): void {
        // PHP equivalent of config/packages/framework.yaml
        $container->extension('framework', [
            'secret' => 'S0ME_SECRET',
        ]);

        // Load environment variables from .env file
        $dotenv = new \Symfony\Component\Dotenv\Dotenv();
        $dotenv->loadEnv(__DIR__ . '/.env');

        $container->services()
        ->load('Soukicz\\SqlAiOptimizer\\', __DIR__ . '/src/*')
        ->autowire()
        ->autoconfigure();

        $container->services()
            ->set(FileCache::class)
            ->arg('$cacheDir', __DIR__ . '/var/cache')
            ->autowire()
            ->autoconfigure();

        $container->services()
            ->set(\Symfony\Component\Cache\Adapter\FilesystemAdapter::class)
            ->arg('$namespace', '')
            ->arg('$defaultLifetime', 0)
            ->arg('$directory', __DIR__ . '/var/cache')
            ->autowire()
            ->autoconfigure();

        $container->services()
            ->set(AnthropicClient::class)
            ->arg('$apiKey', '%env(ANTHROPIC_API_KEY)%')
            ->arg('$cache', new Reference(FileCache::class))
            ->autowire()
            ->autoconfigure();

        $container->services()
            ->set(MarkdownFormatter::class)
            ->autowire()
            ->autoconfigure();

        $container->services()
            ->set(LLMFileLogger::class)
            ->arg('$logPath', __DIR__ . '/var/log/llm.md')
            ->arg('$formatter', new Reference(MarkdownFormatter::class))
            ->autowire()
            ->autoconfigure();

        $container->services()
            ->set(LLMChainClient::class)
            ->arg('$logger', new Reference(LLMFileLogger::class))
            ->autowire()
            ->autoconfigure();

        $container->services()
            ->set(StateDatabase::class)
            ->arg('$databasePath', __DIR__ . '/state.sqlite')
            ->autowire()
            ->autoconfigure();

        // Register Twig
        $container->services()
            ->set('twig.loader', \Twig\Loader\FilesystemLoader::class)
            ->arg('$paths', [__DIR__ . '/templates'])
            ->autowire()
            ->autoconfigure();

        $container->services()
            ->set(\Twig\Environment::class)
            ->arg('$loader', new Reference('twig.loader'))
            ->arg('$options', [
                'cache' => __DIR__ . '/var/cache/twig',
                'debug' => '%env(bool:APP_DEBUG)%',
            ])
            ->autowire()
            ->autoconfigure();

        // Register controllers
        $container->services()
            ->load('Soukicz\\SqlAiOptimizer\\Controller\\', __DIR__ . '/src/Controller/')
            ->tag('controller.service_arguments')
            ->autowire()
            ->autoconfigure();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void {
        $routes->import(__DIR__ . '/src/Controller/', 'attribute');
    }
}

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
