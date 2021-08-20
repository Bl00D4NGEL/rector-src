<?php

declare(strict_types=1);

namespace Rector\CodeQuality\Rector\ClassMethod;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Property;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\UnionType;
use Rector\BetterPhpDocParser\PhpDocManipulator\PhpDocTypeChanger;
use Rector\Core\Exception\ShouldNotHappenException;
use Rector\Core\NodeAnalyzer\ParamAnalyzer;
use Rector\Core\Rector\AbstractRector;
use Rector\Core\ValueObject\MethodName;
use Rector\Core\ValueObject\PhpVersionFeature;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\VersionBonding\Contract\MinPhpVersionInterface;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @see \Rector\Tests\CodeQuality\Rector\ClassMethod\DateTimeToDateTimeInterfaceRector\DateTimeToDateTimeInterfaceRectorTest
 */
final class DateTimeToDateTimeInterfaceRector extends AbstractRector implements MinPhpVersionInterface
{
    /**
     * @var string[]
     */
    private const METHODS_RETURNING_CLASS_INSTANCE_MAP = [
        'add', 'modify', MethodName::SET_STATE, 'setDate', 'setISODate', 'setTime', 'setTimestamp', 'setTimezone', 'sub',
    ];

    public function __construct(
        private PhpDocTypeChanger $phpDocTypeChanger,
        private ParamAnalyzer $paramAnalyzer
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Changes DateTime type-hint to DateTimeInterface', [
            new CodeSample(
                <<<'CODE_SAMPLE'
class SomeClass {
    public function methodWithDateTime(\DateTime $dateTime)
    {
        return true;
    }
}
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
class SomeClass {
    /**
     * @param \DateTime|\DateTimeImmutable $dateTime
     */
    public function methodWithDateTime(\DateTimeInterface $dateTime)
    {
        return true;
    }
}
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [ClassMethod::class, Property::class];
    }

    /**
     * @param ClassMethod|Property $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($node instanceof ClassMethod) {
            return $this->refactorClassMethod($node);
        }

        return $this->refactorProperty($node);
    }

    public function provideMinPhpVersion(): int
    {
        return PhpVersionFeature::DATE_TIME_INTERFACE;
    }

    private function refactorClassMethod(ClassMethod $classMethod): ?Node {

        $classMethod = $this->refactorFunctionParameters($classMethod);

        /** @var FullyQualified|null $returnType */
        $returnType = $classMethod->returnType;
        if ($returnType === null) {
            return null;
        }

        $isNullable = false;
        if ($returnType instanceof NullableType) {
            $isNullable = true;
            $returnType = $returnType->type;
        }
        if (! $this->isObjectType($returnType, new ObjectType('DateTime'))) {
            return null;
        }

        $classMethod->returnType = new FullyQualified('DateTimeInterface');
        if ($isNullable) {
            $classMethod->returnType = new NullableType($classMethod->returnType);
        }


        $types = $this->determinePhpDocTypes($classMethod->returnType);
        $phpDocInfo = $this->phpDocInfoFactory->createFromNodeOrEmpty($classMethod);
        $this->phpDocTypeChanger->changeReturnType($phpDocInfo, new UnionType($types));

        return $classMethod;
    }

    private function refactorParamTypeHint(Param $param): void
    {
        $fullyQualified = new FullyQualified('DateTimeInterface');
        if ($this->paramAnalyzer->isNullable($param)) {
            $param->type = new NullableType($fullyQualified);
            return;
        }

        $param->type = $fullyQualified;
    }

    private function refactorParamDocBlock(Param $param, ClassMethod $classMethod): void
    {
        $types = $this->determinePhpDocTypes($param);

        $paramName = $this->getName($param->var);
        if ($paramName === null) {
            throw new ShouldNotHappenException();
        }

        $phpDocInfo = $this->phpDocInfoFactory->createFromNodeOrEmpty($classMethod);
        $this->phpDocTypeChanger->changeParamType($phpDocInfo, new UnionType($types), $param, $paramName);
    }

    private function refactorMethodCalls(Param $param, ClassMethod $classMethod): void
    {
        if ($classMethod->stmts === null) {
            return;
        }

        $this->traverseNodesWithCallable($classMethod->stmts, function (Node $node) use ($param): void {
            if (! ($node instanceof MethodCall)) {
                return;
            }

            $this->refactorMethodCall($param, $node);
        });
    }

    private function refactorMethodCall(Param $param, MethodCall $methodCall): void
    {
        $paramName = $this->getName($param->var);
        if ($paramName === null) {
            return;
        }
        if ($this->shouldSkipMethodCallRefactor($paramName, $methodCall)) {
            return;
        }

        $assign = new Assign(new Variable($paramName), $methodCall);

        /** @var Node $parent */
        $parent = $methodCall->getAttribute(AttributeKey::PARENT_NODE);
        if ($parent instanceof Arg) {
            $parent->value = $assign;
            return;
        }

        if (! $parent instanceof Expression) {
            return;
        }

        $parent->expr = $assign;
    }

    private function shouldSkipMethodCallRefactor(string $paramName, MethodCall $methodCall): bool
    {
        if (! $this->isName($methodCall->var, $paramName)) {
            return true;
        }

        if (! $this->isNames($methodCall->name, self::METHODS_RETURNING_CLASS_INSTANCE_MAP)) {
            return true;
        }

        $parentNode = $methodCall->getAttribute(AttributeKey::PARENT_NODE);
        if (! $parentNode instanceof Node) {
            return true;
        }

        return $parentNode instanceof Assign;
    }

    private function refactorFunctionParameters(ClassMethod $node): ClassMethod
    {
        foreach ($node->getParams() as $param) {
            if (! $this->isObjectType($param, new ObjectType('DateTime'))) {
                continue;
            }

            // Do not refactor if node's type is a child class of \DateTime (can have wider API)
            $paramType = $this->nodeTypeResolver->resolve($param);
            if (! $paramType->isSuperTypeOf(new ObjectType('DateTime'))->yes()) {
                continue;
            }


            $this->refactorParamTypeHint($param);
            $this->refactorParamDocBlock($param, $node);
            $this->refactorMethodCalls($param, $node);
        }

        return $node;
    }

    private function refactorProperty(Property $node): ?Node
    {
        $type = $node->type;
        if ($type === null) {
            return null;
        }

        $isNullable = false;
        if ($type instanceof NullableType) {
            $isNullable = true;
            $type = $type->type;
        }
        if (! $this->isObjectType($type, new ObjectType('DateTime'))) {
            return null;
        }


        $types = $this->determinePhpDocTypes($node->type);

        $phpDocInfo = $this->phpDocInfoFactory->createFromNodeOrEmpty($node);
        $this->phpDocTypeChanger->changeVarType($phpDocInfo, new UnionType($types));

        $node->type = new FullyQualified('DateTimeInterface');
        if ($isNullable) {
            $node->type = new NullableType($node->type);
        }
        return $node;
    }

    private function determinePhpDocTypes(Node $node): array
    {
        $types = [
            new ObjectType('DateTime'),
            new ObjectType('DateTimeImmutable')
        ];

        if ($node instanceof Param) {
            if ($this->paramAnalyzer->isNullable($node)) {
                $types[] = new NullType();
            }
        } else {
            if ($node instanceof NullableType) {
                $types[] = new NullType();
            }
        }

        return $types;
    }
}
