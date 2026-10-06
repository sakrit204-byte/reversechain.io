<?php
/**
 * Minimal secp256k1 public-key recovery (ECDSA "ecrecover") in pure PHP using BCMath.
 *
 * Used only to VERIFY wallet-ownership signatures (EIP-191 personal_sign). No private keys are ever handled,
 * so constant-time behaviour is not required. Jacobian coordinates + Shamir's trick keep a recovery at a few
 * thousand BCMath operations (~tens of milliseconds).
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Secp256k1 {

	private const P_HEX  = 'fffffffffffffffffffffffffffffffffffffffffffffffffffffffefffffc2f';
	private const N_HEX  = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';
	private const GX_HEX = '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';
	private const GY_HEX = '483ada7726a3c4655da4fbfc0e1108a8fd17b448a68554199c47d08ffb10d4b8';

	private static string $p;
	private static string $n;
	private static bool $ready = false;

	public static function available(): bool {
		return function_exists( 'bcmul' ) && function_exists( 'bcpowmod' );
	}

	private static function setup(): void {
		if ( self::$ready ) {
			return;
		}
		self::$p     = self::hexdec( self::P_HEX );
		self::$n     = self::hexdec( self::N_HEX );
		self::$ready = true;
	}

	public static function hexdec( string $hex ): string {
		$dec = '0';
		$hex = ltrim( strtolower( $hex ), '0' );
		foreach ( str_split( $hex, 7 ) as $chunk ) {
			$dec = bcadd( bcmul( $dec, bcpow( '16', (string) strlen( $chunk ), 0 ), 0 ), (string) hexdec( $chunk ), 0 );
		}
		return $dec;
	}

	public static function dechex( string $dec, int $pad = 64 ): string {
		$hex = '';
		while ( 1 === bccomp( $dec, '0', 0 ) ) {
			$hex = str_pad( dechex( (int) bcmod( $dec, '268435456', 0 ) ), 7, '0', STR_PAD_LEFT ) . $hex;
			$dec = bcdiv( $dec, '268435456', 0 );
		}
		$hex = ltrim( $hex, '0' );
		return str_pad( $hex, $pad, '0', STR_PAD_LEFT );
	}

	private static function m( string $a, ?string $mod = null ): string {
		$mod = $mod ?? self::$p;
		$r   = bcmod( $a, $mod, 0 );
		return '-' === $r[0] ? bcadd( $r, $mod, 0 ) : $r;
	}

	private static function mul( string $a, string $b ): string {
		return self::m( bcmul( $a, $b, 0 ) );
	}

	private static function inv( string $a, string $mod ): string {
		return bcpowmod( self::m( $a, $mod ), bcsub( $mod, '2', 0 ), $mod, 0 );
	}

	/** Jacobian doubling (curve a = 0). Point = [X, Y, Z]; Z = '0' is infinity. */
	private static function dbl( array $pt ): array {
		[ $x, $y, $z ] = $pt;
		if ( '0' === $z || '0' === $y ) {
			return array( '0', '1', '0' );
		}
		$yy = self::mul( $y, $y );
		$s  = self::mul( bcmul( '4', $x, 0 ), $yy );
		$mm = self::mul( '3', self::mul( $x, $x ) );
		$x3 = self::m( bcsub( self::mul( $mm, $mm ), bcmul( '2', $s, 0 ), 0 ) );
		$y3 = self::m( bcsub( self::mul( $mm, self::m( bcsub( $s, $x3, 0 ) ) ), bcmul( '8', self::mul( $yy, $yy ), 0 ), 0 ) );
		$z3 = self::mul( bcmul( '2', $y, 0 ), $z );
		return array( $x3, $y3, $z3 );
	}

	private static function add( array $a, array $b ): array {
		if ( '0' === $a[2] ) {
			return $b;
		}
		if ( '0' === $b[2] ) {
			return $a;
		}
		$z1z1 = self::mul( $a[2], $a[2] );
		$z2z2 = self::mul( $b[2], $b[2] );
		$u1   = self::mul( $a[0], $z2z2 );
		$u2   = self::mul( $b[0], $z1z1 );
		$s1   = self::mul( $a[1], self::mul( $b[2], $z2z2 ) );
		$s2   = self::mul( $b[1], self::mul( $a[2], $z1z1 ) );
		if ( 0 === bccomp( $u1, $u2, 0 ) ) {
			return 0 === bccomp( $s1, $s2, 0 ) ? self::dbl( $a ) : array( '0', '1', '0' );
		}
		$h   = self::m( bcsub( $u2, $u1, 0 ) );
		$r   = self::m( bcsub( $s2, $s1, 0 ) );
		$hh  = self::mul( $h, $h );
		$hhh = self::mul( $h, $hh );
		$v   = self::mul( $u1, $hh );
		$x3  = self::m( bcsub( bcsub( self::mul( $r, $r ), $hhh, 0 ), bcmul( '2', $v, 0 ), 0 ) );
		$y3  = self::m( bcsub( self::mul( $r, self::m( bcsub( $v, $x3, 0 ) ) ), self::mul( $s1, $hhh ), 0 ) );
		$z3  = self::mul( $h, self::mul( $a[2], $b[2] ) );
		return array( $x3, $y3, $z3 );
	}

	private static function bits( string $dec ): string {
		$bin = '';
		foreach ( str_split( self::dechex( $dec ) ) as $h ) {
			$bin .= str_pad( decbin( hexdec( $h ) ), 4, '0', STR_PAD_LEFT );
		}
		return $bin;
	}

	/**
	 * Recover the uncompressed public key (64 raw bytes X||Y) from a 32-byte digest and a 65-byte r||s||v signature.
	 *
	 * @return string|null Raw 64-byte public key or null if the signature is invalid.
	 */
	public static function recover( string $digest, string $sig ): ?string {
		if ( ! self::available() || 32 !== strlen( $digest ) || 65 !== strlen( $sig ) ) {
			return null;
		}
		self::setup();
		$p = self::$p;
		$n = self::$n;
		$r = self::hexdec( bin2hex( substr( $sig, 0, 32 ) ) );
		$s = self::hexdec( bin2hex( substr( $sig, 32, 32 ) ) );
		$v = ord( $sig[64] );
		$v = $v >= 27 ? $v - 27 : $v;
		if ( $v > 1 || 1 !== bccomp( $r, '0', 0 ) || 1 !== bccomp( $s, '0', 0 ) || -1 !== bccomp( $r, $n, 0 ) || -1 !== bccomp( $s, $n, 0 ) ) {
			return null;
		}
		// R = (r, y) with y parity = v. (r + n ≥ p is astronomically unlikely and is rejected by not handling it.)
		$alpha = self::m( bcadd( bcpowmod( $r, '3', $p, 0 ), '7', 0 ) );
		$y     = bcpowmod( $alpha, bcdiv( bcadd( $p, '1', 0 ), '4', 0 ), $p, 0 );
		if ( 0 !== bccomp( self::mul( $y, $y ), $alpha, 0 ) ) {
			return null; // r is not an x-coordinate on the curve.
		}
		if ( (int) bcmod( $y, '2', 0 ) !== $v ) {
			$y = bcsub( $p, $y, 0 );
		}
		$e    = self::m( self::hexdec( bin2hex( $digest ) ), $n );
		$rinv = self::inv( $r, $n );
		$u1   = self::m( bcmul( bcsub( $n, $e, 0 ), $rinv, 0 ), $n ); // -e / r.
		$u2   = self::m( bcmul( $s, $rinv, 0 ), $n );                // s / r.

		$g  = array( self::hexdec( self::GX_HEX ), self::hexdec( self::GY_HEX ), '1' );
		$rp = array( $r, $y, '1' );
		$gr = self::add( $g, $rp );
		$b1 = self::bits( $u1 );
		$b2 = self::bits( $u2 );
		$q  = array( '0', '1', '0' );
		for ( $i = 0; $i < 256; $i++ ) {
			$q  = self::dbl( $q );
			$k1 = '1' === $b1[ $i ];
			$k2 = '1' === $b2[ $i ];
			if ( $k1 && $k2 ) {
				$q = self::add( $q, $gr );
			} elseif ( $k1 ) {
				$q = self::add( $q, $g );
			} elseif ( $k2 ) {
				$q = self::add( $q, $rp );
			}
		}
		if ( '0' === $q[2] ) {
			return null;
		}
		$zi  = self::inv( $q[2], $p );
		$zi2 = self::mul( $zi, $zi );
		$x   = self::mul( $q[0], $zi2 );
		$yy  = self::mul( $q[1], self::mul( $zi2, $zi ) );
		return hex2bin( self::dechex( $x ) . self::dechex( $yy ) );
	}
}
