<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->name('*.php')
    ->exclude(['vendor', 'frontend', 'node_modules', 'storage', 'public/admin', 'dist']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments']],
        'strict_param' => true,
        'strict_comparison' => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'native_function_invocation' => [
            'include' => ['@compiler_optimized'],
            'scope' => 'namespaced',
            'strict' => true,
        ],
        'blank_line_before_statement' => [
            'statements' => ['return', 'throw'],
        ],
    ])
    ->setFinder($finder);
