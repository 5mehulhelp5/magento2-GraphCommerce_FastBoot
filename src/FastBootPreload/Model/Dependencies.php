<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootPreload\Model;

/** Link dependencies used by deferred anonymous classes before PHP finalizes preloading. */
class Dependencies
{
    public static function load(): void
    {
        $parser = (new \PhpParser\ParserFactory())->createForHostVersion();
        $finder = new \PhpParser\NodeFinder();
        $seen = [];
        for ($pass = 0; $pass < 32; $pass++) {
            $files = array_diff(get_included_files(), array_keys($seen));
            if (!$files) {
                return;
            }
            foreach ($files as $file) {
                $seen[$file] = true;
                $source = (string)@file_get_contents($file);
                if (!preg_match('/\bnew\s+(?:#\[[\s\S]*?\]\s*)*class\b/', $source)) {
                    continue;
                }
                $nodes = $parser->parse($source) ?? [];
                $resolver = new \PhpParser\NodeTraverser(new \PhpParser\NodeVisitor\NameResolver());
                $nodes = $resolver->traverse($nodes);
                foreach ($finder->findInstanceOf($nodes, \PhpParser\Node\Stmt\Class_::class) as $class) {
                    if (!$class->isAnonymous()) {
                        continue;
                    }
                    $dependencies = $class->implements;
                    if ($class->extends) {
                        $dependencies[] = $class->extends;
                    }
                    foreach ($class->stmts as $statement) {
                        if ($statement instanceof \PhpParser\Node\Stmt\TraitUse) {
                            array_push($dependencies, ...$statement->traits);
                        }
                    }
                    foreach ($dependencies as $name) {
                        $name = $name->toString();
                        class_exists($name) || interface_exists($name) || trait_exists($name);
                    }
                }
            }
        }
    }
}
