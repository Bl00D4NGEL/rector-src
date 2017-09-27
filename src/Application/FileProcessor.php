<?php declare(strict_types=1);

namespace Rector\Application;

use Rector\NodeTraverser\MainNodeTraverser;
use Rector\NodeTraverserQueue\NodeTraverserQueue;
use Rector\Printer\FormatPerservingPrinter;
use SplFileInfo;

final class FileProcessor
{
    /**
     * @var FormatPerservingPrinter
     */
    private $formatPerservingPrinter;

    /**
     * @var NodeTraverserQueue
     */
    private $nodeTraverserQueue;

    /**
     * @var MainNodeTraverser
     */
    private $mainNodeTraverser;

    public function __construct(
        FormatPerservingPrinter $codeStyledPrinter,
        NodeTraverserQueue $nodeTraverserQueue,
        MainNodeTraverser $mainNodeTraverser
    ) {
        $this->formatPerservingPrinter = $codeStyledPrinter;
        $this->nodeTraverserQueue = $nodeTraverserQueue;
        $this->mainNodeTraverser = $mainNodeTraverser;
    }

    /**
     * @param SplFileInfo[] $files
     */
    public function processFiles(array $files): void
    {
        foreach ($files as $file) {
            $this->processFile($file);
        }
    }

    /**
     * @param string[] $rectorClasses
     */
    public function processFileWithRectorsToString(SplFileInfo $file, array $rectorClasses): string
    {
        $this->mainNodeTraverser->enableOnlyRectorClasses($rectorClasses);

        return $this->processFileToString($file);
    }

    private function processFile(SplFileInfo $fileInfo): void
    {
        [$newStmts, $oldStmts, $oldTokens] = $this->nodeTraverserQueue->processFileInfo($fileInfo);

        $this->formatPerservingPrinter->printToFile($fileInfo, $newStmts, $oldStmts, $oldTokens);
    }

    /**
     * See https://github.com/nikic/PHP-Parser/issues/344#issuecomment-298162516.
     */
    private function processFileToString(SplFileInfo $fileInfo): string
    {
        [$newStmts, $oldStmts, $oldTokens] = $this->nodeTraverserQueue->processFileInfo($fileInfo);

        return $this->formatPerservingPrinter->printToString($newStmts, $oldStmts, $oldTokens);
    }
}
