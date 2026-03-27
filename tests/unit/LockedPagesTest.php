<?php

namespace TearoomOne\ContentWatch\Tests\Unit;

use TearoomOne\ContentWatch\LockedPages;
use TearoomOne\ContentWatch\Tests\TestCase;

class LockedPagesTest extends TestCase
{
    // -------------------------------------------------------------------------
    // V4 lock file parsing
    // -------------------------------------------------------------------------

    public function testV4LockFileIsParsed(): void
    {
        $lockDir  = $this->contentDir . '/1_about';
        mkdir($lockDir, 0777, true);
        file_put_contents($lockDir . '/about.txt', "Title: About\n");
        $lockTime = time() - 60;
        file_put_contents($lockDir . '/.lock', "user: kirby\ntime: $lockTime\n");

        $results = (new LockedPages())->getLockedPagesV4($this->contentDir, []);

        $this->assertCount(1, $results);
        $this->assertSame($lockTime, $results[0]['time']);
        $this->assertNotEmpty($results[0]['user']);
        $this->assertSame('listed', $results[0]['page_status']);
        $this->assertNotEmpty($results[0]['panel_url']);
    }

    public function testV4LockIdHasNoOrderNumber(): void
    {
        $lockDir  = $this->contentDir . '/1_about';
        mkdir($lockDir, 0777, true);
        file_put_contents($lockDir . '/.lock', "user: kirby\ntime: " . time() . "\n");

        $results = (new LockedPages())->getLockedPagesV4($this->contentDir, []);

        // id is the order-number-stripped version
        $this->assertStringNotContainsString('1_', $results[0]['id']);
        $this->assertSame('about', $results[0]['id']);
    }

    public function testV4MissingUserFallsBackToUserId(): void
    {
        $lockDir = $this->contentDir . '/1_about';
        mkdir($lockDir, 0777, true);
        file_put_contents($lockDir . '/.lock', "user: nonexistent-user-xyz\ntime: " . time() . "\n");

        $results = (new LockedPages())->getLockedPagesV4($this->contentDir, []);

        $this->assertSame('nonexistent-user-xyz', $results[0]['user']);
    }

    public function testV4NoLockFilesReturnsEmptyArray(): void
    {
        $results = (new LockedPages())->getLockedPagesV4($this->contentDir, []);
        $this->assertSame([], $results);
    }

    public function testV4MultipleNestedLockFilesAreFound(): void
    {
        $lockTime = time();
        foreach (['1_about', '2_contact', '3_blog/1_post'] as $path) {
            $dir = $this->contentDir . '/' . $path;
            mkdir($dir, 0777, true);
            file_put_contents($dir . '/.lock', "user: kirby\ntime: $lockTime\n");
        }

        $results = (new LockedPages())->getLockedPagesV4($this->contentDir, []);
        $this->assertCount(3, $results);
    }

    // -------------------------------------------------------------------------
    // V5 _changes/ parsing
    // -------------------------------------------------------------------------

    public function testV5ChangesFileIsParsed(): void
    {
        $changesDir = $this->contentDir . '/1_about/_changes';
        mkdir($changesDir, 0777, true);
        file_put_contents($this->contentDir . '/1_about/about.txt', "Title: About\n");
        file_put_contents($changesDir . '/article.txt', "Lock: kirby\n");

        $results = (new LockedPages())->getLockedPagesV5($this->contentDir, []);

        $this->assertCount(1, $results);
        $this->assertNotEmpty($results[0]['user']);
        $this->assertNotEmpty($results[0]['time']);
        $this->assertSame('listed', $results[0]['page_status']);
        $this->assertNotEmpty($results[0]['panel_url']);
    }

    /**
     * Regression: before fix, line 103 used $fileDir instead of $fileId,
     * so the _changes/ segment was never stripped from $fileId.
     */
    public function testV5IdDoesNotContainChangesSuffix(): void
    {
        $changesDir = $this->contentDir . '/1_about/_changes';
        mkdir($changesDir, 0777, true);
        file_put_contents($changesDir . '/article.txt', "Lock: kirby\n");

        $results = (new LockedPages())->getLockedPagesV5($this->contentDir, []);

        $this->assertCount(1, $results);
        $this->assertStringNotContainsString('_changes', $results[0]['id']);
    }

    public function testV5IdHasNoOrderNumber(): void
    {
        $changesDir = $this->contentDir . '/1_about/_changes';
        mkdir($changesDir, 0777, true);
        file_put_contents($changesDir . '/article.txt', "Lock: kirby\n");

        $results = (new LockedPages())->getLockedPagesV5($this->contentDir, []);

        $this->assertStringNotContainsString('1_', $results[0]['id']);
        $this->assertSame('about', $results[0]['id']);
    }

    public function testV5MissingUserFallsBackToUserId(): void
    {
        $changesDir = $this->contentDir . '/1_about/_changes';
        mkdir($changesDir, 0777, true);
        file_put_contents($changesDir . '/article.txt', "Lock: ghost-user-id\n");

        $results = (new LockedPages())->getLockedPagesV5($this->contentDir, []);
        $this->assertSame('ghost-user-id', $results[0]['user']);
    }

    public function testV5NoChangesFilesReturnsEmptyArray(): void
    {
        $results = (new LockedPages())->getLockedPagesV5($this->contentDir, []);
        $this->assertSame([], $results);
    }
}
