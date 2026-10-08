<?php
/**
 * Dependency-light guard for the declared PHP minimum.
 *
 * The plugin declares "Requires PHP: 8.1", but it is developed on newer
 * interpreters, which happily accept syntax that PHP 8.1 rejects at compile
 * time. One such declaration in any required file is a fatal error on every
 * request: a `true|WP_Error` return type kept 0.2.0–0.7.0 from loading on
 * PHP 8.1 at all. Newer interpreters parse that code without a word, so this
 * test tokenizes the plugin's own source and looks for the 8.2+ constructs and
 * builtins themselves.
 *
 * It complements, and does not replace, linting with an older interpreter:
 * `php -l` under PHP 8.1 is the authoritative check. PHP 8.0 also works for
 * that, because it rejects every 8.2+ construct; there the only acceptable
 * errors are features PHP 8.1 itself introduced.
 *
 * Run directly with: php tests/php-compat.php
 */

declare(strict_types=1);

$root = dirname( __DIR__ );

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

/** Functions added after PHP 8.1 that a plugin like this one could plausibly reach for. */
const SRC_TEST_NEWER_FUNCTIONS = array(
	// 8.2
	'ini_parse_quantity', 'memory_reset_peak_usage', 'mysqli_execute_query', 'openssl_cipher_key_length', 'curl_upkeep', 'libxml_get_external_entity_loader',
	// 8.3
	'json_validate', 'mb_str_pad', 'str_increment', 'str_decrement', 'stream_context_set_options',
	// 8.4
	'array_find', 'array_find_key', 'array_any', 'array_all', 'mb_trim', 'mb_ltrim', 'mb_rtrim', 'mb_ucfirst', 'mb_lcfirst',
	'http_get_last_response_headers', 'http_clear_last_response_headers', 'request_parse_body', 'fpow', 'bcround', 'bcceil', 'bcfloor', 'bcdivmod',
	// 8.5
	'array_first', 'array_last', 'get_error_handler', 'get_exception_handler',
);

/** Classes added after PHP 8.1. Attributes are left out: PHP 8.1 never instantiates them. */
const SRC_TEST_NEWER_CLASSES = array(
	'random\\randomizer', 'random\\engine\\secure', 'random\\engine\\mt19937', 'random\\engine\\pcgoneseq128xslrr64', 'random\\engine\\xoshiro256starstar',
	'dom\\htmldocument', 'dom\\xmldocument', 'bcmath\\number', 'roundingmode',
	'uri\\rfc3986\\uri', 'uri\\whatwg\\url',
);

/** Type-position tokens, by id; '?', '|', '&', '(' and ')' are matched by text. */
$type_ids = array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_ARRAY, T_CALLABLE, T_STATIC );

/**
 * Scan one source string and return "line: problem" strings.
 *
 * @return string[]
 */
