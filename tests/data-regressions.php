<?php

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

function add_action( ...$arguments ) {}
function add_filter( ...$arguments ) {}

require dirname( __DIR__ ) . '/includes/data.php';

$cases = array(
	array( 0, 0 ),
	array( 100, 100 ),
	array( 49.4, 49 ),
	array( 49.5, 50 ),
	array( '12.6', 13 ),
	array( -1, 0 ),
	array( 101, 100 ),
	array( PHP_INT_MAX, 100 ),
	array( PHP_FLOAT_MAX, 100 ),
	array( -PHP_FLOAT_MAX, 0 ),
	array( '1.7976931348623157e308', 100 ),
	array( INF, 50 ),
	array( -INF, 50 ),
	array( NAN, 50 ),
	array( '1e309', 50 ),
	array( '', 50 ),
	array( 'invalid', 50 ),
	array( null, 50 ),
	array( true, 50 ),
	array( array( 20 ), 50 ),
);

foreach ( $cases as $index => list( $input, $expected ) ) {
	$actual = corsivo_focal_point_sanitize_coordinate( $input );
	if ( $actual !== $expected ) {
		throw new RuntimeException( sprintf( 'Coordinate case %d: expected %d, got %s.', $index, $expected, var_export( $actual, true ) ) );
	}
}

echo 'Corsivo Focal Point data regressions: ' . count( $cases ) . " assertions passed.\n";
