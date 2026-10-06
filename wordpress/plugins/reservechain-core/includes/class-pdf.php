<?php
/**
 * Minimal dependency-free PDF writer used to generate specimen / template documents during seeding.
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Pdf {

	/**
	 * Build a simple A4 PDF. $blocks: list of [style, text] where style is h1|h2|p|mono|small|rule.
	 */
	public static function build( string $title, array $blocks ): string {
		$pages   = array();
		$ops     = '';
		$y       = 800;
		$newpage = static function () use ( &$ops, &$pages, &$y ) {
			if ( '' !== $ops ) {
				$pages[] = $ops;
			}
			$ops = '';
			$y   = 800;
		};

		$header = static function () use ( &$ops, $title ) {
			$ops .= "0.043 0.059 0.078 rg 0 812 595 30 re f\n";
			$ops .= "0.769 0.416 0.227 rg 40 821 12 12 re f\n";
			$ops .= 'BT /F2 10 Tf 1 1 1 rg 60 823 Td (' . self::esc( 'RESERVECHAIN  |  ' . strtoupper( $title ) ) . ") Tj ET\n";
			$ops .= "0.55 0.59 0.64 rg BT /F1 7 Tf 40 30 Td (" . self::esc( 'Proposed - in development - subject to final approval. No tokens are offered or sold. See full disclosure.' ) . ") Tj ET\n";
		};
		$header();

		foreach ( $blocks as $b ) {
			list( $style, $text ) = $b + array( '', '' );
			$cfg = array(
				'h1'    => array( 'F2', 20, 26, 60, '0.043 0.059 0.078' ),
				'h2'    => array( 'F2', 12, 18, 95, '0.769 0.416 0.227' ),
				'p'     => array( 'F1', 10, 14, 98, '0.15 0.17 0.2' ),
				'mono'  => array( 'F3', 8, 11, 120, '0.15 0.17 0.2' ),
				'small' => array( 'F1', 8, 11, 120, '0.4 0.43 0.47' ),
				'rule'  => array( 'F1', 1, 10, 1, '0.8 0.8 0.8' ),
			)[ $style ] ?? array( 'F1', 10, 14, 98, '0 0 0' );

			if ( 'rule' === $style ) {
				$ops .= '0.85 0.85 0.85 RG 40 ' . $y . ' m 555 ' . $y . " l S\n";
				$y   -= 12;
				continue;
			}
			if ( 'h2' === $style ) {
				$y -= 6;
			}
			foreach ( explode( "\n", wordwrap( $text, $cfg[3], "\n", true ) ) as $line ) {
				if ( $y < 60 ) {
					$newpage();
					$header();
				}
				$ops .= $cfg[4] . ' rg BT /' . $cfg[0] . ' ' . $cfg[1] . ' Tf 40 ' . $y . ' Td (' . self::esc( $line ) . ") Tj ET\n";
				$y   -= $cfg[2];
			}
			$y -= 4;
		}
		$pages[] = $ops;

		$objs   = array();
		$objs[] = '<< /Type /Catalog /Pages 2 0 R >>';
		$kids   = array();
		$n      = count( $pages );
		for ( $i = 0; $i < $n; $i++ ) {
			$kids[] = ( 6 + $i * 2 ) . ' 0 R';
		}
		$objs[] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . $n . ' >>';
		$objs[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objs[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$objs[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>';
		foreach ( $pages as $i => $content ) {
			$objs[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >> >> /Contents ' . ( 7 + $i * 2 ) . ' 0 R >>';
			$objs[] = '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream";
		}

		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objs as $i => $o ) {
			$offsets[] = strlen( $pdf );
			$pdf      .= ( $i + 1 ) . " 0 obj\n" . $o . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= "xref\n0 " . ( count( $objs ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $off ) {
			$pdf .= sprintf( "%010d 00000 n \n", $off );
		}
		$pdf .= 'trailer << /Size ' . ( count( $objs ) + 1 ) . ' /Root 1 0 R /Info << /Title (' . self::esc( $title ) . ") /Producer (ReserveChain Core) >> >>\nstartxref\n" . $xref . "\n%%EOF\n";
		return $pdf;
	}

	private static function esc( string $s ): string {
		$s = str_replace( array( '—', '–', '’', '“', '”', '…', '·', '→' ), array( '-', '-', "'", '"', '"', '...', '-', '->' ), $s );
		$s = (string) iconv( 'UTF-8', 'Windows-1252//TRANSLIT', $s );
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $s );
	}
}
