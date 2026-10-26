#!/usr/bin/env node
/**
 * Wrapper wokół @wordpress/env.
 *
 * Node rozwiązuje nazwy przez c-ares (dns.resolve), który na tej maszynie
 * celuje w resolver na 127.0.0.1 odrzucający zapytania. wp-env używa
 * dns.resolve do wykrywania trybu offline i przerywa start. Wskazujemy
 * publiczne serwery DNS wyłącznie na czas działania wp-env.
 */
require( 'dns' ).setServers( [ '1.1.1.1', '8.8.8.8' ] );
require( require( 'path' ).join( __dirname, '..', 'node_modules', '@wordpress', 'env', 'bin', 'wp-env' ) );
