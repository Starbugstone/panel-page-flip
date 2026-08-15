<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/migrations'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        'encoding' => true,
        'full_opening_tag' => true,
        'single_blank_line_at_eof' => true,
    ])
    ->setFinder($finder);
