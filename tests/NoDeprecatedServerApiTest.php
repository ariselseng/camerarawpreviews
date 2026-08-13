<?php

namespace OCA\CameraRawPreviews\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guard against the recurring "undefined method OC\Server::getX()" crashes.
 *
 * Nextcloud has been removing the legacy `\OC::$server->getFoo()` service
 * getters. Two of them broke this app on NC34 in quick succession
 * (getMimeTypeDetector, getTempManager), each only surfacing at runtime for
 * some users. This static scan fails the moment such a call is reintroduced,
 * so it is caught in CI instead of in a user's logs. Use
 * `\OCP\Server::get(Foo::class)` (available since NC25) instead.
 */
class NoDeprecatedServerApiTest extends TestCase
{
    /** Legacy service-locator patterns removed/deprecated in modern Nextcloud. */
    private const FORBIDDEN = [
        '\OC::$server->',        // e.g. \OC::$server->getTempManager()
        '->getServer()->get',    // e.g. $this->getContainer()->getServer()->getX()
    ];

    public function testNoDeprecatedServerApiInLib(): void
    {
        $offenders = [];

        foreach ($this->phpFiles(dirname(__DIR__) . '/lib') as $file) {
            $contents = file_get_contents($file);
            foreach (self::FORBIDDEN as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = basename($file) . ' contains "' . $needle . '"';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Use \\OCP\\Server::get(Foo::class) instead of legacy service getters:\n"
                . implode("\n", $offenders)
        );
    }

    /**
     * @return iterable<string>
     */
    private function phpFiles(string $dir): iterable
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }
}
