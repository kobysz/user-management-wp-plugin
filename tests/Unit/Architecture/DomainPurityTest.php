<?php
/**
 * Pilnuje, by src/Domain nie dotykało WordPressa.
 *
 * Strażnik działa na allowliście, nie na liście zakazanych nazw: każda wywoływana
 * funkcja globalna musi być wbudowana w PHP albo należeć do namespace'u projektu,
 * a każda klasa i stała musi być wbudowana albo pochodzić z `PortalUsers\`.
 * Lista zakazanych nazw byłaby zawsze o jedną nazwę krótsza, niż trzeba.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

final class DomainPurityTest extends TestCase {

	private const PROJECT_NAMESPACE = 'PortalUsers\\';

	/**
	 * Nazwy z gramatyki PHP, które występują jako zwykłe `T_STRING` (typy wbudowane i literały).
	 *
	 * @var list<string>
	 */
	private const LANGUAGE_NAMES = array(
		'int',
		'float',
		'string',
		'bool',
		'array',
		'callable',
		'iterable',
		'object',
		'mixed',
		'void',
		'null',
		'never',
		'false',
		'true',
		'self',
		'parent',
		'static',
	);

	/**
	 * Wzorzec wzmianek, których w domenie nie ma być także w komentarzach.
	 */
	private const COMMENT_PATTERN = '/(?<![A-Za-z0-9_])(?:wordpress|wpdb|wp_[a-z_]+)/i';

	/**
	 * Funkcje wbudowane (małe litery), policzone raz.
	 *
	 * @var array<string, true>|null
	 */
	private static ?array $internalFunctions = null;

	/**
	 * Stałe wbudowane (bez stałych użytkownika, jak ABSPATH z bootstrapu).
	 *
	 * @var array<string, true>|null
	 */
	private static ?array $internalConstants = null;

	/**
	 * Znajduje w kodzie wszystko, co nie należy do PHP ani do projektu.
	 *
	 * @param string $src Kod PHP.
	 * @return list<array{line: int, name: string, why: string}>
	 */
	private function violations( string $src ): array {
		$all    = token_get_all( $src );
		$tokens = array();
		$found  = array();

		// Komentarze sprawdzamy osobno, resztę przechodzimy bez białych znaków i komentarzy.
		foreach ( $all as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				if ( 1 === preg_match( self::COMMENT_PATTERN, $token[1], $m ) ) {
					$found[] = array(
						'line' => $token[2],
						'name' => $m[0],
						'why'  => 'wzmianka w komentarzu',
					);
				}
				continue;
			}
			if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
				continue;
			}
			$tokens[] = is_array( $token ) ? $token : array( -1, $token, 0 );
		}

		$namespace = '';
		$imports   = array(); // Klasy: alias (małe litery) => pełna nazwa.
		$functions = array(); // Funkcje: alias (małe litery) => pełna nazwa.
		$depth     = 0;
		$count     = count( $tokens );
		$line      = 0;

		for ( $i = 0; $i < $count; $i++ ) {
			[ $id, $text, $tokenLine ] = $tokens[ $i ];
			$line                      = 0 !== $tokenLine ? $tokenLine : $line;

			if ( '{' === $text ) {
				++$depth;
				continue;
			}
			if ( '}' === $text ) {
				--$depth;
				continue;
			}

			if ( T_DECLARE === $id ) {
				while ( $i < $count && ')' !== $tokens[ $i ][1] ) {
					++$i;
				}
				continue;
			}

			if ( T_NAMESPACE === $id && isset( $tokens[ $i + 1 ] ) && $this->isName( $tokens[ $i + 1 ][0] ) ) {
				$namespace = $tokens[ $i + 1 ][1];
				++$i;
				continue;
			}

			// Import na poziomie pliku: zbieramy aliasy do końca instrukcji.
			if ( T_USE === $id && 0 === $depth ) {
				$statement = '';
				for ( ++$i; $i < $count && ';' !== $tokens[ $i ][1]; ++$i ) {
					$statement .= ' ' . $tokens[ $i ][1];
				}
				$this->collectImports( $statement, $imports, $functions );
				continue;
			}

			// Zmienna lub składowa o nazwie `wpdb`.
			if ( T_VARIABLE === $id && 1 === preg_match( '/^\$(?:wpdb|GLOBALS)$/i', $text ) ) {
				$found[] = array(
					'line' => $tokenLine,
					'name' => $text,
					'why'  => 'dostęp do bazy WordPressa',
				);
				continue;
			}
			if ( T_CONSTANT_ENCAPSED_STRING === $id && 1 === preg_match( '/^[\'"](?:ABSPATH|wpdb)[\'"]$/i', $text ) ) {
				$found[] = array(
					'line' => $tokenLine,
					'name' => $text,
					'why'  => 'literał wskazujący na WordPressa',
				);
				continue;
			}

			if ( ! $this->isName( $id ) ) {
				continue;
			}

			$prev = $this->previous( $tokens, $i );
			$next = $tokens[ $i + 1 ][1] ?? '';

			// Składowa obiektu lub klasy: nie jest nazwą globalną, ale `->wpdb` jest wyciekiem.
			if ( in_array( $prev, array( '->', '?->', '::' ), true ) ) {
				if ( 'wpdb' === strtolower( $text ) ) {
					$found[] = array(
						'line' => $tokenLine,
						'name' => $text,
						'why'  => 'składowa wpdb',
					);
				}
				continue;
			}

			// Deklaracje własnych nazw oraz argumenty nazwane.
			if ( in_array( $prev, array( 'class', 'interface', 'trait', 'enum', 'function', 'const', 'case', 'namespace' ), true ) ) {
				continue;
			}
			if ( ':' === $next && in_array( $prev, array( '(', ',' ), true ) ) {
				continue;
			}

			if ( '(' === $next && 'new' !== $prev && 'attribute' !== $prev ) {
				$why = $this->functionViolation( $text, $namespace, $imports, $functions );
			} else {
				$why = $this->symbolViolation( $text, $namespace, $imports );
			}

			if ( null !== $why ) {
				$found[] = array(
					'line' => $tokenLine,
					'name' => $text,
					'why'  => $why,
				);
			}
		}

		return $found;
	}

	/**
	 * Czy token to nazwa (zwykła, kwalifikowana, w pełni kwalifikowana lub względna).
	 *
	 * @param int $id Id tokenu.
	 */
	private function isName( int $id ): bool {
		return in_array( $id, array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE ), true );
	}

	/**
	 * Tekst poprzedniego tokenu znaczącego (małe litery dla słów kluczowych), `&` pomijany.
	 *
	 * @param list<array{0:int,1:string,2:int}> $tokens Tokeny bez białych znaków.
	 * @param int                               $index  Indeks bieżącego tokenu.
	 */
	private function previous( array $tokens, int $index ): string {
		$prev = $tokens[ $index - 1 ][1] ?? '';
		if ( '&' === $prev ) {
			$prev = $tokens[ $index - 2 ][1] ?? '';
		}
		return strtolower( $prev );
	}

	/**
	 * Parsuje instrukcję `use` z poziomu pliku.
	 *
	 * @param string                $statement Tekst instrukcji po `use`, tokeny rozdzielone spacjami.
	 * @param array<string, string> $imports   Aliasy klas (modyfikowane).
	 * @param array<string, string> $functions Aliasy funkcji (modyfikowane).
	 */
	private function collectImports( string $statement, array &$imports, array &$functions ): void {
		$statement = trim( (string) preg_replace( '/\s+/', ' ', $statement ) );
		$kind      = 'class';
		if ( 1 === preg_match( '/^(function|const)\s+(.*)$/i', $statement, $m ) ) {
			$kind      = strtolower( $m[1] );
			$statement = $m[2];
		}

		$prefix = '';
		if ( 1 === preg_match( '/^(.*?)\s*\\\\\s*\{(.*)\}$/', $statement, $m ) ) {
			$prefix    = trim( $m[1] ) . '\\';
			$statement = $m[2];
		}

		foreach ( explode( ',', $statement ) as $part ) {
			$part  = trim( $part );
			$alias = null;
			if ( 1 === preg_match( '/^(.*?)\s+as\s+(\w+)$/i', $part, $m ) ) {
				$part  = trim( $m[1] );
				$alias = $m[2];
			}
			$full = ltrim( $prefix . str_replace( ' ', '', $part ), '\\' );
			if ( '' === $full ) {
				continue;
			}
			$alias ??= substr( $full, (int) strrpos( '\\' . $full, '\\' ) );
			$key     = strtolower( $alias );
			if ( 'function' === $kind ) {
				$functions[ $key ] = $full;
			} elseif ( 'class' === $kind ) {
				$imports[ $key ] = $full;
			}
		}
	}

	/**
	 * Rozwiązuje nazwę klasy do pełnej nazwy.
	 *
	 * @param string                $name      Nazwa z kodu.
	 * @param string                $namespace Bieżący namespace.
	 * @param array<string, string> $imports   Aliasy klas.
	 */
	private function resolve( string $name, string $namespace, array $imports ): string {
		if ( '\\' === $name[0] ) {
			return substr( $name, 1 );
		}
		if ( 1 === preg_match( '/^namespace\\\\(.*)$/i', $name, $m ) ) {
			return ( '' === $namespace ? '' : $namespace . '\\' ) . $m[1];
		}
		$parts = explode( '\\', $name, 2 );
		$head  = strtolower( $parts[0] );
		if ( isset( $imports[ $head ] ) ) {
			return $imports[ $head ] . ( isset( $parts[1] ) ? '\\' . $parts[1] : '' );
		}
		return ( '' === $namespace ? '' : $namespace . '\\' ) . $name;
	}

	/**
	 * Powód odrzucenia wywołania funkcji, albo null gdy jest dozwolone.
	 *
	 * @param string                $name      Nazwa z kodu.
	 * @param string                $namespace Bieżący namespace.
	 * @param array<string, string> $imports   Aliasy klas.
	 * @param array<string, string> $functions Aliasy funkcji.
	 */
	private function functionViolation( string $name, string $namespace, array $imports, array $functions ): ?string {
		$unqualified = false === strpos( $name, '\\' );
		if ( $unqualified && isset( $functions[ strtolower( $name ) ] ) ) {
			$full = $functions[ strtolower( $name ) ];
		} elseif ( '\\' === $name[0] || ! $unqualified ) {
			$full = $this->resolve( $name, $namespace, $imports );
		} else {
			// Nazwa niekwalifikowana: PHP najpierw szuka w namespace'ie, potem globalnie.
			$local = ( '' === $namespace ? '' : $namespace . '\\' ) . $name;
			if ( '' !== $namespace && function_exists( $local ) && $this->isProject( $local ) ) {
				return null;
			}
			$full = $name;
		}

		if ( false === strpos( $full, '\\' ) ) {
			return isset( $this->internalFunctions()[ strtolower( $full ) ] ) ? null : 'funkcja spoza PHP i projektu';
		}
		if ( $this->isProject( $full ) && function_exists( $full ) ) {
			return null;
		}
		return 'funkcja spoza PHP i projektu';
	}

	/**
	 * Powód odrzucenia nazwy klasy lub stałej, albo null gdy jest dozwolona.
	 *
	 * @param string                $name      Nazwa z kodu.
	 * @param string                $namespace Bieżący namespace.
	 * @param array<string, string> $imports   Aliasy klas.
	 */
	private function symbolViolation( string $name, string $namespace, array $imports ): ?string {
		if ( in_array( strtolower( $name ), self::LANGUAGE_NAMES, true ) ) {
			return null;
		}

		$full = $this->resolve( $name, $namespace, $imports );

		if ( $this->isProject( $full ) ) {
			return $this->projectSymbolExists( $full ) ? null : 'nieistniejąca nazwa z namespace\'u projektu';
		}
		if ( false === strpos( $full, '\\' ) ) {
			if ( $this->isInternalClass( $full ) || isset( $this->internalConstants()[ $full ] ) ) {
				return null;
			}
			// Nazwa niekwalifikowana w namespace'ie, której nie ma ani w PHP, ani w imporcie.
			return 'klasa lub stała spoza PHP i projektu';
		}
		return 'klasa lub stała spoza PHP i projektu';
	}

	/**
	 * Czy pełna nazwa leży w namespace'ie projektu.
	 *
	 * @param string $full Pełna nazwa bez wiodącego ukośnika.
	 */
	private function isProject( string $full ): bool {
		return 0 === strpos( $full, self::PROJECT_NAMESPACE );
	}

	/**
	 * Czy klasa, interfejs lub enum projektu istnieje (z autoloadem).
	 *
	 * @param string $full Pełna nazwa.
	 */
	private function projectSymbolExists( string $full ): bool {
		return class_exists( $full ) || interface_exists( $full ) || enum_exists( $full ) || trait_exists( $full );
	}

	/**
	 * Czy to klasa, interfejs lub enum wbudowany w PHP.
	 *
	 * @param string $name Nazwa globalna.
	 */
	private function isInternalClass( string $name ): bool {
		if ( ! class_exists( $name, false ) && ! interface_exists( $name, false ) && ! enum_exists( $name, false ) ) {
			return false;
		}
		return ( new \ReflectionClass( $name ) )->isInternal();
	}

	/**
	 * @return array<string, true>
	 */
	private function internalFunctions(): array {
		if ( null === self::$internalFunctions ) {
			self::$internalFunctions = array_fill_keys( get_defined_functions()['internal'], true );
		}
		return self::$internalFunctions;
	}

	/**
	 * @return array<string, true>
	 */
	private function internalConstants(): array {
		if ( null === self::$internalConstants ) {
			$byCategory = get_defined_constants( true );
			unset( $byCategory['user'] );
			$names = array();
			foreach ( $byCategory as $constants ) {
				$names = array_merge( $names, array_keys( $constants ) );
			}
			self::$internalConstants = array_fill_keys( $names, true );
		}
		return self::$internalConstants;
	}

	/**
	 * @return list<string>
	 */
	private function domainFiles(): array {
		$dir   = dirname( __DIR__, 3 ) . '/src/Domain';
		$files = array();
		if ( ! is_dir( $dir ) ) {
			return $files;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
		sort( $files );
		return $files;
	}

	public function test_domain_uses_only_php_and_project_names(): void {
		$files = $this->domainFiles();
		$this->assertNotEmpty( $files, 'src/Domain nie zawiera plików PHP: strażnik niczego by nie sprawdził.' );

		$report = array();
		foreach ( $files as $file ) {
			foreach ( $this->violations( (string) file_get_contents( $file ) ) as $v ) {
				$report[] = sprintf( '%s:%d  `%s`  (%s)', substr( $file, (int) strpos( $file, 'src' ) ), $v['line'], $v['name'], $v['why'] );
			}
		}
		$this->assertSame( array(), $report, "Domena sięga poza PHP i projekt:\n" . implode( "\n", $report ) );
	}

	public function test_domain_files_carry_no_abspath_guard(): void {
		foreach ( $this->domainFiles() as $file ) {
			$this->assertStringNotContainsString( 'ABSPATH', (string) file_get_contents( $file ), basename( $file ) );
		}
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function leakProvider(): array {
		return array(
			'WP_Error w namespace'     => array( 'namespace PortalUsers\Domain; return new WP_Error( "x" );', 'WP_Error' ),
			'WP_Error globalny'        => array( 'return new \WP_Error( "x" );', '\WP_Error' ),
			'WP_Error małymi'          => array( '$q = new wp_query();', 'wp_query' ),
			'typ WP_REST_Request'      => array( 'function f( \WP_REST_Request $r ) {}', '\WP_REST_Request' ),
			'get_transient'            => array( '$v = get_transient( "k" );', 'get_transient' ),
			'set_transient'            => array( 'set_transient( "k", 1, 60 );', 'set_transient' ),
			'current_user_can'         => array( 'if ( current_user_can( "edit_posts" ) ) {}', 'current_user_can' ),
			'sanitize_text_field'      => array( '$x = sanitize_text_field( $v );', 'sanitize_text_field' ),
			'tłumaczenie __()'         => array( '$s = __( "tekst", "portal-users" );', '__' ),
			'tłumaczenie _e()'         => array( '_e( "x" );', '_e' ),
			'wp_ przez ukośnik'        => array( '$u = \wp_get_current_user();', '\wp_get_current_user' ),
			'stała ABSPATH'            => array( 'if ( ! defined( "ABSPATH" ) ) {} $p = ABSPATH;', 'ABSPATH' ),
			'global $wpdb'             => array( 'global $wpdb;', '$wpdb' ),
			'$this->wpdb'              => array( '$this->wpdb->query( "x" );', 'wpdb' ),
			'klasa spoza projektu'     => array( 'namespace PortalUsers\Domain; $x = Foo\Bar::baz();', 'Foo\Bar' ),
			'import spoza projektu'    => array( 'namespace PortalUsers\Domain; use Vendor\Thing; $x = new Thing();', 'Thing' ),
			'komentarz o WordPressie'  => array( "// to woła WordPressa\n\$a = 1;", 'WordPress' ),
			'komentarz o wp_ funkcji'  => array( "/** Używa wp_insert_post(). */\n\$a = 1;", 'wp_insert_post' ),
		);
	}

	/**
	 * @dataProvider leakProvider
	 */
	public function test_detector_catches_leak( string $code, string $expectedName ): void {
		$names = array_column( $this->violations( '<?php ' . $code ), 'name' );
		$this->assertNotEmpty( $names, 'Nie wykryto wycieku: ' . $code );
		$this->assertContains( $expectedName, $names, 'Wykryto, ale pod inną nazwą: ' . $code );
	}

	public function test_detector_catches_all_four_leaks_in_one_fragment(): void {
		$code = '<?php return array( new WP_Error( "x" ), get_transient( "k" ), current_user_can( "x" ), $GLOBALS );'
			. ' global $wpdb;';
		$names = array_column( $this->violations( $code ), 'name' );
		foreach ( array( 'WP_Error', 'get_transient', 'current_user_can', '$wpdb' ) as $expected ) {
			$this->assertContains( $expected, $names );
		}
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function innocentProvider(): array {
		return array(
			'preg_replace'              => array( '$a = preg_replace( "/x/", "y", $s );' ),
			'strtoupper'                => array( '$a = strtoupper( $s );' ),
			'konstruktor'               => array( 'final class A { public function __construct() { parent::__construct(); } }' ),
			'podobne nazwy'             => array( '$this->swp_value = 1; $my_escape_hatch = 2; $optionsCount = 3; $x = $this->get_options_list();' ),
			'metoda na obiekcie'        => array( '$m = new \PortalUsers\Domain\Fields\FieldMatrix( array() ); $m->requiredFields( "lekarz" );' ),
			'klasa projektu przez use'  => array( 'namespace PortalUsers\Domain\Account; use PortalUsers\Domain\Credentials\Normalizer; $n = Normalizer::clean( $r );' ),
			'klasa projektu względna'   => array( 'namespace PortalUsers\Domain; $n = Credentials\Normalizer::clean( $r );' ),
			'wbudowana klasa i wyjątek' => array( 'namespace PortalUsers\Domain; use InvalidArgumentException; throw new InvalidArgumentException( "x" );' ),
			'wbudowana globalna'        => array( 'namespace PortalUsers\Domain; $d = new \DateTimeImmutable( "now" ); $c = \count( array() );' ),
			'stałe i typy'              => array( 'function f( ?string $s, int|float $n = PHP_INT_MAX ): bool { return true; }' ),
			'argument nazwany'          => array( '$a = in_array( needle: 1, haystack: array( 1 ), strict: true );' ),
			'enum projektu'             => array( 'namespace PortalUsers\Domain; $c = \PortalUsers\Domain\Credentials\CredentialType::None;' ),
			'declare'                   => array( 'declare( strict_types=1 );' ),
			'komentarz bez wycieku'     => array( "// numer uprawnień, bez zależności od frameworka\n\$a = 1;" ),
		);
	}

	/**
	 * @dataProvider innocentProvider
	 */
	public function test_detector_ignores_innocent_code( string $code ): void {
		$this->assertSame( array(), $this->violations( '<?php ' . $code ), 'Fałszywe trafienie: ' . $code );
	}
}
