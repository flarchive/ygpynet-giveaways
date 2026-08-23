<?php

namespace ErnestDefoe\Giveaways\Tests;

use Flarum\Extend\ApiResource;
use Flarum\Extend\Console;
use Flarum\Extend\ExtenderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Guard against broken class references in extend.php. Extenders are plain
 * data holders — they can be constructed without booting the Flarum app — but
 * a wrong class-string (e.g. a namespace that doesn't exist) fails silently or
 * fatally at runtime, so we resolve every configured class here.
 */
class ExtensionConfigTest extends TestCase
{
    /** @return ExtenderInterface[] */
    private function extenders(): array
    {
        $extend = require __DIR__ . '/../extend.php';
        $this->assertIsArray($extend);

        return $extend;
    }

    public function test_api_resource_endpoint_class_references_exist(): void
    {
        foreach ($this->extenders() as $extender) {
            if (! $extender instanceof ApiResource) {
                continue;
            }

            $prop = new \ReflectionProperty($extender, 'endpoint');
            $prop->setAccessible(true);

            foreach (array_keys($prop->getValue($extender)) as $key) {
                if (! is_string($key) || ! str_contains($key, '\\')) {
                    continue;
                }
                $this->assertTrue(
                    class_exists($key) || interface_exists($key) || trait_exists($key),
                    "extend.php references endpoint class '{$key}', which does not exist."
                );
            }
        }
    }

    public function test_console_command_classes_exist(): void
    {
        foreach ($this->extenders() as $extender) {
            if (! $extender instanceof Console) {
                continue;
            }

            $prop = new \ReflectionProperty($extender, 'addCommands');
            $prop->setAccessible(true);

            foreach ($prop->getValue($extender) as $command) {
                $this->assertTrue(
                    is_a($command, \Symfony\Component\Console\Command\Command::class, true),
                    "extend.php registers console command '{$command}', which is not a Command subclass."
                );
            }
        }
    }

    public function test_every_extender_is_a_valid_extender(): void
    {
        foreach ($this->extenders() as $extender) {
            $this->assertInstanceOf(ExtenderInterface::class, $extender);
        }
    }
}
