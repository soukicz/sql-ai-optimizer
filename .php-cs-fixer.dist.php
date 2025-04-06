<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude(['vendor', 'node_modules'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => true,
        'blank_line_after_opening_tag' => false,
        'blank_line_before_statement' => [
            'statements' => ['return', 'throw', 'try']
        ],
        'blank_line_after_namespace' => true,
		'blank_lines_before_namespace' => true,
        'braces' => [
            'allow_single_line_anonymous_class_with_empty_body' => false,
            'allow_single_line_closure' => false,
            'position_after_anonymous_constructs' => 'same',
            'position_after_control_structures' => 'same',
            'position_after_functions_and_oop_constructs' => 'same',
        ],
        'class_attributes_separation' => [
            'elements' => [
                'method' => 'one',
                'property' => 'one',
                'const' => 'one',
            ]
        ],
        'concat_space' => ['spacing' => 'one'],
        'control_structure_continuation_position' => ['position' => 'same_line'],
        'curly_braces_position' => [
            'classes_opening_brace' => 'same_line',
            'functions_opening_brace' => 'same_line',
            'anonymous_functions_opening_brace' => 'same_line',
            'anonymous_classes_opening_brace' => 'same_line',
        ],
        'declare_parentheses' => true,
        'function_declaration' => [
            'closure_function_spacing' => 'one',
        ],
        'function_typehint_space' => true,
        'include' => true,
        'indentation_type' => true,
        'method_argument_space' => [
            'on_multiline' => 'ensure_fully_multiline',
            'keep_multiple_spaces_after_comma' => false
        ],
        'method_chaining_indentation' => false,
        'multiline_whitespace_before_semicolons' => [
            'strategy' => 'no_multi_line',
        ],
        'no_extra_blank_lines' => [
            'tokens' => [
                'extra',
                'throw',
                'use',
                'use_trait',
            ]
        ],
        'no_spaces_after_function_name' => true,
        'no_spaces_around_offset' => true,
        'no_spaces_inside_parenthesis' => true,
        'no_trailing_whitespace' => true,
        'no_trailing_whitespace_in_comment' => true,
        'operator_linebreak' => [
            'only_booleans' => true,
            'position' => 'beginning',
        ],
        'single_line_comment_style' => [
            'comment_types' => ['hash']
        ],
        'single_space_after_construct' => true,
        'space_after_semicolon' => true,
        'switch_case_semicolon_to_colon' => true,
        'switch_case_space' => true,
        'ternary_operator_spaces' => true,
        'whitespace_after_comma_in_array' => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setIndent("    ")
    ->setLineEnding("\n")
    ->setFinder($finder);
