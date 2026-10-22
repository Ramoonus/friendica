<?php

// Copyright (C) 2010-2026, the Friendica project
// SPDX-FileCopyrightText: 2010-2026 the Friendica project
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Friendica\Network;

use Friendica\Model\Item;

/**
 * Shared HTTP caching is deliberately limited to anonymous, public object reads.
 * Authorization and visibility must still be checked by the Objects module.
 */
final class ActivityPubObjectCache
{
	public const VARY = 'Accept, Authorization, Cookie, Signature, Signature-Input';

	/**
	 * @param array<string, mixed> $item
	 * @param array<string, mixed> $parameters
	 */
	public static function isPublicObject(array $item, array $parameters): bool
	{
		return isset($item['private'], $item['gravity'], $item['deleted'])
			&& in_array($item['private'], [Item::PUBLIC, (string) Item::PUBLIC], true)
			&& in_array($item['gravity'], [Item::GRAVITY_PARENT, Item::GRAVITY_COMMENT, (string) Item::GRAVITY_PARENT, (string) Item::GRAVITY_COMMENT], true)
			&& !$item['deleted']
			&& !isset($parameters['activity']);
	}

	/**
	 * @param array<string, mixed> $server
	 * @param string[] $responseHeaders Headers already queued by PHP, including session cookies.
	 */
	public static function cacheControl(int $ttl, bool $publicObject, array $server, array $responseHeaders = []): string
	{
		if ($ttl <= 0 || !$publicObject || !in_array($server['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
			return 'private, no-store';
		}

		foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER', 'PHP_AUTH_PW', 'REMOTE_USER', 'HTTP_COOKIE', 'HTTP_SIGNATURE', 'HTTP_SIGNATURE_INPUT'] as $key) {
			if (isset($server[$key]) && (string) $server[$key] !== '') {
				return 'private, no-store';
			}
		}

		// REQUEST_URI retains the original URL, unlike QUERY_STRING after rewrites.
		if (str_contains($server['REQUEST_URI'] ?? '', '?')) {
			return 'private, no-store';
		}

		foreach ($responseHeaders as $header) {
			if (stripos($header, 'Set-Cookie:') === 0) {
				return 'private, no-store';
			}
		}

		// Browsers revalidate; only shared caches may reuse the response briefly.
		return 'public, max-age=0, s-maxage=' . min($ttl, 60) . ', must-revalidate';
	}
}
