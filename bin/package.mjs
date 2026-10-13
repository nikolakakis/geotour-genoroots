// Stages the plugin's runtime files for upload to a live site:
//   dist/geotour-genoroots/      (upload this folder over SFTP)
//   dist/geotour-genoroots.zip   (or: Plugins → Add New → Upload)
// Run through `npm run package`, which builds first.
import { cpSync, existsSync, mkdirSync, rmSync, statSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

const root = path.resolve( path.dirname( new URL( import.meta.url ).pathname.replace( /^\/([A-Za-z]:)/, '$1' ) ), '..' );
const name = 'geotour-genoroots';
const dist = path.join( root, 'dist' );
const stage = path.join( dist, name );

// Everything the plugin needs at runtime, nothing it doesn't
// (no node_modules, src, tests, build tooling or GEDCOM fixtures).
const runtime = [ 'geotour-genoroots.php', 'README.md', 'readme.txt', 'CHANGELOG.md', 'LICENSE', 'includes', 'templates', 'languages', 'build' ];

if ( ! existsSync( path.join( root, 'build', '.vite', 'manifest.json' ) ) ) {
	console.error( 'build/ is missing: run `npm run build` first.' );
	process.exit( 1 );
}

rmSync( dist, { recursive: true, force: true } );
mkdirSync( stage, { recursive: true } );
for ( const entry of runtime ) {
	const from = path.join( root, entry );
	if ( existsSync( from ) ) {
		cpSync( from, path.join( stage, entry ), { recursive: true } );
	}
}

// bsdtar writes zips with -a. Windows 10+ ships it as System32\tar.exe; call
// it by path so Git Bash's GNU tar (which can't) isn't picked up instead.
const tar = process.platform === 'win32'
	? path.join( process.env.SystemRoot || 'C:\\Windows', 'System32', 'tar.exe' )
	: 'tar';
const zip = path.join( dist, `${ name }.zip` );
execFileSync( tar, [ '-a', '-c', '-f', zip, name ], { cwd: dist, stdio: 'inherit' } );

console.log( `Staged ${ stage }` );
console.log( `Zipped ${ zip } (${ Math.round( statSync( zip ).size / 1024 ) } KB)` );
