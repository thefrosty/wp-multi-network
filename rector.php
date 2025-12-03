<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Class_\InlineConstructorDefaultToPropertyRector;
use Rector\Config\RectorConfig;
use Rector\Php54\Rector\Array_\LongArrayToShortArrayRector;
use Rector\Php81\Rector\Array_\FirstClassCallableRector;
use Rector\Set\ValueObject\LevelSetList;

return RectorConfig::configure()
	->withAutoloadPaths(array(
		__DIR__ . 'vendor/php-stubs/wordpress-stubs/wordpress-stubs.php',
	))
	->withRootFiles()
	->withPaths(array(
		__DIR__ . '/wp-multi-network',
	))
	->withSkip(array(
		LongArrayToShortArrayRector::class,
		// This should stop Rector changing callable arrays to $this->function in WP's add_*.
		FirstClassCallableRector::class,
	))
	->withRules(array(
		InlineConstructorDefaultToPropertyRector::class,
	))
	->withSets(array(
		LevelSetList::UP_TO_PHP_82,
	));
