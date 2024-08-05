<?php

require_once $_composer_autoload_path ?? __DIR__.'/../vendor/autoload.php';

use Illuminate\Support\Str;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprArrayNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFalseNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNullNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprTrueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;

$linting = in_array('--lint', $argv);

collect($argv)
    ->skip(1)
    ->filter(fn ($arg) => ! str_starts_with($arg, '-'))
    ->map(fn ($class) => new ReflectionClass($class))
    ->each(function ($facade) use ($linting) {
        $proxies = resolveDocSees($facade);

        if ($proxies->isEmpty()) {
            echo "Skipping [{$facade->getName()}] as no proxies were found.".PHP_EOL;
            return;
        }

        // Build a list of methods that are available on the Facade...

        $resolvedMethods = $proxies->map(fn ($fqcn) => new ReflectionClass($fqcn))
            ->flatMap(fn ($class) => [$class, ...resolveDocMixins($class)])
            ->flatMap(resolveMethods(...))
            ->reject(isMagic(...))
            ->reject(isInternal(...))
            ->reject(isDeprecated(...))
            ->reject(fulfillsBuiltinInterface(...))
            ->reject(fn ($method) => conflictsWithFacade($facade, $method))
            ->unique(resolveName(...))
            ->map(normaliseDetails(...));

        // Prepare the @method docblocks...

        $methods = $resolvedMethods->map(function ($method) {
            if (is_string($method)) {
                return " * @method static {$method}";
            }

            $parameters = $method['parameters']->map(function ($parameter) {
                $rest = $parameter['variadic'] ? '...' : '';

                $default = $parameter['optional'] ? ' = '.resolveDefaultValue($parameter) : '';

                return "{$parameter['type']} {$rest}{$parameter['name']}{$default}";
            });

            return " * @method static {$method['returns']} {$method['name']}({$parameters->join(', ')})";
        });

        // Fix: ensure we keep the references to the Carbon library on the Date Facade...

        if ($facade->getName() === Illuminate\Support\Facades\Date::class) {
            $methods->prepend(' *')
                    ->prepend(' * @see https://github.com/briannesbitt/Carbon/blob/master/src/Carbon/Factory.php')
                    ->prepend(' * @see https://carbon.nesbot.com/docs/');
        }

        // To support generics, we want to preserve any mixins on the class...

        $directMixins = resolveDocTags($facade->getDocComment() ?: '', '@mixin');

        if ($methods->isEmpty()) {
            echo "Skipping [{$facade->getName()}] as no methods were found.".PHP_EOL;
            return;
        }

        // Generate the docblock...

        $docblock = <<< PHP
        /**
        {$methods->join(PHP_EOL)}
         *
        {$proxies->map(fn ($class) => " * @see {$class}")->merge($proxies->isNotEmpty() && $directMixins->isNotEmpty() ? [' *'] : [])->merge($directMixins->map(fn ($class) => " * @mixin {$class}"))->join(PHP_EOL)}
         */
        PHP;

        if (($facade->getDocComment() ?: '') === $docblock) {
            return;
        }

        if ($linting) {
            echo "Did not find expected docblock for [{$facade->getName()}].".PHP_EOL.PHP_EOL.$docblock;
            exit(1);
        }

        // Update the facade docblock...

        echo "Updating docblock for [{$facade->getName()}].".PHP_EOL;
        $contents = file_get_contents($facade->getFileName());
        $contents = str_replace($facade->getDocComment(), $docblock, $contents);
        file_put_contents($facade->getFileName(), $contents);
    });

echo 'Done.';
exit(0);

/**
 * Resolve the classes referenced in the @see docblocks.
 *
 * @param  \ReflectionClass  $class
 * @return \Illuminate\Support\Collection<class-string>
 */
function resolveDocSees($class)
{
    return resolveDocTags($class->getDocComment() ?: '', '@see')
        ->reject(fn ($tag) => str_starts_with($tag, 'https://'));
}

/**
 * Resolve the classes referenced methods in the @methods docblocks.
 *
 * @param  \ReflectionClass  $class
 * @return \Illuminate\Support\Collection<string>
 */
function resolveDocMethods($class)
{
    return resolveDocTags($class->getDocComment() ?: '', '@method')
        ->map(fn ($tag) => Str::squish($tag))
        ->map(fn ($tag) => Str::before($tag, ')').')');
}

/**
 * Resolve the parameters type from the @param docblocks.
 *
 * @param  \ReflectionMethodDecorator  $method
 * @param  \ReflectionParameter  $parameter
 * @return string|null
 */
