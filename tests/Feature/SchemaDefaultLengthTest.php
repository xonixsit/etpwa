<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchemaDefaultLengthTest extends TestCase
{
    public function test_string_columns_default_to_191_chars_for_the_hosts_767_byte_index_limit()
    {
        // R59: the Plesk host's MySQL caps InnoDB index keys at 767 bytes; utf8mb4 makes a
        // 255-char column 1020 bytes and migrate dies on users_email_unique (SQLSTATE 1071).
        // 191 * 4 = 764. Local MariaDB 10.4 has a 3072-byte limit and never shows this, so
        // the boot-time default is the only place the constraint can be witnessed.
        $connection = DB::connection();
        $connection->useDefaultSchemaGrammar();
        $blueprint = new Blueprint($connection, 'probe');
        $blueprint->string('email');

        $this->assertSame(191, $blueprint->getColumns()[0]->length);
    }
}
