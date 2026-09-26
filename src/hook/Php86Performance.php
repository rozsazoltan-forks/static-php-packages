<?php

declare(strict_types=1);

namespace staticphp\hook;

use Package\Target\php as PhpTarget;
use StaticPHP\Attribute\Package\BeforeStage;
use StaticPHP\Attribute\PatchDescription;
use StaticPHP\Package\TargetPackage;
use StaticPHP\Util\SourcePatcher;

class Php86Performance
{
    #[BeforeStage('php', [PhpTarget::class, 'buildconfForUnix'], 'php')]
    #[PatchDescription('Apply php-src performance PRs #22729, #22728, #22722 and #23604 to PHP 8.6')]
    public function patchBeforeBuildconf(TargetPackage $package): bool
    {
        if (!str_starts_with(PhpTarget::getPHPVersion($package->getSourceDir()), '8.6.')) {
            return false;
        }

        return SourcePatcher::patchFile(dirname(__DIR__, 2) . '/config/patches/php86-performance-prs.patch', $package->getSourceDir());
    }
}