function resolveDocParamType($method, $parameter)
{
    $paramTypeNode = collect(parseDocblock($method->getDocComment())->getParamTagValues())
        ->firstWhere('parameterName', '$'.$parameter->getName());

    // As we didn't find a param type, we will now recursively check if the prototype has a value specified...

    if ($paramTypeNode === null) {
        try {
            $prototype = new ReflectionMethodDecorator($method->getPrototype(), $method->sourceClass()->getName());

            return resolveDocParamType($prototype, $parameter);
        } catch (Throwable) {
            return null;
        }
    }

    $type = resolveDocblockTypes($method, $paramTypeNode->type);

    return is_string($type) ? trim($type, '()') : null;
}

/**
 * Resolve the return type from the @return docblock.
 *
 * @param  \ReflectionMethodDecorator  $method
 * @return string|null
 */
function resolveReturnDocType($method)
{
    $returnTypeNode = array_values(parseDocblock($method->getDocComment())->getReturnTagValues())[0] ?? null;

    if ($returnTypeNode === null) {
        return null;
    }

    $type = resolveDocblockTypes($method, $returnTypeNode->type);

    return is_string($type) ? trim($type, '()') : null;
}

/**
 * Parse the given docblock.
 *
 * @param  string  $docblock
 * @return \PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode
 */
function parseDocblock($docblock)
{
    return (new PhpDocParser(new TypeParser(new ConstExprParser), new ConstExprParser))->parse(
        new TokenIterator((new Lexer)->tokenize($docblock ?: '/** */'))
    );
}

/**
 * Resolve the types from the docblock.
 *
 * @param  \ReflectionMethodDecorator  $method
 * @param  \PHPStan\PhpDocParser\Ast\Type\TypeNode  $typeNode
 * @return string|null
 */
function resolveDocblockTypes($method, $typeNode, $depth = 1)
{
    try {
        if ($typeNode instanceof UnionTypeNode) {
            return '('.collect($typeNode->types)
                ->map(fn ($node) => resolveDocblockTypes($method, $node, $depth + 1))
                ->unique()
                ->implode('|').')';
        }

        if ($typeNode instanceof IntersectionTypeNode) {
            return '('.collect($typeNode->types)
                ->map(fn ($node) => resolveDocblockTypes($method, $node, $depth + 1))
                ->unique()
                ->implode('&').')';
        }

        if ($typeNode instanceof GenericTypeNode) {
            return resolveDocblockTypes($method, $typeNode->type, $depth + 1);
        }

        if ($typeNode instanceof ThisTypeNode) {
            return '\\'.$method->sourceClass()->getName();
        }

        if ($typeNode instanceof ArrayTypeNode) {
            return resolveDocblockTypes($method, $typeNode->type, $depth + 1).'[]';
        }

        if ($typeNode instanceof IdentifierTypeNode) {
            if ($typeNode->name === 'static') {
                return '\\'.$method->sourceClass()->getName();
            }

            if ($typeNode->name === 'self') {
                return '\\'.$method->getDeclaringClass()->getName();
            }

            if (isBuiltIn($typeNode->name)) {
                return (string) $typeNode;
            }

            if ($typeNode->name === 'class-string') {
                return 'string';
            }

            if ($typeNode->name === 'list') {
                return 'array';
            }

            $guessedFqcn = resolveClassImports($method->getDeclaringClass())->get($typeNode->name) ?? '\\'.$method->getDeclaringClass()->getNamespaceName().'\\'.$typeNode->name;

            foreach ([$typeNode->name, $guessedFqcn] as $name) {
                if (class_exists($name)) {
                    return Str::start((string) $name, '\\');
                }

                if (interface_exists($name)) {
                    return (string) $name;
                }

                if (enum_exists($name)) {
                    return (string) $name;
                }

                if (isKnownOptionalDependency($name)) {
                    return (string) $name;
                }
            }

            return handleUnknownIdentifierType($method, $typeNode);
        }

        if ($typeNode instanceof ConditionalTypeNode) {
            return handleConditionalType($method, $typeNode);
        }

        if ($typeNode instanceof NullableTypeNode) {
            return '?'.resolveDocblockTypes($method, $typeNode->type, $depth + 1);
        }

        if ($typeNode instanceof CallableTypeNode) {
            return resolveDocblockTypes($method, $typeNode->identifier, $depth + 1);
        }

        if ($typeNode instanceof ConstTypeNode) {
            if ($typeNode->constExpr instanceof ConstExprStringNode) {
                return 'string';
            }

            if ($typeNode->constExpr instanceof ConstExprIntegerNode) {
                return 'int';
            }

            if ($typeNode->constExpr instanceof ConstExprNullNode) {
                return 'null';
            }

            if ($typeNode->constExpr instanceof ConstExprFloatNode) {
                return 'float';
            }

            if ($typeNode->constExpr instanceof ConstExprFalseNode) {
                return 'false';
            }

            if ($typeNode->constExpr instanceof ConstExprTrueNode) {
                return 'true';
            }

            if ($typeNode->constExpr instanceof ConstExprArrayNode) {
                return 'false';
            }

            $class = $typeNode->constExpr::class;
            throw new UnresolvableType('resolveDocblockTypes', <<<MESSAGE
                Unknown constant type [{$class}] encountered.
                MESSAGE);
        }

        $class = $typeNode::class;

        throw new UnresolvableType('resolveDocblockTypes', <<<MESSAGE
            Unknown type node [{$class}] encountered.
            MESSAGE);
    } catch (UnresolvableType $e) {
        if ($depth > 1) {
            throw $e;
        }

        echo $e->getMessage();
        echo PHP_EOL;
        echo 'You can safely ignore this message if there is a native type declartion in place, which will be used as a fallback.';
        echo PHP_EOL;
        echo "You may tweak the {$e->method} function of the facade-documenter if a fix is required.";
        echo PHP_EOL;
        echo PHP_EOL;

        return null;
    }
}

