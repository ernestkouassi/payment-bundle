<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->name('*.php')
    ->notPath('vendor');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'global_namespace_import' => [
            'import_classes' => false,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'declare_strict_types' => true,
        'strict_param' => true,
        'strict_comparison' => true,
        'array_syntax' => ['syntax' => 'short'],
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'no_multiline_whitespace_around_double_arrow' => true,
        'single_quote' => true,
        'explicit_string_variable' => true,
        'phpdoc_align' => ['align' => 'vertical'],
        'phpdoc_order' => true,
        'phpdoc_separation' => true,
        'phpdoc_trim' => true,
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true, 'remove_inheritdoc' => false],
        'ordered_class_elements' => [
            'order' => [
                'use_trait',
                'case',
                'constant_public',
                'constant_protected',
                'constant_private',
                'property_public',
                'property_protected',
                'property_private',
                'construct',
                'destruct',
                'magic',
                'phpunit',
                'method_public_static',
                'method_protected_static',
                'method_private_static',
                'method_public',
                'method_protected',
                'method_private',
            ],
        ],
        'class_attributes_separation' => [
            'elements' => ['method' => 'one', 'property' => 'one', 'const' => 'one'],
        ],
        'not_operator_with_successor_space' => true,
        'concat_space' => ['spacing' => 'one'],
        'modernize_types_casting' => true,
        'ternary_to_null_coalescing' => true,
        'use_arrow_functions' => true,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/var/.php-cs-fixer.cache');
