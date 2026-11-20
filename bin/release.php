<?php
/**
 * Build the plugin ZIP and publish it as a GitHub release
 *
 * composer zip       Builds build/twee-cache-{version}.zip from the plugin/ directory, with the uncommitted changes
 * composer release   Builds the ZIP and publishes release v{version} with its section of CHANGELOG.md as the notes
 */

chdir(dirname(__DIR__));


/**
 * Stop with a message
 *
 * @param string $message
 */
function release_fail(string $message): void
{
	fwrite(STDERR, $message . PHP_EOL);
	exit(1);
}


/**
 * Run a command and stop when it fails
 *
 * @param string $command
 *
 * @return string Output
 */
function release_run(string $command): string
{
	exec($command . ' 2>&1', $output, $code);

	if ($code !== 0) {
		release_fail("Failed: $command\n" . implode(PHP_EOL, $output));
	}

	return trim(implode("\n", $output));
}


// Sites compare the plugin header with the release tag
preg_match('/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents('plugin/twee-cache.php'), $header);
$version = $header[1] ?? '';

if (!$version) {
	release_fail('plugin/twee-cache.php has no Version header.');
}

$tag = 'v' . $version;
$zip = "build/twee-cache-$version.zip";
$publish = ($argv[1] ?? '') === 'publish';
$notes = 'build/notes.md';

// The ZIP and the tag come from HEAD, so it must hold every change and already be on GitHub
if ($publish) {
	// The release notes are the section of the version in CHANGELOG.md
	preg_match('/^## ' . preg_quote($version, '/') . '\s*$(.*?)(?=^## |\z)/ms', (string) @file_get_contents('CHANGELOG.md'), $section);
	$changes = trim($section[1] ?? '');

	if ($changes === '') {
		release_fail("Describe version $version under \"## $version\" in CHANGELOG.md first.");
	}

	exec('gh --version 2>&1', $output, $code);

	if ($code !== 0) {
		release_fail('Install the GitHub CLI from https://cli.github.com and run "gh auth login".');
	}

	if (release_run('git status --porcelain') !== '') {
		release_fail('Commit the changes first.');
	}

	release_run('git fetch --quiet --tags origin');

	if (release_run('git branch -r --contains HEAD') === '') {
		release_fail('Push HEAD first.');
	}

	if (release_run('git tag --list ' . $tag) !== '') {
		release_fail("Release $tag already exists. Change the Version header in plugin/twee-cache.php first.");
	}
}

// The prefix makes WordPress install and update the plugin in its twee-cache folder
if (!is_dir('build')) {
	mkdir('build');
}

// A local build takes the uncommitted changes of the tracked files too. "git stash create" makes a commit of them and does not change the files.
$tree = $publish ? 'HEAD' : (release_run('git stash create') ?: 'HEAD');

release_run("git archive --format=zip --prefix=twee-cache/ -o $zip $tree:plugin");
echo "Built $zip from " . ($tree === 'HEAD' ? 'HEAD' : 'the working tree') . '.' . PHP_EOL;

if ($publish) {
	file_put_contents($notes, $changes . "\n");
	echo release_run(sprintf('gh release create %s %s --title %s --target %s --notes-file %s --latest', $tag, $zip, $tag, release_run('git rev-parse HEAD'), $notes)) . PHP_EOL;
}