/**
 * Handle conditional types.
 *
 * @param  \ReflectionMethodDecorator  $method
 * @param  \PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode  $typeNode
 * @return string
 */
function handleConditionalType($method, $typeNode)
{
    if (
        in_array($method->getname(), ['pull', 'get']) &&
        $method->getDeclaringClass()->getName() === Illuminate\Cache\Repository::class
    ) {
        return 'mixed';
    }

    throw new UnresolvableType('handleConditionalType', <<<MESSAGE
        Unknown conditional type encountered on method [{$method->getDeclaringClass()->getName()}::{$method->getName()}].
        MESSAGE);
}

/**
 * Handle unknown identifier types.
 *
 * @param  \ReflectionMethodDecorator  $method
 * @param  \PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode  $typeNode
 * @return string
 */
function handleUnknownIdentifierType($method, $typeNode)
{
    if (
        $typeNode->name === 'TCacheValue' &&
        $method->getDeclaringClass()->getName() === Illuminate\Cache\Repository::class
    ) {
        return 'mixed';
    }

    if (
        $typeNode->name === 'TWhenParameter' &&
        in_array(Illuminate\Support\Traits\Conditionable::class, class_uses_recursive($method->getDeclaringClass()->getName()))
    ) {
        return 'mixed';
    }

    if (
        $typeNode->name === 'TWhenReturnType' &&
        in_array(Illuminate\Support\Traits\Conditionable::class, class_uses_recursive($method->getDeclaringClass()->getName()))
    ) {
        return 'mixed';
    }

    if (
        $typeNode->name === 'TUnlessParameter' &&
        in_array(Illuminate\Support\Traits\Conditionable::class, class_uses_recursive($method->getDeclaringClass()->getName()))
    ) {
        return 'mixed';
    }

    if (
        $typeNode->name === 'TUnlessReturnType' &&
        in_array(Illuminate\Support\Traits\Conditionable::class, class_uses_recursive($method->getDeclaringClass()->getName()))
    ) {
        return 'mixed';
    }

    if (
        $typeNode->name === 'TEnum' &&
        $method->getDeclaringClass()->getName() === Illuminate\Http\Request::class
    ) {
        return 'object';
    }

    throw new UnresolvableType('handleUnknownIdentifierType', <<<MESSAGE
        Unknown doctype [{$typeNode->name}] encountered, which is likely a generic, on method [{$method->getDeclaringClass()->getName()}::{$method->getName()}].
        MESSAGE);
}

/**
 * Determine if the type is a built-in.
 *
 * @param  string  $type
 * @return bool
 */
function isBuiltIn($type)
{
    return in_array($type, [
        'null', 'bool', 'int', 'float', 'string', 'array', 'object',
        'resource', 'never', 'void', 'mixed', 'iterable', 'self', 'static',
        'parent', 'true', 'false', 'callable',
    ]);
}

/**
 * Determine if the type is known optional dependency.
 *
 * @param  string  $type
 * @return bool
 */
function isKnownOptionalDependency($type)
{
    return in_array($type, [
        '\Pusher\Pusher',
        '\GuzzleHttp\Psr7\RequestInterface',
    ]);
}

/**
 * Resolve the declared type.
 *
 * @param  \ReflectionMethodDecorator  $method
 * @param  \ReflectionType|null  $type
 * @return string|null
 */
