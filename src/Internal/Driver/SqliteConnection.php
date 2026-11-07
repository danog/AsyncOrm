<?php declare(strict_types=1);

/**
 * Copyright 2024 Daniil Gentili.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2023 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/license/apache-2-0 Apache 2.0
 * @link https://github.com/danog/AsyncOrm AsyncOrm documentation
 */

namespace danog\AsyncOrm\Settings;

namespace danog\AsyncOrm\Internal\Driver;

use PDO;
use PDOException;
use Revolt\EventLoop;

use function Amp\delay;
use function Amp\Parallel\Worker\workerPool;

/**
 * The connection of this process to an SQLite database file, shared by all its arrays.
 *
 * Queries run directly from the event loop's thread, where they take a few microseconds:
 * less than the client side of a query to a database server. What would take longer
 * never runs there:
 * - waiting for another process' lock: SQLite doesn't wait itself (busy timeout 0),
 *   the query is retried after an asynchronous delay;
 * - fsync: in WAL mode with synchronous=NORMAL commits don't sync, only checkpoints do,
 *   and those run in an amphp/parallel worker (if one can be started);
 * - many queries in a row: after 1ms of queries without the loop getting a turn, the
 *   next one waits for the loop's next tick.
 *
 * The writes made during one tick of the event loop are one transaction, committed at the
 * end of the tick (or at shutdown): committing each write separately costs 8 times as
 * much, and appends a page to the WAL each time.
 *
 * @internal
 */
final class SqliteConnection
{
    private const SQLITE_BUSY = 5;
    private const SQLITE_LOCKED = 6;
    private const YIELD_AFTER_NS = 1_000_000;
    private const CHECKPOINT_PAGES = 1000;
    private const CHECKPOINT_INTERVAL = 0.2;
    private const CHECKPOINT_RETRY = 60.0;

    /** @var array<string, self> */
    private static array $connections = [];

    public static function get(string $path): self
    {
        return self::$connections[$path] ??= new self($path);
    }

    public readonly PDO $db;
    private bool $inTransaction = false;
    private ?int $busySince = null;

    /** Whether workers may checkpoint the WAL (SqliteSettings::$checkpointInWorkers). */
    private bool $checkpointInWorkers = true;
    /** Whether workers checkpoint the WAL (SQLite doesn't, when committing). */
    private bool $checkpointInWorker = false;
    private bool $checkpointScheduled = false;
    /** Whether something was committed since the last checkpoint began. */
    private bool $dirty = false;
    private float $checkpointRetryAt = 0.0;

