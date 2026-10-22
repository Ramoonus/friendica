<?php

// Copyright (C) 2010-2026, the Friendica project
// SPDX-FileCopyrightText: 2010-2026 the Friendica project
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Friendica\Test\Unit\Network;

use Friendica\Network\ActivityPubObjectCache;
use Friendica\Model\Item;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ActivityPubObjectCacheTest extends TestCase
{
	public function testOnlyPublicNonDeletedObjectRepresentationsAreEligible(): void
	{
		$item = ['private' => Item::PUBLIC, 'gravity' => Item::GRAVITY_PARENT, 'deleted' => false];
		self::assertTrue(ActivityPubObjectCache::isPublicObject($item, []));
		self::assertTrue(ActivityPubObjectCache::isPublicObject(['private' => '0', 'gravity' => '6', 'deleted' => '0'], []));
		foreach ([Item::PRIVATE, Item::UNLISTED] as $visibility) {
			self::assertFalse(ActivityPubObjectCache::isPublicObject(array_replace($item, ['private' => $visibility]), []));
		}
		self::assertFalse(ActivityPubObjectCache::isPublicObject(array_replace($item, ['deleted' => true]), []));
		self::assertFalse(ActivityPubObjectCache::isPublicObject(array_replace($item, ['gravity' => Item::GRAVITY_ACTIVITY]), []));
		self::assertFalse(ActivityPubObjectCache::isPublicObject([], []));
		foreach (['replies', 'Create', 'Announce', 'Delete'] as $activity) {
			self::assertFalse(ActivityPubObjectCache::isPublicObject($item, ['activity' => $activity]));
		}
	}

	public function testAnonymousPublicObjectCanBeCachedBriefly(): void
	{
		self::assertSame('public, max-age=0, s-maxage=30, must-revalidate', ActivityPubObjectCache::cacheControl(30, true, ['REQUEST_METHOD' => 'GET']));
		self::assertSame('public, max-age=0, s-maxage=60, must-revalidate', ActivityPubObjectCache::cacheControl(3600, true, ['REQUEST_METHOD' => 'HEAD']));
	}

	#[DataProvider('uncacheableRequests')]
	public function testSensitiveAndDisabledResponsesCannotBeCached(int $ttl, bool $public, array $server, array $headers = []): void
	{
		self::assertSame('private, no-store', ActivityPubObjectCache::cacheControl($ttl, $public, $server, $headers));
	}

	public static function uncacheableRequests(): iterable
	{
		yield 'disabled' => [0, true, ['REQUEST_METHOD' => 'GET']];
		yield 'negative lifetime' => [-1, true, ['REQUEST_METHOD' => 'GET']];
		yield 'private, unlisted, deleted or activity variant' => [30, false, ['REQUEST_METHOD' => 'GET']];
		yield 'write' => [30, true, ['REQUEST_METHOD' => 'POST']];
		yield 'unknown method' => [30, true, []];
		yield 'query string' => [30, true, ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/objects/example?zrl=account']];
		yield 'session created' => [30, true, ['REQUEST_METHOD' => 'GET'], ['Set-Cookie: PHPSESSID=example']];
		yield 'mixed case cookie' => [30, true, ['REQUEST_METHOD' => 'GET'], ['set-cookie: PHPSESSID=example']];
		foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER', 'PHP_AUTH_PW', 'REMOTE_USER', 'HTTP_COOKIE', 'HTTP_SIGNATURE', 'HTTP_SIGNATURE_INPUT'] as $key) {
			yield $key => [30, true, ['REQUEST_METHOD' => 'GET', $key => 'example']];
			yield $key . ' zero' => [30, true, ['REQUEST_METHOD' => 'GET', $key => '0']];
		}
	}
}