function resolveType($method, $type)
{
    if ($type instanceof ReflectionIntersectionType) {
        return collect($type->getTypes())
            ->map(fn ($type) => resolveType($method, $type))
            ->filter()
            ->join('&');
    }

    if ($type instanceof ReflectionUnionType) {
        return collect($type->getTypes())
            ->map(fn ($type) => resolveType($method, $type))
            ->filter()
            ->join('|');
    }

    if ($type instanceof ReflectionNamedType && $type->getName() === 'null') {
        return 'null';
    }

    if ($type instanceof ReflectionNamedType) {
        if ($type->getName() === 'static') {
            return '\\'.$method->sourceClass()->getName();
        }

        if ($type->getName() === 'self') {
            return '\\'.$method->getDeclaringClass()->getName();
        }

        return ($type->isBuiltin() ? '' : '\\').$type->getName().(($type->allowsNull() && $type->getName() !== 'mixed') ? '|null' : '');
    }

    return null;
}

/**
 * Resolve the docblock tags.
 *
 * @param  string  $docblock
 * @param  string  $tag
 * @return \Illuminate\Support\Collection<string>
 */
function resolveDocTags($docblock, $tag)
{
    return Str::of($docblock)
        ->explode("\n")
        ->skip(1)
        ->reverse()
        ->skip(1)
        ->reverse()
        ->map(fn ($line) => ltrim($line, ' \*'))
        ->filter(fn ($line) => str_starts_with($line, $tag))
        ->map(fn ($line) => Str::of($line)->after($tag)->trim()->toString())
        ->values();
}

/**
 * Recursively resolve docblock mixins.
 *
 * @param  \ReflectionClass  $class
 * @return \Illuminate\Support\Collection<\ReflectionClass>
 */
function resolveDocMixins($class)
{
    return resolveDocTags($class->getDocComment() ?: '', '@mixin')
        ->map(fn ($mixin) => new ReflectionClass($mixin))
        ->flatMap(fn ($mixin) => [$mixin, ...resolveDocMixins($mixin)]);
}

/**
 * Resolve the classes referenced methods in the @methods docblocks.
 *
 * @param  \ReflectionMethodDecorator  $method
 * @return \Illuminate\Support\Collection<int, string>
 */
function resolveDocParameters($method)
{
    return resolveDocTags($method->getDocComment() ?: '', '@param')
        ->map(fn ($tag) => Str::squish($tag));
}

/**
 * Determine if the method is magic.
 *
 * @param  \ReflectionMethod|string  $method
 * @return bool
 */
function isMagic($method)
{
    return Str::startsWith(is_string($method) ? $method : $method->getName(), '__');
}

/**
 * Determine if the method is marked as @internal.
 *
 * @param  \ReflectionMethod|string  $method
 * @return bool
 */
function isInternal($method)
{
    if (is_string($method)) {
        return false;
    }

    return resolveDocTags($method->getDocComment(), '@internal')->isNotEmpty();
}

/**
 * Determine if the method is deprecated.
 *
 * @param  \ReflectionMethod|string  $method
 * @return bool
 */
function isDeprecated($method)
{
    if (is_string($method)) {
        return false;
    }

    return $method->isDeprecated() || resolveDocTags($method->getDocComment(), '@deprecated')->isNotEmpty();
}

/**
 * Determine if the method is for a builtin contract.
 *
 * @param  \ReflectionMethodDecorator|string  $method
 * @return bool
 */
function fulfillsBuiltinInterface($method)
{
    if (is_string($method)) {
        return false;
    }

    if ($method->sourceClass()->implementsInterface(ArrayAccess::class)) {
        return in_array($method->getName(), ['offsetExists', 'offsetGet', 'offsetSet', 'offsetUnset']);
    }

    return false;
}

/**
 * Resolve the methods name.
 *
 * @param  \ReflectionMethod|string  $method
 * @return string
 */
function resolveName($method)
{
    return is_string($method)
        ? Str::of($method)->after(' ')->before('(')->toString()
        : $method->getName();
}

/**
 * Resolve the classes methods.
 *
 * @param  \ReflectionClass  $class
 * @return \Illuminate\Support\Collection<\ReflectionMethodDecorator|string>
 */
function resolveMethods($class)
{
    return collect($class->getMethods(ReflectionMethod::IS_PUBLIC))
        ->map(fn ($method) => new ReflectionMethodDecorator($method, $class->getName()))
        ->merge(resolveDocMethods($class));
}

/**
 * Determine if the given method conflicts with a Facade method.
 *
 * @param  \ReflectionClass  $facade
 * @param  \ReflectionMethod|string  $method
 * @return bool
 */