    private function __construct(private readonly string $path)
    {
        // PDO, not SQLite3: SQLite3Stmt::execute() runs a query, resets it and runs it again
        // when the first row is fetched, which doubles the cost of a lookup.
        $db = new PDO("sqlite:$path", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 0,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $this->db = $db;
        $this->retry(static fn () => $db->exec('PRAGMA journal_mode=WAL'));
        $db->exec('PRAGMA synchronous=NORMAL');
        // A burst of writes may grow the WAL before it's checkpointed: shrink it afterwards.
        $db->exec('PRAGMA journal_size_limit='.(64 << 20));

        \register_shutdown_function($this->commit(...));
    }

    /**
     * Run a write: in the transaction of this tick, opened if needed.
     *
     * @param \Closure(): mixed $f
     */
    public function write(\Closure $f): void
    {
        $this->yieldIfBusy();
        if (!$this->inTransaction) {
            $this->retry(function (): void {
                // While retry() waited, another fiber may have begun it.
                if ($this->inTransaction) {
                    return;
                }
                $this->db->exec('BEGIN IMMEDIATE');
                $this->inTransaction = true;
                EventLoop::defer($this->commit(...));
            });
        }
        $f();
    }

    /**
     * Run a read.
     *
     * @template T
     * @param \Closure(): T $f
     * @return T
     */
    public function read(\Closure $f): mixed
    {
        $this->yieldIfBusy();
        return $this->retry($f);
    }

    /**
     * Run a schema change in a transaction of its own.
     *
     * @param \Closure(): void $f
     */
    public function schema(\Closure $f): void
    {
        $this->retry(function (): void {
            // While retry() waited, another fiber may have begun a transaction.
            $this->commit();
            $this->db->exec('BEGIN IMMEDIATE');
        });
        try {
            $f();
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
        $this->committed();
    }

    /**
     * Commit the transaction of this tick, if any.
     */
    public function commit(): void
    {
        if ($this->inTransaction) {
            $this->inTransaction = false;
            try {
                $this->db->exec('COMMIT');
            } catch (\Throwable $e) {
                // Don't stay in a transaction nobody will commit.
                if ($this->db->inTransaction()) {
                    $this->db->exec('ROLLBACK');
                }
                throw $e;
            }
            $this->committed();
        }
    }

    /**
     * Identifies the database file, whatever path reaches it.
     */
    public function getIdentity(): ?string
    {
        try {
            $stat = \stat($this->path);
        } catch (\Throwable) {
            return null;
        }
        return $stat === false ? null : $stat['dev'].':'.$stat['ino'];
    }

    private function yieldIfBusy(): void
    {
        $now = (int) \hrtime(true);
        if ($this->busySince === null) {
            $this->busySince = $now;
            EventLoop::defer(function (): void {
                $this->busySince = null;
            });
        } elseif ($now - $this->busySince > self::YIELD_AFTER_NS) {
            delay(0);
        }
    }

    /**
     * Run a query, waiting asynchronously while another connection holds a lock.
     *
     * @template T
     * @param \Closure(): T $f
     * @return T
     */
    private function retry(\Closure $f): mixed
    {
        $wait = 0.001;
        while (true) {
            try {
                return $f();
            } catch (PDOException $e) {
                $code = ((int) ($e->errorInfo[1] ?? 0)) & 0xff;
                if ($code !== self::SQLITE_BUSY && $code !== self::SQLITE_LOCKED) {
                    throw $e;
                }
            }
            delay($wait);
            $wait = \min($wait * 2.0, 0.1);
        }
    }

    /**
     * Whether to checkpoint the WAL in workers: the settings of the last array opened on
     * this database decide.
     */
    public function setCheckpointInWorkers(bool $checkpointInWorkers): void
    {
        $this->checkpointInWorkers = $checkpointInWorkers;
        if (!$checkpointInWorkers && $this->checkpointInWorker) {
            $this->checkpointInWorker = false;
            $this->db->exec('PRAGMA wal_autocheckpoint='.self::CHECKPOINT_PAGES);
        }
    }

    /**
     * Schedule a checkpoint of the WAL in a worker: a checkpoint syncs the database file,
     * which would block the event loop. At most one runs at a time, CHECKPOINT_INTERVAL
     * after a commit.
     */
    private function committed(): void
    {
        $this->dirty = true;
        if (!$this->checkpointInWorkers
            || $this->checkpointScheduled
            || \microtime(true) < $this->checkpointRetryAt
        ) {
            return;
        }
        $this->checkpointScheduled = true;
        // Unreferenced: it must not keep the loop running (closing the last connection
        // to the database checkpoints it anyway).
        EventLoop::unreference(EventLoop::delay(self::CHECKPOINT_INTERVAL, $this->checkpoint(...)));
    }

    private function checkpoint(): void
    {
        $this->dirty = false;
        if (!$this->checkpointInWorkers) {
            // Disabled since it was scheduled.
            $this->checkpointScheduled = false;
            return;
        }
        try {
            workerPool()->submit(new SqliteCheckpointTask($this->path))->await();
            // Disabled while it ran: SQLite checkpoints by itself.
            /** @psalm-suppress TypeDoesNotContainType Set by setCheckpointInWorkers() meanwhile */
            if (!$this->checkpointInWorkers) {
                return;
            }
            if (!$this->checkpointInWorker) {
                $this->checkpointInWorker = true;
                $this->db->exec('PRAGMA wal_autocheckpoint=0');
            }
        } catch (\Throwable) {
            // No worker (e.g. processes can't be started here): SQLite checkpoints by
            // itself again, when committing. Workers are tried again later.
            $this->checkpointInWorker = false;
            $this->checkpointRetryAt = \microtime(true) + self::CHECKPOINT_RETRY;
            $this->db->exec('PRAGMA wal_autocheckpoint='.self::CHECKPOINT_PAGES);
        } finally {
            $this->checkpointScheduled = false;
        }
        /** @psalm-suppress TypeDoesNotContainType Set by commits made while the checkpoint ran */
        if ($this->dirty) {
            $this->committed();
        }
    }
}
