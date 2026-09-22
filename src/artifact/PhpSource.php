<?php

declare(strict_types=1);

namespace staticphp\artifact;

use StaticPHP\Artifact\Artifact;
use StaticPHP\Artifact\ArtifactDownloader;
use StaticPHP\Artifact\Downloader\DownloadResult;
use StaticPHP\Artifact\Downloader\Type\CheckUpdateResult;
use StaticPHP\Artifact\Downloader\Type\PhpRelease;
use StaticPHP\Attribute\Artifact\CustomSource;
use StaticPHP\Attribute\Artifact\CustomSourceCheckUpdate;
use StaticPHP\Exception\DownloaderException;
use StaticPHP\Exception\ValidationException;
use StaticPHP\Registry\ArtifactLoader;

class PhpSource extends PhpRelease
{
    #[CustomSource('php-src')]
    public function downloadSource(Artifact $artifact, ArtifactDownloader $downloader): DownloadResult
    {
        $config = $artifact->getDownloadConfig('source');
        $result = $this->download($artifact->getName(), $config, $downloader);
        if (!$this->validate($artifact->getName(), $config, $downloader, $result)) {
            throw new ValidationException('Hash validation failed for php-src.');
        }
        $result->verified = true;
        return $result;
    }

    #[CustomSourceCheckUpdate('php-src')]
    public function checkSourceUpdate(ArtifactDownloader $downloader, ?string $old_version): CheckUpdateResult
    {
        $config = ArtifactLoader::getArtifactInstance('php-src')->getDownloadConfig('source');
        return $this->checkUpdate('php-src', $config, $old_version, $downloader);
    }

    protected function resolveRelease(string $name, array $config, ArtifactDownloader $downloader): array
    {
        if (strcasecmp($downloader->getOption('with-php', '8.5'), '8.6.0RC1') === 0) {
            throw new DownloaderException('PHP 8.6.0RC1 has an upstream packaging issue; use beta3 or RC2 and later.');
        }

        $release = parent::resolveRelease($name, $config, $downloader);
        if (strcasecmp($release['version'], '8.6.0RC1') === 0) {
            // RC1 has an upstream packaging issue; stay on beta3 until RC2 is tagged.
            return parent::resolvePrereleaseFromGitTags('8.6.0beta3', $downloader);
        }
        return $release;
    }
}
