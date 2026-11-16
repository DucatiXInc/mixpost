<?php

namespace Inovector\Mixpost\Support;

use Closure;
use RuntimeException;
use Throwable;

/** A bounded, all-or-nothing ID snapshot. Never follows provider paging URLs. */
final class InstagramCommentSnapshot
{
    public function __construct(private Closure $get, private int $maxRequests = 100) {}

    public function fetch(string $postId): array
    {
        $this->deadline = microtime(true) + 120;
        $requests = 0;
        $ids = [];
        try {
            $this->id($postId);
            $roots = $this->edge($postId, 'comments', $requests);
            foreach ($roots as $root) {
                $id = $this->id($root['id'] ?? null);
                $this->add($ids, $id);
                // Fetch the complete reply edge explicitly; embedded replies can be truncated.
                foreach ($this->edge($id, 'replies', $requests) as $reply) {
                    $this->add($ids, $this->id($reply['id'] ?? null));
                }
            }
            return ['complete' => true, 'ids' => array_keys($ids), 'requests' => $requests, 'reason' => null];
        } catch (Throwable) {
            // Exceptions can contain credentials or URLs; never persist their message/context.
            return ['complete' => false, 'ids' => [], 'requests' => $requests, 'reason' => 'incomplete'];
        }
    }

    private float $deadline;

    private function edge(string $id, string $edge, int &$requests): array
    {
        $cursor = null;
        $seen = [];
        $items = [];
        do {
            if ($requests >= $this->maxRequests || microtime(true) >= $this->deadline) {
                throw new RuntimeException('request cap');
            }
            $requests++;
            $params = ['fields' => 'id', 'limit' => 50];
            if ($cursor !== null) {
                $params['after'] = $cursor;
            }
            $response = ($this->get)($id, $edge, $params);
            if (! $response->successful()) {
                throw new RuntimeException('http failure');
            }
            $json = $response->json();
            if (! is_array($json) || isset($json['error']) || ! isset($json['data']) || ! is_array($json['data']) || ! array_is_list($json['data'])) {
                throw new RuntimeException('malformed response');
            }
            foreach ($json['data'] as $item) {
                if (! is_array($item)) {
                    throw new RuntimeException('malformed item');
                }
                $this->id($item['id'] ?? null);
                $items[] = $item;
            }
            if (count($items) > 5000) {
                throw new RuntimeException('item cap');
            }
            $paging = $json['paging'] ?? [];
            if (! is_array($paging)) {
                throw new RuntimeException('malformed paging');
            }
            // A cursor alone is normal on the terminal page. A next URL without a cursor is unsafe.
            $next = ! empty($paging['next']);
            $cursor = $next ? ($paging['cursors']['after'] ?? null) : null;
            if ($next && (! is_string($cursor) || $cursor === '' || strlen($cursor) > 2048 || isset($seen[$cursor]))) {
                throw new RuntimeException('invalid or repeated cursor');
            }
            if ($cursor !== null) {
                $seen[$cursor] = true;
            }
        } while ($next);

        return $items;
    }

    private function id(mixed $id): string
    {
        if ((! is_string($id) && ! is_int($id)) || ! preg_match('/^[0-9]{1,32}$/D', (string) $id)) {
            throw new RuntimeException('invalid ID');
        }
        return (string) $id;
    }

    private function add(array &$ids, string $id): void
    {
        if (isset($ids[$id])) {
            throw new RuntimeException('unstable duplicate');
        }
        $ids[$id] = true;
    }
}
