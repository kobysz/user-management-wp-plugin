<?php
/**
 * Bootstrap dla testów jednostkowych — bez WordPressa.
 */

declare( strict_types=1 );

// Pliki warstwy WordPressa zaczynają się od guardu `defined( 'ABSPATH' ) || exit;`.
// Testy jednostkowe ładują je przez goły autoloader, więc stała musi istnieć.
defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
