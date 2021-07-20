<?php

declare(strict_types=1);

namespace Rector\Php70\Rector\Assign;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\List_;
use Rector\Core\Rector\AbstractRector;
use Rector\Core\ValueObject\PhpVersionFeature;
use Rector\VersionBonding\Contract\MinPhpVersionInterface;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @changelog http://php.net/manual/en/migration70.incompatible.php#migration70.incompatible.variable-handling.list
 * @see \Rector\Tests\Php70\Rector\Assign\ListSwapArrayOrderRector\ListSwapArrayOrderRectorTest
 */
final class ListSwapArrayOrderRector extends AbstractRector implements MinPhpVersionInterface
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'list() assigns variables in reverse order - relevant in array assign',
            [new CodeSample('list($a[], $a[]) = [1, 2];', 'list($a[], $a[]) = array_reverse([1, 2]);')]
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Assign::class];
    }

    /**
     * @param Assign $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($this->shouldSkipAssign($node)) {
            return null;
        }

        /** @var List_ $list */
        $list = $node->var;

        $printedVariables = [];
        foreach ($list->items as $arrayItem) {
            if (! $arrayItem instanceof ArrayItem) {
                continue;
            }

            if ($arrayItem->value instanceof ArrayDimFetch && $arrayItem->value->dim === null) {
                $printedVariables[] = $this->print($arrayItem->value->var);
            } else {
                return null;
            }
        }

        // relevant only in 1 variable type
        $uniqueVariables = array_unique($printedVariables);
        if (count($uniqueVariables) !== 1) {
            return null;
        }

        // wrap with array_reverse, to reflect reverse assign order in left
        $node->expr = $this->nodeFactory->createFuncCall('array_reverse', [$node->expr]);

        return $node;
    }

    private function shouldSkipAssign(Assign $assign): bool
    {
        if (! $assign->var instanceof List_) {
            return true;
        }

        // already converted
        return $assign->expr instanceof FuncCall && $this->isName($assign->expr, 'array_reverse');
    }

    public function provideMinPhpVersion(): int
    {
        return PhpVersionFeature::LIST_SWAP_ORDER;
    }
}
