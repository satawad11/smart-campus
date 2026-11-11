<?php

namespace Tests\Unit;

use Aws\MockHandler;
use Aws\Result;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Uploads must not carry an object ACL: the least-privilege object-store
 * identity may not set one, and a "public-read" ACL would expose files.
 */
class ObjectStorageAclTest extends TestCase
{
    private function disk(MockHandler $mock): AwsS3V3Adapter
    {
        return Storage::build([
            'driver' => 's3',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'us-east-1',
            'bucket' => 'face-samples',
            'endpoint' => 'http://objectstore.test:8333',
            'use_path_style_endpoint' => true,
            'handler' => $mock,
            'throw' => true,
        ]);
    }

    public function test_put_sends_no_acl_even_when_public_visibility_is_requested(): void
    {
        $mock = new MockHandler;
        $mock->append(new Result([]), new Result([]));
        $disk = $this->disk($mock);

        $disk->put('a.txt', 'x');
        $this->assertSame('PutObject', $mock->getLastCommand()->getName());
        $this->assertFalse($mock->getLastCommand()->hasParam('ACL'));

        $disk->put('b.txt', 'x', ['visibility' => 'public']);
        $this->assertFalse($mock->getLastCommand()->hasParam('ACL'));
    }

    public function test_copy_sends_no_acl(): void
    {
        $mock = new MockHandler;
        // The SDK copier reads the source size first (HeadObject).
        $mock->append(new Result(['ContentLength' => 1]), new Result([]));
        $disk = $this->disk($mock);

        $disk->getDriver()->copy('a.txt', 'c.txt', ['visibility' => 'private']);

        $this->assertSame('CopyObject', $mock->getLastCommand()->getName());
        $this->assertFalse($mock->getLastCommand()->hasParam('ACL'));
    }
}