function conflictsWithFacade($facade, $method)
{
    return collect($facade->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC))
        ->map(fn ($method) => $method->getName())
        ->contains(is_string($method) ? $method : $method->getName());
}

/**
 * Normalise the method details into a easier format to work with.
 *
 * @param  \ReflectionMethodDecorator|string  $method
 * @return array|string
 */
function normaliseDetails($method)
{
    return is_string($method) ? $method : [
        'name' => $method->getName(),
        'parameters' => resolveParameters($method)
            ->map(fn ($parameter) => [
                'name' => '$'.$parameter->getName(),
                'optional' => $parameter->isOptional() && ! $parameter->isVariadic(),
                'default' => $parameter->isDefaultValueAvailable()
                    ? $parameter->getDefaultValue()
                    : "❌ Unknown default for [{$parameter->getName()}] in [{$parameter->getDeclaringClass()?->getName()}::{$parameter->getDeclaringFunction()->getName()}] ❌",
                'variadic' => $parameter->isVariadic(),
                'type' => resolveDocParamType($method, $parameter) ?? resolveType($method, $parameter->getType()) ?? 'void',
            ]),
        'returns' => resolveReturnDocType($method) ?? resolveType($method, $method->getReturnType()) ?? 'void',
    ];
}

/**
 * Resolve the parameters for the method.
 *
 * @param  \ReflectionMethodDecorator  $method
 * @return \Illuminate\Support\Collection<int, \ReflectionParameter|\DynamicParameter>
 */
function resolveParameters($method)
{
    $dynamicParameters = resolveDocParameters($method)
        ->skip($method->getNumberOfParameters())
        ->mapInto(DynamicParameter::class);

    return collect($method->getParameters())->merge($dynamicParameters);
}

/**
 * Resolve the classes imports.
 *
 * @param  \ReflectionClass  $class
 * @return \Illuminate\Support\Collection<string, class-string>
 */
function resolveClassImports($class)
{
    return Str::of(file_get_contents($class->getFileName()))
        ->explode(PHP_EOL)
        ->take($class->getStartLine() - 1)
        ->filter(fn ($line) => preg_match('/^use [A-Za-z0-9\\\\]+( as [A-Za-z0-9]+)?;$/', $line) === 1)
        ->map(fn ($line) => Str::of($line)->after('use ')->before(';'))
        ->mapWithKeys(fn ($class) => [
            ((string) ($class->contains(' as ') ? $class->after(' as ') : $class->classBasename())) => $class->start('\\')->before(' as ')->toString(),
        ]);
}

/**
 * Resolve the default value for the parameter.
 *
 * @param  array  $parameter
 * @return string
 */
function resolveDefaultValue($parameter)
{
    // Reflection limitation fix for:
    // - Illuminate\Filesystem\Filesystem::ensureDirectoryExists()
    // - Illuminate\Filesystem\Filesystem::makeDirectory()
    if ($parameter['name'] === '$mode' && $parameter['default'] === 493) {
        return '0755';
    }

    $default = json_encode($parameter['default']);

    return Str::of($default === false ? 'unknown' : $default)
        ->replace('"', "'")
        ->replace('\\/', '/')
        ->toString();
}

/**
 * @mixin \ReflectionMethod
 */
class ReflectionMethodDecorator
{
    /**
     * @param  \ReflectionMethod  $method
     * @param  class-string  $sourceClass
     */
    public function __construct(private $method, private $sourceClass)
    {
        //
    }

    /**
     * @param  string  $name
     * @param  array  $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        return $this->method->{$name}(...$arguments);
    }

    /**
     * @return \ReflectionMethod
     */
    public function toBase()
    {
        return $this->method;
    }

    /**
     * @return \ReflectionClass
     */
    public function sourceClass()
    {
        return new ReflectionClass($this->sourceClass);
    }
}

class DynamicParameter
{
    /**
     * @param  string  $definition
     */
    public function __construct(private $definition)
    {
        //
    }

    /**
     * @return string
     */
    public function getName()
    {
        return Str::of($this->definition)
            ->after('$')
            ->before(' ')
            ->toString();
    }

    /**
     * @return bool
     */
    public function isOptional()
    {
        return true;
    }

    /**
     * @return bool
     */
    public function isVariadic()
    {
        return Str::contains($this->definition, " ...\${$this->getName()}");
    }

    /**
     * @return bool
     */
    public function isDefaultValueAvailable()
    {
        return true;
    }

    /**
     * @return null
     */
    public function getDefaultValue()
    {
        return null;
    }
}

class UnresolvableType extends Exception
{
    public function __construct(public string $method, string $message)
    {
        parent::__construct($message);
    }
}
