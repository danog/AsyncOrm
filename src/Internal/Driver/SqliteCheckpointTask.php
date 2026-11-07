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

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use PDO;

/**
 * Checkpoints the WAL of an SQLite database, in a worker: it syncs the database file.
 *
 * @internal
 *
 * @implements Task<list<int>, never, never>
 */
final class SqliteCheckpointTask implements Task
{
    /** @var array<string, PDO> */
    private static array $connections = [];

    public function __construct(private readonly string $path)
    {
    }

    /**
     * @return list<int> Whether the checkpoint couldn't complete, the frames in the WAL, the frames checkpointed.
     */
    #[\Override]
    public function run(Channel $channel, Cancellation $cancellation): array
    {
        $db = self::$connections[$this->path] ??= new PDO("sqlite:{$this->path}", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        /** @var list<int> */
        return $db->query('PRAGMA wal_checkpoint(PASSIVE)')->fetch(PDO::FETCH_NUM);
    }
}