$scan = static function ( string $code ) use ( $type_ids ): array {
	$tokens = array_values(
		array_filter(
			PhpToken::tokenize( $code ),
			static fn ( PhpToken $token ): bool => ! $token->is( array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML ) )
		)
	);
	$count    = count( $tokens );
	$problems = array();

	$text = static fn ( int $index ): string => $index < $count ? $tokens[ $index ]->text : '';

	$is_type_token = static function ( int $index ) use ( $tokens, $count, $type_ids, $text ): bool {
		if ( $index >= $count ) {
			return false;
		}
		if ( $tokens[ $index ]->is( $type_ids ) || in_array( $text( $index ), array( '?', '|', '(', ')' ), true ) ) {
			return true;
		}

		// An ampersand is an intersection type only when no variable follows it.
		return '&' === $text( $index ) && ! ( $index + 1 < $count && $tokens[ $index + 1 ]->is( array( T_VARIABLE, T_ELLIPSIS ) ) );
	};

	$check_type = static function ( array $parts, int $line ) use ( &$problems ): void {
		$names = array_values( array_filter( array_map( 'strtolower', preg_split( '/[|&?()]/', implode( '', $parts ) ) ?: array() ), 'strlen' ) );
		if ( array() === $names ) {
			return;
		}
		if ( in_array( '(', $parts, true ) ) {
			$problems[] = $line . ': disjunctive normal form type ' . implode( '', $parts ) . ' needs PHP 8.2';
			return;
		}
		if ( in_array( 'true', $names, true ) ) {
			$problems[] = $line . ': the `true` type in ' . implode( '', $parts ) . ' needs PHP 8.2';
		} elseif ( array() === array_diff( $names, array( 'null', 'false' ) ) ) {
			$problems[] = $line . ': standalone ' . implode( '', $parts ) . ' type needs PHP 8.2';
		}
	};

	// Collect type tokens starting at $index; returns [parts, index of the first non-type token].
	// A ')' with no '(' of its own belongs to the parameter list, not to the type.
	$collect = static function ( int $index ) use ( $is_type_token, $text ): array {
		$parts = array();
		$open  = 0;
		while ( $is_type_token( $index ) ) {
			$current = $text( $index );
			if ( ')' === $current ) {
				if ( 0 === $open ) {
					break;
				}
				--$open;
			} elseif ( '(' === $current ) {
				++$open;
			}
			$parts[] = $current;
			++$index;
		}

		return array( $parts, $index );
	};

	$modifiers   = array( T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR, T_STATIC, T_READONLY, T_FINAL, T_ABSTRACT );
	$depth       = 0;
	$trait_depth = null;
	$trait_open  = false;

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		$line  = $token->line;

		if ( '{' === $token->text || $token->is( array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ) ) ) {
			++$depth;
			if ( $trait_open ) {
				$trait_depth = $depth;
				$trait_open  = false;
			}
			continue;
		}
		if ( '}' === $token->text ) {
			if ( null !== $trait_depth && $depth === $trait_depth ) {
				$trait_depth = null;
			}
			--$depth;
			continue;
		}
		if ( $token->is( T_TRAIT ) ) {
			$trait_open = true;
			continue;
		}

		// readonly class / readonly final class.
		if ( $token->is( T_READONLY ) ) {
			$next = $i + 1;
			while ( $next < $count && $tokens[ $next ]->is( array( T_FINAL, T_ABSTRACT ) ) ) {
				++$next;
			}
			if ( $next < $count && $tokens[ $next ]->is( T_CLASS ) ) {
				$problems[] = $line . ': readonly classes need PHP 8.2';
			}
		}

		// Class constants: typed ones need 8.3, any constant inside a trait needs 8.2.
		if ( $token->is( T_CONST ) ) {
			$before_value = 0;
			for ( $next = $i + 1; $next < $count && ! in_array( $text( $next ), array( '=', ';' ), true ); $next++ ) {
				++$before_value;
			}
			if ( '=' === $text( $next ) && $before_value > 1 ) {
				$problems[] = $line . ': typed class constants need PHP 8.3';
			}
			if ( null !== $trait_depth && $depth === $trait_depth ) {
				$problems[] = $line . ': constants in traits need PHP 8.2';
			}
			continue;
		}

		// Property types: modifiers, then a type, then the variable. A bare `static`
		// starts one only at statement level; elsewhere it is static::, new static or a closure.
		$statement_start = 0 === $i || in_array( $tokens[ $i - 1 ]->text, array( ';', '{', '}' ), true ) || $tokens[ $i - 1 ]->is( $modifiers );
		if ( $token->is( $modifiers ) && ( ! $token->is( T_STATIC ) || $statement_start ) ) {
			$next = $i + 1;
			while ( $next < $count && $tokens[ $next ]->is( $modifiers ) ) {
				++$next;
			}
			list( $parts, $after ) = $collect( $next );
			if ( $after < $count && $tokens[ $after ]->is( T_VARIABLE ) ) {
				$check_type( $parts, $line );
			}
		}

		// Function, method, closure and arrow-function signatures.
		if ( $token->is( array( T_FUNCTION, T_FN ) ) ) {
			$next = $i + 1;
			if ( '&' === $text( $next ) ) {
				++$next;
			}
			if ( $token->is( T_FUNCTION ) && '(' !== $text( $next ) ) {
				++$next; // The name; reserved words are valid method names, so accept any token.
			}
			if ( '(' !== $text( $next ) ) {
				continue; // `use function ...` and the like.
			}

			// Parameters: [attributes] [modifiers] [type] [&] [...] $name [= default].
			$nesting     = 1;
			$param_start = true;
			for ( $next++; $next < $count && $nesting > 0; $next++ ) {
				$current = $text( $next );
				if ( $tokens[ $next ]->is( T_ATTRIBUTE ) ) {
					for ( $brackets = 1, $next++; $next < $count && $brackets > 0; $next++ ) {
						$brackets += ( '[' === $text( $next ) || $tokens[ $next ]->is( T_ATTRIBUTE ) ) ? 1 : ( ']' === $text( $next ) ? -1 : 0 );
					}
					--$next;
					continue;
				}
				if ( 1 === $nesting && $param_start ) {
					while ( $next < $count && $tokens[ $next ]->is( $modifiers ) ) {
						++$next;
					}
					list( $parts, $after ) = $collect( $next );
					if ( $after < $count && ( $tokens[ $after ]->is( array( T_VARIABLE, T_ELLIPSIS ) ) || '&' === $text( $after ) ) ) {
						$check_type( $parts, $tokens[ $next ]->line );
					}
					$param_start = false;
					$next        = $after - 1;
					continue;
				}
				if ( in_array( $current, array( '(', '[', '{' ), true ) ) {
					++$nesting;
				} elseif ( in_array( $current, array( ')', ']', '}' ), true ) ) {
					--$nesting;
				} elseif ( ',' === $current && 1 === $nesting ) {
					$param_start = true;
				}
			}

			// $next is just past the closing parenthesis; closures may carry use (...).
			if ( $next < $count && $tokens[ $next ]->is( T_USE ) ) {
				for ( $nesting = 0, $next++; $next < $count; $next++ ) {
					$nesting += '(' === $text( $next ) ? 1 : ( ')' === $text( $next ) ? -1 : 0 );
					if ( 0 === $nesting ) {
						++$next;
						break;
					}
				}
			}
			if ( ':' === $text( $next ) ) {
				list( $parts ) = $collect( $next + 1 );
				$check_type( $parts, $tokens[ $next ]->line );
			}
			continue;
		}

		// Builtin functions and classes newer than PHP 8.1.
		if ( $token->is( array( T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED ) ) ) {
			$name     = strtolower( ltrim( $token->text, '\\' ) );
			$previous = $i > 0 ? $tokens[ $i - 1 ] : null;
			$is_call  = '(' === $text( $i + 1 ) && ! ( $previous && $previous->is( array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST ) ) );
			if ( $is_call && in_array( $name, SRC_TEST_NEWER_FUNCTIONS, true ) ) {
				$problems[] = $line . ': ' . $name . '() does not exist before PHP 8.2';
			}
			if ( in_array( $name, SRC_TEST_NEWER_CLASSES, true ) ) {
				$problems[] = $line . ': class ' . $token->text . ' does not exist before PHP 8.2';
			}
		}
	}

	return array_values( array_unique( $problems ) );
};

