<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__])
    ->exclude('vendor')
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
/*
        'header_comment' => [
            'header'       => 'Copyright (c) 2024 Frode Børli. Released under the MIT License.',
            'comment_type' => 'PHPDoc',
            'location'     => 'after_open',
            'separate'     => 'both',
        ],
*/
        '@Symfony'                              => true,
        '@Symfony:risky'                        => false,
        'linebreak_after_opening_tag'           => true,
        'mb_str_functions'                      => false,
        'no_php4_constructor'                   => true,
        'no_unreachable_default_argument_value' => true,
        'no_useless_else'                       => true,
        'no_useless_return'                     => true,
        'php_unit_strict'                       => true,
        'phpdoc_order'                          => true,
        'strict_comparison'                     => true,
        'strict_param'                          => true,
        'array_syntax'                          => ['syntax' => 'short'],
        'binary_operator_spaces'                => [
            'operators' => [
                '='  => 'align',
                '=>' => 'align',
            ],
        ],
        'concat_space'               => ['spacing' => 'one'],
        'native_function_invocation' => [
            'include' => ['@internal'],
        ],
        'native_constant_invocation' => [
            'include' => ['@internal'],
        ],
        'ordered_imports'                  => true,
        'random_api_migration'             => true,
        'phpdoc_summary'                   => false,
        'blank_line_between_import_groups' => false,
    ])
    ->setFinder($finder);
