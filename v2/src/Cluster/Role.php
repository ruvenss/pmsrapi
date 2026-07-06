<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Cluster;

/**
 * A microservice's role in the cluster.
 *
 * - Worker:   a normal service (what v2 has been so far). Owns functions, serves
 *             traffic, and in PRODUCTION runs standalone from a baked-in map.
 * - HiveMind: DEVELOPMENT-only coordinator. Aggregates every worker's function
 *             manifest, detects duplicate ownership, and renders the map. Never
 *             required at runtime by workers.
 */
enum Role: string
{
    case Worker = 'worker';
    case HiveMind = 'hive_mind';

    public static function fromConfig(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Worker) : self::Worker;
    }
}