// The scanner has to catch what it claims to catch, and stay quiet on valid PHP 8.1.
$must_flag = array(
	'true return type'         => 'function a(): true|WP_Error {}',
	'standalone true'          => 'function a(): true {}',
	'standalone false'         => 'function a(): false {}',
	'standalone null param'    => 'function a( null $x ) {}',
	'true parameter'           => 'function a( int $y, true|string $x = true ) {}',
	'true property'            => 'class A { private static true $x; }',
	'promoted true'            => 'class A { function __construct( private true $x ) {} }',
	'closure with use'         => '$f = function () use ( $a ): true { return true; };',
	'arrow function'           => '$f = static fn (): true => true;',
	'method named list'        => 'class A { function list(): true {} }',
	'dnf type'                 => 'function a( (A&B)|null $x ) {}',
	'readonly class'           => 'final readonly class A {}',
	'typed constant'           => 'class A { public const string B = ""; }',
	'trait constant'           => 'trait T { const X = 1; }',
	'json_validate'            => '$ok = json_validate( "{}" );',
	'fully qualified function' => '$x = \\array_find( $a, $b );',
	'randomizer'               => '$r = new \\Random\\Randomizer();',
	'dnf return type'          => 'function a(): (A&B)|null {}',
	'attribute then true'      => 'function a( #[\\SensitiveParameter] true|string $x ) {}',
	'attribute then return'    => 'function a( #[Foo( [ 1 ] )] string $p ): true {}',
	'by-reference function'    => 'function &a(): true {}',
);
foreach ( $must_flag as $label => $snippet ) {
	$assert( array() !== $scan( '<?php ' . $snippet ), 'The compatibility scanner missed: ' . $label . '.' );
}

