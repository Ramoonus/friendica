<?php

// Copyright (C) 2010-2026, the Friendica project
// SPDX-FileCopyrightText: 2010-2026 the Friendica project
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Synthetic origin for the isolated proxy test; it never loads a Friendica database.
require __DIR__ . '/../../src/Network/ActivityPubObjectCache.php';

use Friendica\Network\ActivityPubObjectCache;

$state = getenv('FRIENDICA_CACHE_TEST_STATE');
$count = (int) file_get_contents($state . '/count') + 1;
file_put_contents($state . '/count', (string) $count);
$mode         = trim(file_get_contents($state . '/mode'));
$path         = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$publicObject = in_array($mode, ['public', 'cookie'], true) && preg_match('#^/objects/[^/]+$#', $path) === 1;

if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html')) {
	$publicObject = false;
	http_response_code(302);
	header('Location: /display/example');
} elseif ($mode === 'private' || $mode === 'missing') {
	http_response_code(404);
} elseif ($mode === 'deleted') {
	http_response_code(410);
} elseif ($mode === 'error') {
	http_response_code(500);
} elseif ($mode === 'cookie') {
	header('Set-Cookie: example=session; HttpOnly');
}

$ttl = (int) file_get_contents($state . '/ttl');
header('Cache-Control: ' . ActivityPubObjectCache::cacheControl($ttl, $publicObject, $_SERVER, headers_list()));
header('Vary: ' . ActivityPubObjectCache::VARY);
header('Content-Type: application/activity+json');
header('ETag: "example-' . $mode . '"');
// Deliberate generation cost makes avoided backend work visible.
usleep(100000);
echo json_encode(['mode' => $mode, 'generation' => $count]);
