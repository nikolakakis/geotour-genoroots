<?php
/**
 * GenoRoots_Gedcom_Parser
 *
 * Deliberately has zero WordPress dependencies so it can be sanity-checked
 * standalone with plain `php`, independent of the WordPress bootstrap.
 *
 * Reads, per record:
 *  - INDI: NAME (with NICK and _PATR, the father's name), SEX,
 *    BIRT/DEAT/BURI (DATE, PLAC), NOTE, SOUR (as text),
 *    FAMC/FAMS, and GenoRoots' extension tags _PERIOD, _GROUP (repeatable),
 *    _ARTICLE (an address on the importing site).
 *  - FAM: HUSB/WIFE/CHIL, MARR (DATE, PLAC), NOTE, and _STATUS
 *    (confirmed | probable | claimed).
 * NOTE and SOUR text may continue on CONT (new line) / CONC (same line).
 * Extension tags start with "_" as GEDCOM requires; other programs ignore them.
 */

class GenoRoots_Gedcom_Parser {

	/**
	 * @param string $path Path to a .ged file.
	 * @return array{individuals: array<string, array>, families: array<string, array>, skipped: array<int, array{line: int, content: string}>}
	 */
	public function parse_file( $path ) {
		$lines = file( $path, FILE_IGNORE_NEW_LINES );
		if ( $lines === false ) {
			throw new RuntimeException( "Unable to read GEDCOM file: {$path}" );
		}
		// A UTF-8 byte-order mark would hide the first "0 HEAD".
		if ( isset( $lines[0] ) ) {
			$lines[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $lines[0] );
		}
		return $this->parse_lines( $lines );
	}

	/**
	 * @param string[] $lines
	 * @return array{individuals: array<string, array>, families: array<string, array>, skipped: array<int, array{line: int, content: string}>}
	 */
	public function parse_lines( array $lines ) {
		$individuals = array();
		$families    = array();
		$skipped     = array();

		$current_type   = null; // 'INDI' or 'FAM', null while inside HEAD/SUBM/TRLR
		$current_id     = null;
		$current_record = array();
		// Tracks the most recent tag seen at each level, so a level-2 line
		// (TYPE/DATE/PLAC/CONT) can tell which level-1 block it belongs to.
		$level_stack = array();

		$flush = function () use ( &$current_type, &$current_id, &$current_record, &$individuals, &$families ) {
			if ( $current_id === null ) {
				return;
			}
			if ( $current_type === 'INDI' ) {
				$individuals[ $current_id ] = $current_record;
			} elseif ( $current_type === 'FAM' ) {
				$families[ $current_id ] = $current_record;
			}
		};

		foreach ( $lines as $line_index => $raw_line ) {
			$line = trim( $raw_line );
			if ( $line === '' ) {
				continue;
			}

			// A GEDCOM line is: LEVEL [XREF] TAG [VALUE]
			if ( ! preg_match( '/^(\d+)\s+(@[^@\s]+@\s+)?(\S+)(?:\s(.*))?$/u', $line, $m ) ) {
				$skipped[] = array(
					'line'    => $line_index + 1,
					'content' => $raw_line,
				);
				continue;
			}

			$level = (int) $m[1];
			$xref  = isset( $m[2] ) ? trim( $m[2], " \t@" ) : null;
			$tag   = $m[3];
			$value = isset( $m[4] ) ? $m[4] : '';

			if ( $level === 0 ) {
				$flush();
				$current_record = array();
				$level_stack     = array();

				if ( $tag === 'INDI' ) {
					$current_type = 'INDI';
					$current_id   = $xref;
				} elseif ( $tag === 'FAM' ) {
					$current_type = 'FAM';
					$current_id   = $xref;
				} else {
					// HEAD, SUBM, TRLR, or anything else — not genealogy data.
					$current_type = null;
					$current_id   = null;
				}
				continue;
			}

			if ( $current_type === null ) {
				continue; // inside a block we don't care about (e.g. HEAD)
			}

			$level_stack[ $level ] = $tag;
			foreach ( array_keys( $level_stack ) as $stack_level ) {
				if ( $stack_level > $level ) {
					unset( $level_stack[ $stack_level ] );
				}
			}

			$this->apply_tag( $current_record, $level, $tag, $value, $level_stack );
		}
		$flush();

		return array(
			'individuals' => $individuals,
			'families'    => $families,
			'skipped'     => $skipped,
		);
	}