$must_pass = array(
	'bool|WP_Error'          => 'function a(): bool|WP_Error {}',
	'false in a union'       => 'function a(): int|false {}',
	'nullable'               => 'function a( ?array $x = null, string ...$rest ): ?static {}',
	'by-reference'           => 'function a( array &$x, &...$rest ): void {}',
	'intersection type'      => 'function a( A&B $x ): A&B {}',
	'readonly property'      => 'class A { public function __construct( public readonly int $x ) {} public readonly array $y; }',
	'static variable'        => 'function a() { static $cache = array(); return static::class; }',
	'untyped constant'       => 'class A { const B = 1, C = 2; } trait T { public function c() { return 1; } }',
	'use function'           => 'use function Foo\\bar; use const Foo\\BAZ;',
	'method named like func' => '$x->json_validate(); A::array_find(); function array_is_list( array $a ): bool {}',
	'attribute on parameter' => 'function a( #[\\SensitiveParameter] string $password, #[Foo( [ 1, 2 ] )] int $b ) {}',
	'enum'                   => 'enum Suit: string { case Hearts = "H"; }',
	'default value true'     => 'function a( bool $x = true, array $y = array( true, false ) ): bool { return $x; }',
	'never'                  => 'function a(): never { exit; }',
	'empty parameter list'   => 'function a() { return ( $x ) ? new static( $y ) : static fn () => null; }',
	'call in a default'      => 'function a( array $x = array( 1 ), ?Closure $y = null ): ?array { return a(); }',
	'constant after a trait' => 'trait T { function a() {} } class B { const X = 1; }',
);
foreach ( $must_pass as $label => $snippet ) {
	$found = $scan( '<?php ' . $snippet );
	$assert( array() === $found, 'The compatibility scanner flagged valid PHP 8.1 (' . $label . '): ' . implode( '; ', $found ) );
}

// The plugin's own source.
$files    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
$scanned  = 0;
foreach ( $files as $file ) {
	$path     = $file->getPathname();
	$relative = substr( $path, strlen( $root ) + 1 );
	// Hidden directories hold VCS data or nested checkouts (.git, .claude/worktrees), not plugin source.
	if ( ! str_ends_with( $path, '.php' ) || str_starts_with( $relative, '.' ) || str_contains( $relative, '/.' ) ) {
		continue;
	}
	++$scanned;
	foreach ( $scan( (string) file_get_contents( $path ) ) as $problem ) {
		$assert( false, $relative . ':' . $problem . '.' );
	}
}
$assert( $scanned >= 30, 'The compatibility scan found suspiciously few PHP files (' . $scanned . ').' );

// Every place WordPress reads the minimum from has to agree with what this test enforces.
$header = (string) file_get_contents( $root . '/simple-redis-cache.php' );
$readme = (string) file_get_contents( $root . '/readme.txt' );
$update = (string) file_get_contents( $root . '/includes/class-simple-redis-cache-github-updater.php' );
$assert( 1 === preg_match( '/^\s*\*\s*Requires PHP:\s*8\.1\s*$/m', $header ), 'The plugin header no longer declares Requires PHP: 8.1.' );
$assert( 1 === preg_match( '/^Requires PHP:\s*8\.1\s*$/m', $readme ), 'readme.txt no longer declares Requires PHP: 8.1.' );
$assert( 2 === preg_match_all( "/'requires_php'\s*=>\s*'8\.1'/", $update ), 'The GitHub updater no longer reports requires_php 8.1 in both responses.' );

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
	}
	exit( 1 );
}

echo "OK: none of the checked PHP 8.2+ constructs or builtins are used, and every declared minimum says PHP 8.1.\n";
