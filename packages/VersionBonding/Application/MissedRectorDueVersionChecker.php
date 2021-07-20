<?php

declare(strict_types=1);

namespace Rector\VersionBonding\Application;

use Nette\Utils\Strings;
use PHPStan\Php\PhpVersion;
use Rector\Core\Contract\Rector\RectorInterface;
use Rector\Core\Php\PhpVersionProvider;
use Rector\VersionBonding\Contract\MinPhpVersionInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class MissedRectorDueVersionChecker
{
    public function __construct(
        private PhpVersionProvider $phpVersionProvider,
        private SymfonyStyle $symfonyStyle,
    ) {
    }

    /**
     * @param RectorInterface[] $rectors
     */
    public function check(array $rectors): void
    {
        $minProjectPhpVersion = $this->phpVersionProvider->provide();

        $missedRectors = $this->resolveMissedRectors($rectors, $minProjectPhpVersion);
        if ($missedRectors === []) {
            return;
        }

        $phpVersion = new PhpVersion($minProjectPhpVersion);
        $warningMessage = sprintf(
            'Your project requires min PHP version "%s".%sCouple Rectors require higher PHP version and will not run,%sto avoid breaking your codebase.',
            $phpVersion->getVersionString(),
            PHP_EOL,
            PHP_EOL
        );

        $this->symfonyStyle->warning($warningMessage);

        foreach ($missedRectors as $missedRector) {
            $phpVersion = new PhpVersion($missedRector->provideMinPhpVersion());
            $shortRectorClass = Strings::after($missedRector::class, '\\', -1);
            $rectorMessage = sprintf(' * [%s] %s', $phpVersion->getVersionString(), $shortRectorClass);
            $this->symfonyStyle->writeln($rectorMessage);
        }

        $solutionMessage = sprintf(
            'Do you want to run them? Make "require" > "php" in `composer.json` higher,%sor add "Option::PHP_VERSION_FEATURES" parameter to your `rector.php`.',
            PHP_EOL
        );
        $this->symfonyStyle->note($solutionMessage);
    }

    /**
     * @param RectorInterface[] $rectors
     * @return MinPhpVersionInterface[]
     */
    private function resolveMissedRectors(array $rectors, int $minProjectPhpVersion): array
    {
        $missedRectors = [];
        foreach ($rectors as $rector) {
            if (! $rector instanceof MinPhpVersionInterface) {
                continue;
            }

            // the conditions are met → skip it
            if ($rector->provideMinPhpVersion() <= $minProjectPhpVersion) {
                continue;
            }

            $missedRectors[] = $rector;
        }

        return $missedRectors;
    }
}