	/**
	 * @param array<string, mixed> $record
	 * @param array<int, string>   $level_stack
	 */
	private function apply_tag( array &$record, $level, $tag, $value, array $level_stack ) {
		// Continuation text keeps its spacing (CONC joins mid-word); other values are trimmed.
		$text  = rtrim( $value, "\r\n" );
		$value = trim( $value );

		if ( $level === 1 ) {
			switch ( $tag ) {
				case 'NAME':
					// The first NAME is the person's name; later ones are alternatives.
					// NICK / _PATR below it are read for the first NAME only.
					$record['in_first_name'] = ! isset( $record['name'] );
					if ( ! isset( $record['name'] ) ) {
						$record['name'] = $value;
					}
					break;
				case 'SEX':
					$record['sex'] = $value;
					break;
				case 'BIRT':
				case 'DEAT':
				case 'BURI':
				case 'MARR':
					$key = strtolower( $tag );
					$record[ $key ] = isset( $record[ $key ] ) ? $record[ $key ] : array( 'date' => '', 'place' => '' );
					break;
				case 'NOTE':
					// Several NOTEs become paragraphs of one note.
					$record['notes'][] = $text;
					break;
				case 'SOUR':
					// Only inline citations; pointers to SOUR records (@S1@) aren't followed.
					if ( $value !== '' && $value[0] !== '@' ) {
						$record['sources'][] = $text;
					}
					break;
				case '_PERIOD':
					$record['period'] = $value;
					break;
				case '_GROUP':
					if ( $value !== '' ) {
						$record['groups'][] = $value;
					}
					break;
				case '_ARTICLE':
					$record['article'] = $value;
					break;
				case '_STATUS':
					$record['status'] = strtolower( $value );
					break;
				case 'FAMC':
					$record['famc'][] = trim( $value, '@' );
					break;
				case 'FAMS':
					$record['fams'][] = trim( $value, '@' );
					break;
				case 'HUSB':
					if ( $value !== '' ) {
						$record['husb'] = trim( $value, '@' );
					}
					break;
				case 'WIFE':
					if ( $value !== '' ) {
						$record['wife'] = trim( $value, '@' );
					}
					break;
				case 'CHIL':
					$record['chil'][] = trim( $value, '@' );
					break;
				default:
					// Unrecognized level-1 tag (e.g. a stray malformed export
					// artifact) — ignore rather than fail.
					break;
			}
			return;
		}

		if ( $level === 2 ) {
			$parent = isset( $level_stack[1] ) ? $level_stack[1] : null;

			if ( in_array( $parent, array( 'BIRT', 'DEAT', 'BURI', 'MARR' ), true ) ) {
				$key = strtolower( $parent );
				if ( ! isset( $record[ $key ] ) ) {
					$record[ $key ] = array( 'date' => '', 'place' => '' );
				}
				if ( $tag === 'DATE' && $value !== '' ) {
					$record[ $key ]['date'] = $value;
				} elseif ( $tag === 'PLAC' && $value !== '' ) {
					$record[ $key ]['place'] = $value;
				}
				// TYPE under these blocks duplicates the level-1 tag itself; ignore.
				return;
			}

			if ( $parent === 'NAME' && ! empty( $record['in_first_name'] ) && $value !== '' ) {
				if ( $tag === 'NICK' ) {
					$record['nickname'] = $value;
				} elseif ( $tag === '_PATR' ) {
					$record['father_name'] = $value;
				}
				return;
			}

			// Continuation of the last NOTE / SOUR.
			$list = array( 'NOTE' => 'notes', 'SOUR' => 'sources' )[ $parent ] ?? null;
			if ( $list && ( $tag === 'CONT' || $tag === 'CONC' ) && ! empty( $record[ $list ] ) ) {
				$last = count( $record[ $list ] ) - 1;
				$record[ $list ][ $last ] .= ( $tag === 'CONT' ? "\n" : '' ) . $text;
			}
		}

		// Deeper levels aren't relevant to person/family data.
	}
}
