<?php

declare(strict_types=1);

namespace Rector\Core;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PHPStan\Type\ObjectType;
use Rector\Core\Contract\Rector\RectorInterface;
use Rector\Core\Rector\AbstractRector;
use Rector\VersionBonding\Contract\MinPhpVersionInterface;
use Symplify\Astral\ValueObject\NodeBuilder\MethodBuilder;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class SomeRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {

    }

    public function getNodeTypes(): array
    {
        return [Node\Stmt\Class_::class];
    }

    /**
     * @param Node\Stmt\Class_ $node
     * @return Node|Node[]|void|null
     */
    public function refactor(Node $node)
    {
        if (! $this->isObjectType($node, new ObjectType(RectorInterface::class))) {
            return null;
        }

        $refactorClassMethod = $node->getMethod('refactor');

        if ($refactorClassMethod->stmts === null) {
            return null;
        }

        $firstStmt = $refactorClassMethod->stmts[0];
        if (! $firstStmt instanceof If_) {
            return null;
        }

        $this->removeNode($firstStmt);

        if (! $firstStmt->cond instanceof Node\Expr\BooleanNot) {
            return null;
        }

        $booleanNot = $firstStmt->cond;
        if (! $booleanNot->expr instanceof MethodCall) {
            return null;
        }

        $methodCall = $booleanNot->expr;
        if (! $this->isName($methodCall->name, 'isAtLeastPhpVersion')) {
            return null;
        }

        $constantValue = $methodCall->args[0]->value;

        $node->implements[] = new Node\Name\FullyQualified(MinPhpVersionInterface::class);

        $methodBuilder = new MethodBuilder('provideMinPhpVersion');
        $methodBuilder->makePublic();
        $methodBuilder->setReturnType(new Identifier('int'));

        $return = new Return_();
        $return->expr = $constantValue;
        $methodBuilder->addStmt($return);

        $node->stmts[] = $methodBuilder->getNode();
        return $node;
    }
}
