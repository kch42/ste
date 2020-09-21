<?php

namespace tests\functional\test_recursive;

use r7r\ste\STECore;
use tests\functional\BaseTest;

class Test extends BaseTest
{
    protected function getDirectory(): string
    {
        return __DIR__;
    }

    protected function setUpSte(STECore $ste): void
    {
        $ste->mute_runtime_errors = false;
    }
}
