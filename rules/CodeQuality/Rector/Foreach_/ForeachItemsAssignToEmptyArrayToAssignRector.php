<?php

declare(strict_types=1);

namespace Rector\CodeQuality\Rector\Foreach_;

use IteratorAggregate;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Stmt\Foreach_;
use Rector\CodeQuality\NodeAnalyzer\ForeachAnalyzer;
use Rector\Core\Rector\AbstractRector;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\ReadWrite\NodeFinder\NodeUsageFinder;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @see \Rector\Tests\CodeQuality\Rector\Foreach_\ForeachItemsAssignToEmptyArrayToAssignRector\ForeachItemsAssignToEmptyArrayToAssignRectorTest
 */
final class ForeachItemsAssignToEmptyArrayToAssignRector extends AbstractRector
{
    public function __construct(
        private NodeUsageFinder $nodeUsageFinder,
        private ForeachAnalyzer $foreachAnalyzer
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Change foreach() items assign to empty array to direct assign',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
class SomeClass
{
    public function run($items)
    {
        $collectedItems = [];

        foreach ($items as $item) {
             $collectedItems[] = $item;
        }
    }
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
class SomeClass
{
    public function run($items)
    {
        $collectedItems = [];

        $collectedItems = $items;
    }
}
CODE_SAMPLE
                ),
            ]
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Foreach_::class];
    }

    /**
     * @param Foreach_ $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($this->shouldSkip($node)) {
            return null;
        }

        /** @var Expr $assignVariable */
        $assignVariable = $this->foreachAnalyzer->matchAssignItemsOnlyForeachArrayVariable($node);

        return new Assign($assignVariable, $node->expr);
    }

    private function shouldSkip(Foreach_ $foreach): bool
    {
        $assignVariable = $this->foreachAnalyzer->matchAssignItemsOnlyForeachArrayVariable($foreach);
        if (! $assignVariable instanceof Expr) {
            return true;
        }

        if ($this->shouldSkipAsPartOfNestedForeach($foreach)) {
            return true;
        }

        $previousDeclaration = $this->nodeUsageFinder->findPreviousForeachNodeUsage($foreach, $assignVariable);
        if (! $previousDeclaration instanceof Node) {
            return true;
        }

        $previousDeclarationParentNode = $previousDeclaration->getAttribute(AttributeKey::PARENT_NODE);
        if (! $previousDeclarationParentNode instanceof Assign) {
            return true;
        }

        // must be empty array, otherwise it will false override
        $defaultValue = $this->valueResolver->getValue($previousDeclarationParentNode->expr);
        if ($defaultValue !== []) {
            return true;
        }

        if (!$this->isExpressionThis($foreach)) {
            return false;
        }

        $parentClass = $foreach->getAttribute(AttributeKey::CLASS_NODE);
        if (! $parentClass instanceof Node\Stmt\Class_) {
            return false;
        }
        foreach ($parentClass->implements as $implement) {
            if ((string)$implement === IteratorAggregate::class) {
                return true;
            }
        }

        return false;
    }

    private function isExpressionThis(Foreach_ $foreach): bool
    {
        /** @var Expr\Variable $expr */
        $expr = $foreach->expr;

        if (! $expr instanceof Expr\Variable) {
            return false;
        }

        return $expr->name === 'this';
    }

    private function shouldSkipAsPartOfNestedForeach(Foreach_ $foreach): bool
    {
        $foreachParent = $this->betterNodeFinder->findParentType($foreach, Foreach_::class);
        return $foreachParent !== null;
    }
}
