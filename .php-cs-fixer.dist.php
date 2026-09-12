<?php

// php-cs-fixer : la règle Symfony, plus les strict_types que le projet met déjà partout.
$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/migrations'])
    ->exclude('var');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        // Le projet aligne volontairement les => et les clés de tableaux
        'binary_operator_spaces' => ['default' => 'single_space', 'operators' => ['=>' => null, '=' => null]],
        'phpdoc_summary' => false,
        'phpdoc_align' => false,
        'concat_space' => ['spacing' => 'one'],
        'yoda_style' => false,
        'native_function_invocation' => false,
        'global_namespace_import' => ['import_classes' => false, 'import_constants' => false, 'import_functions' => false],
        // Style maison conservé : constructeurs `) {}` sur une ligne, $i++, closures non static
        'braces_position' => false,
        'increment_style' => false,
        'static_lambda' => false,
        'native_constant_invocation' => false,
    ])
    ->setFinder($finder);
